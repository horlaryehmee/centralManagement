<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GithubSource;
use App\Models\Release;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Pulls releases straight from GitHub: each GitHub release's zip asset (built by
 * the repository's release workflow) becomes a release here, with the GitHub
 * release notes. Can run automatically every hour (auto import / auto publish).
 */
class GithubImporter
{
    public function __construct(protected ReleaseService $releases) {}

    /**
     * Recent GitHub releases of a source, newest first, each with whether it was imported.
     *
     * @return array<int, array>
     */
    public function list(GithubSource $source): array
    {
        $response = $this->client($source)->get("https://api.github.com/repos/{$source->repository}/releases", ['per_page' => 30]);
        $this->ensureOk($response, $source);

        $imported = Release::query()->where('github_source_id', $source->id)->pluck('id', 'github_release_id');

        return collect($response->json())
            ->reject(fn ($r) => ! empty($r['draft']) || (! $source->include_prereleases && ! empty($r['prerelease'])))
            ->map(function (array $r) use ($source, $imported) {
                $asset = $this->pickAsset($source, $r['assets'] ?? []);

                return [
                    'id' => $r['id'],
                    'tag' => $r['tag_name'],
                    'version' => $this->versionFromTag($r['tag_name']),
                    'name' => $r['name'] ?: $r['tag_name'],
                    'notes' => $r['body'] ?? null,
                    'published_at' => $r['published_at'] ?? null,
                    'prerelease' => (bool) ($r['prerelease'] ?? false),
                    'url' => $r['html_url'] ?? null,
                    'asset' => $asset ? ['name' => $asset['name'], 'size' => $asset['size']] : null,
                    'release_id' => $imported[$r['id']] ?? null,
                    'problem' => $asset ? null : 'No package (.zip) attached yet. If the release was just created, the workflow may still be building it.',
                ];
            })
            ->values()->all();
    }

