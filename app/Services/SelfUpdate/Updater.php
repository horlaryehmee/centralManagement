<?php

namespace App\Services\SelfUpdate;

use App\Models\SystemSetting;
use FilesystemIterator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * One-click updates of the central app itself from GitHub releases, made for
 * shared hosting (cPanel): no terminal, Composer, Node.js or Git needed on the
 * server. (Same engine as the ecommerce app's updater, core only.)
 *
 * The work is split into short steps, each its own request, so no single
 * request runs into the host's time limit:
 *
 *   download → backup → extract → install → finish
 *
 * "install" puts the site in maintenance mode and swaps the code folders in
 * (renames, so it takes a second); if anything fails the old files are moved
 * straight back. "finish" then runs on the NEW code in a fresh request:
 * database migrations, cache clearing, and bringing the site back online.
 *
 * Never touched: .env, storage/ (signing key, packages, backups, logs, sessions), custom public files.
 */
class Updater
{
    public const STEPS = ['download', 'backup', 'extract', 'install', 'finish'];

    protected string $basePath;

    protected string $publicPath;

    protected string $workPath;

    public function __construct(?string $basePath = null, ?string $publicPath = null, ?string $workPath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/\\');
        $this->publicPath = rtrim($publicPath ?? public_path(), '/\\');
        $this->workPath = rtrim($workPath ?? storage_path('app/updates'), '/\\');
    }

    /* ------------------------------------------------------------------ */
    /* Versions & GitHub                                                   */
    /* ------------------------------------------------------------------ */

    public function currentVersion(): string
    {
        $file = $this->basePath.'/VERSION';

        return is_file($file) ? self::normalize((string) file_get_contents($file)) : '0.0.0';
    }

    public static function normalize(?string $version): string
    {
        return ltrim(trim((string) $version), 'vV');
    }

    public static function isNewer(?string $latest, ?string $current): bool
    {
        return filled($latest) && version_compare(self::normalize($latest), self::normalize($current), '>');
    }

    /** ['repository' => 'owner/repo'|null, 'token' => string|null]; admin settings win over .env. */
    public function source(): array
    {
        $s = SystemSetting::current();

        return [
            'repository' => self::cleanRepository($s->update_repository ?: config('selfupdate.repository')),
            'token' => $s->update_token ?: config('selfupdate.token'),
        ];
    }

    /** Accepts "owner/repo" or a github.com URL. */
    public static function cleanRepository(?string $value): ?string
    {
        $value = trim((string) $value);
        $value = preg_replace('~^(https?://)?(www\.)?github\.com/~i', '', $value);
        $value = preg_replace('~(\.git)?/*$~', '', $value);

        return preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $value) ? $value : null;
    }

    protected function github(): PendingRequest
    {
        $request = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'central-management-updater',
        ])->timeout(20)->connectTimeout(10);

        $token = $this->source()['token'];

        return $token ? $request->withToken($token) : $request;
    }

    /** Cached result of the last check, without contacting GitHub (for the menu badge). */
    public function cachedStatus(): ?array
    {
        $status = Cache::get('selfupdate.latest');

        return is_array($status) ? [...$status, 'current' => $this->currentVersion(), 'available' => self::isNewer($status['latest'] ?? null, $this->currentVersion())] : null;
    }

    /** The latest published release and whether it is newer than this installation. */
    public function check(bool $fresh = false): array
    {
        if (! $fresh && ($cached = $this->cachedStatus())) {
            return $cached;
        }

        $repo = $this->source()['repository'];
        if (! $repo) {
            return ['current' => $this->currentVersion(), 'available' => false, 'error' => 'No update source is set up yet. Add the GitHub repository under "Update source".'];
        }

        try {
            $response = $this->github()->get("https://api.github.com/repos/{$repo}/releases/latest");
        } catch (Throwable $e) {
            return ['current' => $this->currentVersion(), 'available' => false, 'error' => 'Could not reach GitHub. Please try again in a few minutes.'];
        }

        if (! $response->successful()) {
            return ['current' => $this->currentVersion(), 'available' => false, 'error' => $this->githubError($response)];
        }

        $release = $response->json();
        $tag = (string) ($release['tag_name'] ?? '');
        $assetName = str_replace('{tag}', $tag, config('selfupdate.asset'));
        $assets = collect($release['assets'] ?? []);
        $asset = $assets->firstWhere('name', $assetName) ?? $assets->first(fn ($a) => str_ends_with($a['name'], '.zip'));
        $checksumAsset = $asset ? $assets->firstWhere('name', $asset['name'].'.sha256') : null;

        $status = [
            'latest' => self::normalize($tag),
            'tag' => $tag,
            'name' => $release['name'] ?: $tag,
            'notes_html' => (string) Str::markdown((string) ($release['body'] ?? ''), ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'published_at' => $release['published_at'] ?? null,
            'url' => $release['html_url'] ?? null,
            'asset' => $asset ? [
                'id' => $asset['id'],
                'name' => $asset['name'],
                'size' => $asset['size'],
                'api_url' => $asset['url'],
                // GitHub adds "sha256:…" digests to release assets; older releases use a .sha256 file.
                'digest' => isset($asset['digest']) ? Str::after($asset['digest'], 'sha256:') : null,
                'checksum_url' => $checksumAsset['url'] ?? null,
            ] : null,
            'checked_at' => now()->toIso8601String(),
            'error' => $asset ? null : "Release {$tag} has no installable package ({$assetName}) attached yet. If it was just published, wait a few minutes for it to finish building.",
        ];

        Cache::put('selfupdate.latest', $status, config('selfupdate.check_ttl'));

        return [...$status, 'current' => $this->currentVersion(), 'available' => self::isNewer($status['latest'], $this->currentVersion())];
    }

    protected function githubError(Response $response): string
    {
        return match (true) {
            $response->status() === 401 => 'GitHub rejected the access token. It may have expired: create a new one and save it under "Update source".',
            $response->status() === 403 && $response->header('X-RateLimit-Remaining') === '0' => 'GitHub\'s hourly limit for update checks was reached. Try again later, or add an access token under "Update source".',
            $response->status() === 404 => 'No published release found. Check the repository name, and add an access token if the repository is private.',
            default => 'GitHub returned an error ('.$response->status().'). Please try again later.',
        };
    }

    /* ------------------------------------------------------------------ */
    /* Pre-flight checks                                                   */
    /* ------------------------------------------------------------------ */

    /** [['label' => …, 'ok' => bool, 'detail' => …], …] */
    public function preflight(?int $packageBytes = null): array
    {
        $free = @disk_free_space($this->basePath);
        $needed = max(300 * 1024 * 1024, ($packageBytes ?? 0) * 5);
        $unwritable = collect([$this->basePath, $this->publicPath, ...glob($this->basePath.'/{app,bootstrap,config,database,resources,routes,vendor}', GLOB_BRACE | GLOB_ONLYDIR) ?: []])
            ->reject(fn ($p) => is_writable($p))
            ->map(fn ($p) => Str::after($p, dirname($this->basePath).DIRECTORY_SEPARATOR) ?: $p)
            ->values();

        return [
            ['label' => 'PHP '.PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => 'PHP 8.2 or newer is needed. Change it in cPanel → MultiPHP Manager.'],
            ['label' => 'Zip support', 'ok' => class_exists(ZipArchive::class), 'detail' => 'Turn on the "zip" PHP extension in cPanel → Select PHP Version → Extensions.'],
            ['label' => 'Internet access (cURL)', 'ok' => function_exists('curl_init'), 'detail' => 'Turn on the "curl" PHP extension in cPanel → Select PHP Version → Extensions.'],
            ['label' => 'App files can be updated', 'ok' => $unwritable->isEmpty(), 'detail' => 'Not writable: '.$unwritable->implode(', ').'. Ask your host to make these folders writable by PHP.'],
            ['label' => 'Free disk space', 'ok' => $free === false || $free >= $needed, 'detail' => 'About '.round($needed / 1048576).' MB of free space is needed; '.($free === false ? '?' : round($free / 1048576)).' MB is free. Delete old files or ask your host for more space.'],
        ];
    }

    public function preflightPasses(?int $packageBytes = null): bool
    {
        return collect($this->preflight($packageBytes))->every('ok');
    }

    /* ------------------------------------------------------------------ */
    /* Steps                                                               */
    /* ------------------------------------------------------------------ */

    /** Step 1: download the release package and verify its checksum. */
    public function download(): array
    {
        $this->prepareLongStep();
        $status = $this->check(true);

        if (! empty($status['error'])) {
            throw new RuntimeException($status['error']);
        }
        if (! $status['available']) {
            throw new RuntimeException('Central Management is already up to date.');
        }
        if (! $this->preflightPasses($status['asset']['size'])) {
            throw new RuntimeException('The server is not ready for updates yet. See the checks on this page.');
        }

        $this->clearWork(keepBackups: true);
        $this->ensureDir($this->workPath);
        $zip = $this->workPath.'/package.zip';

        // replaceHeaders, not withHeaders: withHeaders would *add* octet-stream to the JSON Accept
        // header, and GitHub then answers with the asset's JSON description instead of the file.
        $response = $this->github()
            ->replaceHeaders(['Accept' => 'application/octet-stream'])
            ->timeout(600)
            ->withOptions(['sink' => $zip])
            ->get($status['asset']['api_url']);

        if (! $response->successful() || ! is_file($zip) || filesize($zip) === 0) {
            @unlink($zip);
            throw new RuntimeException('The download failed ('.$response->status().'). Please try again.');
        }

        $expected = $status['asset']['digest'] ?: $this->fetchChecksum($status['asset']['checksum_url']);
        if ($expected && ! hash_equals(strtolower($expected), hash_file('sha256', $zip))) {
            @unlink($zip);
            throw new RuntimeException('The downloaded file is damaged (checksum mismatch). Please try again.');
        }

        $this->writeState([
            'step' => 'download',
            'from' => $this->currentVersion(),
            'to' => $status['latest'],
            'tag' => $status['tag'],
            'zip' => $zip,
            'started_at' => now()->toIso8601String(),
        ]);

        return ['message' => 'Downloaded version '.$status['latest'].' ('.round(filesize($zip) / 1048576, 1).' MB).'];
    }

    /** Empty the work folder (keeps backups) so a new update can start. */
    public function resetWork(): void
    {
        $this->clearWork(keepBackups: true);
        $this->ensureDir($this->workPath);
    }

    public function workPath(): string
    {
        return $this->workPath;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** Step 2: back up the database (files are kept automatically during install). */
    public function backup(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('download');
        $id = now()->format('Y-m-d_His').'_v'.$state['from'];
        $dir = $this->workPath.'/backups/'.$id;
        $this->ensureDir($dir);

        $result = app(DatabaseBackup::class)->dump($dir.'/database.sql.gz');

        $this->writeState([...$state, 'step' => 'backup', 'backup' => $id]);

        return ['message' => "Backed up the database ({$result['tables']} tables, ".number_format($result['rows']).' rows).'];
    }

    /** Step 3: unpack the package into a staging folder and check it is complete. */
    public function extract(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('backup');
        $staging = $this->workPath.'/staging';
        $this->delete($staging);
        $this->ensureDir($staging);

        $zip = new ZipArchive;
        if ($zip->open($state['zip']) !== true) {
            throw new RuntimeException('The update package could not be opened. Please start again.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name)) {
                $zip->close();
                throw new RuntimeException('The update package contains unsafe file paths and was rejected.');
            }
        }
        if (! $zip->extractTo($staging)) {
            $zip->close();
            throw new RuntimeException('The update package could not be unpacked. Check the free disk space.');
        }
        $zip->close();

        // Packages may wrap everything in one top-level folder.
        $root = $staging;
        $entries = array_values(array_diff(scandir($staging), ['.', '..']));
        if (count($entries) === 1 && is_dir($staging.'/'.$entries[0])) {
            $root = $staging.'/'.$entries[0];
        }

        foreach (['artisan', 'VERSION', 'vendor/autoload.php', 'bootstrap/app.php', 'public/build/manifest.json'] as $required) {
            if (! file_exists($root.'/'.$required)) {
                throw new RuntimeException("The update package is incomplete ({$required} is missing).");
            }
        }
        if (self::normalize(file_get_contents($root.'/VERSION')) !== $state['to']) {
            throw new RuntimeException('The update package does not match the expected version.');
        }

        @unlink($state['zip']);
        $this->writeState([...$state, 'step' => 'extract', 'root' => $root]);

        return ['message' => 'Unpacked and checked the new files.'];
    }

    /**
     * Step 4: maintenance mode on, swap the code in. Rolls back on failure.
     * The site stays in maintenance mode until "finish" (which runs on the new code).
     */
    public function install(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('extract');
        $backupDir = $this->workPath.'/backups/'.$state['backup'];

        Artisan::call('down', ['--retry' => 30, '--refresh' => 15]);

        try {
            $manifest = $this->swapIn($state['root'], $backupDir);
        } catch (Throwable $e) {
            $this->bringOnline();
            throw new RuntimeException('Installing failed, so the previous version was put back. Nothing was changed. ('.$e->getMessage().')', 0, $e);
        }

        file_put_contents($backupDir.'/manifest.json', json_encode([...$manifest, 'kind' => 'core', 'slug' => 'core', 'from' => $state['from'], 'to' => $state['to'], 'created_at' => now()->toIso8601String()], JSON_PRETTY_PRINT));
        $this->writeState([...$state, 'step' => 'install']);
        $this->resetOpcache();

        return ['message' => 'Installed the new files.'];
    }

    /** Step 5 (runs on the new code): migrate the database, clear caches, back online. */
    public function finish(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('install');
        $notes = [];
        $batch = null;

        try {
            $before = $this->maxMigrationBatch();
            Artisan::call('migrate', ['--force' => true]);
            $notes[] = trim(Artisan::output()) ?: 'Database up to date.';
            // Remember which batch this update created, so a rollback can undo exactly that.
            if (($after = $this->maxMigrationBatch()) > $before) {
                $batch = $after;
                $this->updateBackupManifest($state['backup'], ['migration_batch' => $batch]);
            }
        } finally {
            // Clear compiled config/routes/views and the app cache so the new code is used everywhere.
            try {
                Artisan::call('optimize:clear');
            } catch (Throwable) {
                $this->clearCompiledFiles();
            }
            $this->bringOnline();
        }

        $this->delete($this->workPath.'/staging');
        @unlink($this->statePath());
        $this->pruneBackups();
        Cache::forget('selfupdate.latest');

        return [
            'message' => 'Updated to version '.$this->currentVersion().'.',
            'version' => $this->currentVersion(),
            'backup' => $state['backup'],
            'migration_batch' => $batch,
            'details' => $notes,
        ];
    }

    /**
     * Undo the database changes an update made (its migration batch). Must run
     * while the updated files are still in place, i.e. before restore().
     */
    public function rollbackDatabase(string $id): ?string
    {
        $manifest = json_decode((string) @file_get_contents($this->backupDir($id).'/manifest.json'), true) ?: [];
        if (empty($manifest['migration_batch'])) {
            return null;
        }
        Artisan::call('migrate:rollback', ['--batch' => (int) $manifest['migration_batch'], '--force' => true]);

        return trim(Artisan::output());
    }

    protected function maxMigrationBatch(): int
    {
        try {
            return (int) \Illuminate\Support\Facades\DB::table('migrations')->max('batch');
        } catch (Throwable) {
            return 0;
        }
    }

    protected function updateBackupManifest(string $id, array $data): void
    {
        $file = $this->workPath.'/backups/'.$id.'/manifest.json';
        $manifest = json_decode((string) @file_get_contents($file), true) ?: [];
        file_put_contents($file, json_encode([...$manifest, ...$data], JSON_PRETTY_PRINT));
    }

    /** Put a previous version's files back (this does not change the database; see rollbackDatabase()). */
    public function restore(string $id, bool $mentionDatabase = true): array
    {
        $this->prepareLongStep();
        $dir = $this->backupDir($id);
        $manifest = json_decode((string) @file_get_contents($dir.'/manifest.json'), true);
        if (! is_array($manifest) || empty($manifest['entries'])) {
            throw new RuntimeException('This backup has no files to restore.');
        }

        Artisan::call('down', ['--retry' => 30]);
        $trash = $this->workPath.'/trash-'.Str::random(6);

        try {
            foreach ([[$this->basePath, 'files', $manifest['entries']], [$this->publicPath, 'public', $manifest['public'] ?? []]] as [$root, $sub, $entries]) {
                foreach ($entries as $entry) {
                    $live = $root.'/'.$entry['name'];
                    if (file_exists($live) || is_link($live)) {
                        $this->move($live, $trash.'/'.$sub.'/'.$entry['name']);
                    }
                    if ($entry['existed']) {
                        $this->move($dir.'/'.$sub.'/'.$entry['name'], $live);
                    }
                }
            }
        } finally {
            // The restored code is older than what is loaded in this request, so clear caches by hand.
            $this->clearCompiledFiles();
            $this->resetOpcache();
            $this->bringOnline();
        }

        $this->delete($trash);
        $this->delete($dir);
        Cache::forget('selfupdate.latest');

        return ['message' => 'Restored version '.($manifest['from'] ?? '').'.'.($mentionDatabase ? ' The database was not changed.' : '')];
    }

    /**
     * One-click rollback to a previous version: undo every later update of the same item
     * (core, or one module), newest first. Database changes of those updates are undone first
     * (while the newest code, which has all their migration files, is still in place), then the
     * files are put back one update at a time. The database backups stay downloadable until pruned.
     *
     * @return array{message: string, version: string, details: array<int, string>}
     */
    public function rollbackTo(string $id): array
    {
        $this->prepareLongStep();
        if ($this->readState()) {
            throw new RuntimeException('An update is in progress. Finish or cancel it first.');
        }
        $target = collect($this->backups())->firstWhere('id', $this->backupId($id));
        if (! $target) {
            throw new RuntimeException('Backup not found.');
        }

        // The target and every newer backup of the same item, newest first.
        $chain = collect($this->backups())
            ->filter(fn ($b) => $b['kind'] === $target['kind'] && $b['slug'] === $target['slug'] && strcmp($b['id'], $target['id']) >= 0)
            ->sortByDesc('id')->values();

        Artisan::call('down', ['--retry' => 30]);
        $details = [];
        try {
            foreach ($chain as $backup) {
                try {
                    $output = (string) $this->rollbackDatabase($backup['id']);
                } catch (Throwable $e) {
                    // Don't leave the newest code on a half-rolled-back database: re-apply what was undone.
                    try {
                        Artisan::call('migrate', ['--force' => true]);
                    } catch (Throwable) {
                    }
                    throw new RuntimeException('Undoing the database changes of version '.($backup['replaced_by'] ?? '?').' failed, so nothing was rolled back: '.$e->getMessage());
                }
                preg_match_all('/(\d{4}_\d{2}_\d{2}_\d{6}_\w+)\s.*DONE/', $output, $m);
                if ($m[1]) {
                    $details[] = 'Undid database changes of '.($backup['replaced_by'] ?? 'the update').': '.implode(', ', $m[1]).'.';
                }
            }
            foreach ($chain as $backup) {
                $this->restore($backup['id'], mentionDatabase: false);
            }
        } finally {
            $this->bringOnline();
        }

        $label = 'version '.$target['version'];
        $details[] = 'Files restored to '.$label.'.';

        return ['message' => 'Rolled back to '.$label.'.', 'version' => $target['version'], 'kind' => $target['kind'], 'slug' => $target['slug'], 'details' => $details];
    }

    protected function backupId(string $id): string
    {
        return basename($this->backupDir($id));
    }

    /** Leave maintenance mode (also used to recover from an interrupted update). */
    public function bringOnline(): void
    {
        @unlink(storage_path('framework/down'));
        @unlink(storage_path('framework/maintenance.php'));
    }

    public function isDown(): bool
    {
        return is_file(storage_path('framework/down'));
    }

    /** An update that was started but not finished: ['step' => …, 'to' => …] or null. */
    public function pending(): ?array
    {
        $state = $this->readState();

        return $state ? ['step' => $state['step'], 'from' => $state['from'], 'to' => $state['to'], 'backup' => $state['backup'] ?? null, 'started_at' => $state['started_at'] ?? null] : null;
    }

    /** Forget an update whose files were already put back (used after a failed finish). */
    public function forgetState(): void
    {
        @unlink($this->statePath());
        $this->delete($this->workPath.'/staging');
    }

    /** Throw away a half-done update (before install) and start over. */
    public function cancel(): void
    {
        $state = $this->readState();
        if (($state['step'] ?? null) === 'install') {
            throw new RuntimeException('The new files are already installed. Finish the update instead.');
        }
        if (! empty($state['backup'])) {
            $this->delete($this->backupDir($state['backup']));
        }
        $this->clearWork(keepBackups: true);
    }

    /** Previous versions that can be restored, newest first. */
    public function backups(): array
    {
        return collect(glob($this->workPath.'/backups/*', GLOB_ONLYDIR) ?: [])
            ->map(function ($dir) {
                $manifest = json_decode((string) @file_get_contents($dir.'/manifest.json'), true) ?: [];
                $db = $dir.'/database.sql.gz';

                return [
                    'id' => basename($dir),
                    'kind' => $manifest['kind'] ?? 'core',
                    'slug' => $manifest['slug'] ?? 'core',
                    'version' => $manifest['from'] ?? Str::after(basename($dir), '_v'),
                    'replaced_by' => $manifest['to'] ?? null,
                    'created_at' => $manifest['created_at'] ?? null,
                    'restorable' => ! empty($manifest['entries']),
                    'database_size' => is_file($db) ? filesize($db) : null,
                ];
            })
            ->filter(fn ($b) => $b['restorable'])
            ->sortByDesc('id')->values()->all();
    }

    public function databaseBackupPath(string $id): string
    {
        $file = $this->backupDir($id).'/database.sql.gz';
        if (! is_file($file)) {
            throw new RuntimeException('Backup not found.');
        }

        return $file;
    }

    /* ------------------------------------------------------------------ */
    /* File swapping                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Moves each top-level entry of the release over the live site, keeping the
     * old one in the backup folder. public/build is replaced; other public files
     * are only added when missing. Returns the manifest needed to restore.
     */
    protected function swapIn(string $root, string $backupDir): array
    {
        $done = []; // [[liveRoot, backupSub, name, existed], …] in order, for rollback
        $preserve = config('selfupdate.preserve');
        $publicPreserve = config('selfupdate.public_preserve');

        try {
            foreach (array_diff(scandir($root), ['.', '..']) as $name) {
                if (in_array($name, $preserve, true)) {
                    continue;
                }
                $this->replace($this->basePath, $root.'/'.$name, $backupDir.'/files', $name, $done);
            }

            if (is_dir($root.'/public')) {
                foreach (array_diff(scandir($root.'/public'), ['.', '..']) as $name) {
                    if (in_array($name, $publicPreserve, true)) {
                        continue;
                    }
                    $live = $this->publicPath.'/'.$name;
                    if ($name === 'build' || ! (file_exists($live) || is_link($live))) {
                        $this->replace($this->publicPath, $root.'/public/'.$name, $backupDir.'/public', $name, $done);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->undo($done);
            throw $e;
        }

        $list = fn ($sub) => collect($done)->filter(fn ($d) => $d[1] === $backupDir.'/'.$sub)->map(fn ($d) => ['name' => $d[2], 'existed' => $d[3]])->values()->all();

        return ['entries' => $list('files'), 'public' => $list('public')];
    }

    /** Reverse a partial swap: remove what was placed, move the originals back. */
    protected function undo(array $done): void
    {
        foreach (array_reverse($done) as [$liveRoot, $backupSub, $name, $existed, $placed]) {
            $live = $liveRoot.'/'.$name;
            if ($placed) {
                $this->delete($live);
            }
            if ($existed) {
                $this->move($backupSub.'/'.$name, $live);
            }
        }
    }

    protected function replace(string $liveRoot, string $new, string $backupSub, string $name, array &$done): void
    {
        $live = $liveRoot.'/'.$name;
        $existed = file_exists($live) || is_link($live);
        $done[] = [$liveRoot, $backupSub, $name, $existed, false];
        $i = array_key_last($done);

        if ($existed) {
            $this->move($live, $backupSub.'/'.$name);
        }
        $this->move($new, $live);
        $done[$i][4] = true;
    }

    /** rename(), falling back to copy + delete (e.g. public_html on another disk). */
    protected function move(string $from, string $to): void
    {
        $this->ensureDir(dirname($to));
        if (@rename($from, $to)) {
            return;
        }
        $this->copy($from, $to);
        $this->delete($from);
    }

    protected function copy(string $from, string $to): void
    {
        if (is_link($from)) {
            if (! @symlink(readlink($from), $to)) {
                throw new RuntimeException("Could not copy {$from}");
            }

            return;
        }
        if (is_file($from)) {
            if (! @copy($from, $to)) {
                throw new RuntimeException("Could not write {$to}");
            }

            return;
        }
        $this->ensureDir($to);
        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $item) {
            $this->copy($item->getPathname(), $to.'/'.$item->getFilename());
        }
    }

    /** Recursive delete that never follows symlinks. */
    protected function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path) || (PHP_OS_FAMILY === 'Windows' && @rmdir($path));

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            $this->delete($item->getPathname());
        }
        @rmdir($path);
    }

    protected function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Housekeeping                                                        */
    /* ------------------------------------------------------------------ */

    protected function prepareLongStep(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');
    }

    protected function statePath(): string
    {
        return $this->workPath.'/state.json';
    }

    protected function readState(): ?array
    {
        $state = json_decode((string) @file_get_contents($this->statePath()), true);

        return is_array($state) ? $state : null;
    }

    protected function writeState(array $state): void
    {
        $this->ensureDir($this->workPath);
        file_put_contents($this->statePath(), json_encode($state, JSON_PRETTY_PRINT));
    }

    protected function requireState(string $previous): array
    {
        $state = $this->readState();
        if (($state['step'] ?? null) !== $previous) {
            throw new RuntimeException('The update steps ran out of order. Please start the update again.');
        }

        return $state;
    }

    protected function backupDir(string $id): string
    {
        if (! preg_match('~^[\w.-]+$~', $id) || ! is_dir($dir = $this->workPath.'/backups/'.$id)) {
            throw new RuntimeException('Backup not found.');
        }

        return $dir;
    }

    protected function clearWork(bool $keepBackups): void
    {
        foreach (glob($this->workPath.'/{,.}*', GLOB_BRACE) ?: [] as $path) {
            $name = basename($path);
            if (in_array($name, ['.', '..', '.gitignore'], true) || ($keepBackups && $name === 'backups')) {
                continue;
            }
            $this->delete($path);
        }
    }

    /**
     * Keep the newest backups: at least 2, or selfupdate.keep_backups. Older ones are deleted automatically.
     */
    protected function pruneBackups(): void
    {
        $keep = max(2, (int) config('selfupdate.keep_backups'));

        collect(glob($this->workPath.'/backups/*', GLOB_ONLYDIR) ?: [])
            ->groupBy(function ($dir) {
                $manifest = json_decode((string) @file_get_contents($dir.'/manifest.json'), true) ?: [];

                return ($manifest['kind'] ?? 'core').':'.($manifest['slug'] ?? 'core');
            })
            ->each(fn ($dirs) => $dirs->sortDesc()->values()->slice($keep)->each(fn ($dir) => $this->delete($dir)));
    }

    protected function clearCompiledFiles(): void
    {
        foreach ([...glob($this->basePath.'/bootstrap/cache/*.php') ?: [], ...glob(storage_path('framework/views/*.php')) ?: []] as $file) {
            @unlink($file);
        }
    }

    protected function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    protected function fetchChecksum(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $body = $this->github()->replaceHeaders(['Accept' => 'application/octet-stream'])->get($url)->body();

        return preg_match('/\b([a-f0-9]{64})\b/i', $body, $m) ? $m[1] : null;
    }
}