    /** Import one GitHub release (by its GitHub release ID). */
    public function import(GithubSource $source, int $githubReleaseId, ?User $user = null): Release
    {
        if ($existing = Release::query()->where('github_source_id', $source->id)->where('github_release_id', $githubReleaseId)->first()) {
            return $existing;
        }

        $response = $this->client($source)->get("https://api.github.com/repos/{$source->repository}/releases/{$githubReleaseId}");
        $this->ensureOk($response, $source);
        $gh = $response->json();
        $asset = $this->pickAsset($source, $gh['assets'] ?? []);
        if (! $asset) {
            throw ValidationException::withMessages(['github' => "{$gh['tag_name']} has no .zip package attached yet."]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ghrel');
        try {
            // replaceHeaders, not withHeaders: withHeaders would *add* octet-stream to the JSON Accept
            // header, and GitHub then answers with the asset's JSON description instead of the file.
            $download = $this->client($source)
                ->replaceHeaders(['Accept' => 'application/octet-stream'])
                ->timeout(900)
                ->withOptions(['sink' => $tmp])
                ->get($asset['url']);
            clearstatcache(true, $tmp);
            if (! $download->successful() || filesize($tmp) === 0) {
                throw ValidationException::withMessages(['github' => 'Downloading the package from GitHub failed ('.$download->status().'). Try again.']);
            }
            if (str_contains(strtolower((string) $download->header('Content-Type')), 'json') || ($asset['size'] && filesize($tmp) !== (int) $asset['size'])) {
                throw ValidationException::withMessages(['github' => 'GitHub didn\'t send the package file (got '.number_format(filesize($tmp)).' bytes, expected '.number_format((int) $asset['size']).'). Try again.']);
            }
            // GitHub publishes a SHA-256 digest for release assets: check the file arrived intact.
            if (! empty($asset['digest']) && str_starts_with($asset['digest'], 'sha256:') && ! hash_equals(substr($asset['digest'], 7), hash_file('sha256', $tmp))) {
                throw ValidationException::withMessages(['github' => 'The downloaded package ('.number_format(filesize($tmp)).' bytes) does not match GitHub\'s SHA-256 checksum. Try again.']);
            }

            $release = $this->releases->createFromPackage($tmp, [
                'type' => $source->type,
                'client_id' => $source->client_id,
                'name' => $gh['name'] ?: $gh['tag_name'],
                'notes' => $gh['body'] ?? null,
            ], $user);

            $tagVersion = $this->versionFromTag($gh['tag_name']);
            if ($tagVersion && $tagVersion !== $release->version) {
                $this->releases->deleteDraft($release);
                throw ValidationException::withMessages(['github' => "The tag {$gh['tag_name']} says version {$tagVersion}, but the package says {$release->version}. Fix the VERSION/module.json and tag again."]);
            }

            $release->update(['github_source_id' => $source->id, 'github_release_id' => $gh['id'], 'github_url' => $gh['html_url'] ?? null]);
            // A successful import replaces any error left by an earlier attempt.
            $source->update(['last_error' => null]);
            Event::log('release.github_import', "Imported {$release->label()} from GitHub ({$source->repository} {$gh['tag_name']}).", ['user' => $user, 'client' => $source->client]);

            if ($source->auto_publish) {
                $this->releases->publish($release, $user);
            }

            return $release->fresh();
        } finally {
            @unlink($tmp);
        }
    }

    /** Import every release not imported yet (oldest first). Used by the hourly schedule and "Import all". */
    public function sync(GithubSource $source, ?User $user = null): array
    {
        $done = [];
        $failed = [];
        try {
            foreach (array_reverse($this->list($source)) as $r) {
                if ($r['release_id'] || ! $r['asset']) {
                    continue;
                }
                try {
                    $done[] = $this->import($source, $r['id'], $user)->version;
                } catch (ValidationException $e) {
                    $failed[] = $r['tag'].': '.collect($e->errors())->flatten()->first();
                }
            }
            $source->update(['last_checked_at' => now(), 'last_error' => $failed ? implode(' ', $failed) : null]);
        } catch (Throwable $e) {
            $source->update(['last_checked_at' => now(), 'last_error' => $e->getMessage()]);
            throw $e;
        }

        return ['imported' => $done, 'failed' => $failed];
    }

    protected function client(GithubSource $source): PendingRequest
    {
        $request = Http::withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('central-update-management')->timeout(30)->connectTimeout(10);

        return $source->token ? $request->withToken($source->token) : $request;
    }

    protected function ensureOk(Response $response, GithubSource $source): void
    {
        if ($response->successful()) {
            return;
        }
        $message = match (true) {
            $response->status() === 401 => 'GitHub rejected the access token (expired or wrong). Save a new one for this repository.',
            $response->status() === 404 => "Repository {$source->repository} not found. Check the name, and add a token if it is private.",
            $response->status() === 403 && $response->header('X-RateLimit-Remaining') === '0' => 'GitHub\'s hourly request limit was reached. Add an access token, or try again later.',
            default => 'GitHub returned error '.$response->status().'.',
        };
        throw new RuntimeException($message);
    }

    protected function pickAsset(GithubSource $source, array $assets): ?array
    {
        foreach ($assets as $a) {
            if (str_ends_with(strtolower($a['name']), '.zip') && fnmatch($source->asset_pattern ?: '*.zip', $a['name'])) {
                return ['name' => $a['name'], 'size' => $a['size'] ?? null, 'url' => $a['url'], 'digest' => $a['digest'] ?? null];
            }
        }

        return null;
    }

    /** "v1.2.0" / "gift-wrap-v1.2.0" / "1.2.0" → "1.2.0" (null when the tag has no version). */
    protected function versionFromTag(string $tag): ?string
    {
        return preg_match('/(\d+\.\d+\.\d+)(?!.*\d+\.\d+\.\d+)/', $tag, $m) ? $m[1] : null;
    }
}
