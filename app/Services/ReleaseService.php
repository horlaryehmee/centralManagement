<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Event;
use App\Models\Release;
use App\Models\User;
use App\Support\VersionConstraint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Release lifecycle: draft (package uploaded, details editable) → published
 * (manifest built and signed, details locked, deployable) → withdrawn
 * (no new deployments; still usable as a baseline for file checks).
 */
class ReleaseService
{
    public function __construct(protected PackageInspector $inspector, protected Signer $signer) {}

    /**
     * Create a draft from a package file. Type, slug and version are read from the package
     * itself; $data may add notes, requirements, upgrade range and (for extensions) the client.
     */
    public function createFromPackage(string $path, array $data, ?User $user = null): Release
    {
        try {
            $info = $this->inspector->inspect($path);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['package' => $e->getMessage()]);
        }

        foreach (['type', 'slug', 'version'] as $field) {
            if (filled($data[$field] ?? null) && $data[$field] !== $info[$field]) {
                throw ValidationException::withMessages(['package' => "The package says {$field} \"{$info[$field]}\", but \"{$data[$field]}\" was entered."]);
            }
        }

        $client = null;
        if ($info['type'] === 'extension') {
            $client = Client::find($data['client_id'] ?? null);
            if (! $client) {
                throw ValidationException::withMessages(['client_id' => 'Choose the client this extension belongs to. It can only ever be deployed to that client\'s websites.']);
            }
        }

        $duplicate = Release::query()->where('type', $info['type'])->where('slug', $info['slug'])->where('version', $info['version'])
            ->when($client, fn ($q) => $q->where('client_id', $client->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['package' => "{$info['slug']} {$info['version']} already exists. Each version can be released once; bump the version number."]);
        }

        $requires = $this->requires($data['requires'] ?? null, $info['requires']);

        return DB::transaction(function () use ($path, $data, $info, $client, $requires, $user) {
            $release = Release::create([
                'type' => $info['type'],
                'slug' => $info['slug'],
                'client_id' => $client?->id,
                'version' => $info['version'],
                'name' => $data['name'] ?? $info['name'],
                'notes' => $data['notes'] ?? null,
                'requires' => $requires,
                'upgrade_from_min' => $data['upgrade_from_min'] ?? null,
                'upgrade_from_max' => $data['upgrade_from_max'] ?? null,
                'has_migrations' => (bool) ($data['has_migrations'] ?? $info['has_migrations']),
                'database_rollback' => (bool) ($data['database_rollback'] ?? false),
                'status' => 'draft',
                'package_size' => filesize($path),
                'package_sha256' => hash_file('sha256', $path),
                'file_count' => count($info['files']),
                'created_by' => $user?->id,
            ]);

            $stored = config('central.packages_dir').'/'.$release->uuid.'.zip';
            Storage::disk(config('central.packages_disk'))->put($stored, fopen($path, 'rb'));
            // The file list is kept until publishing, when it goes into the signed manifest.
            Storage::disk(config('central.packages_disk'))->put($stored.'.files.json', json_encode(['root' => $info['root'], 'files' => $info['files']]));
            $release->update(['package_path' => $stored]);

            Event::log('release.created', "Release {$release->label()} uploaded as a draft.", ['user' => $user, 'client' => $client]);

            return $release;
        });
    }

    public function updateDraft(Release $release, array $data): Release
    {
        if ($release->status !== 'draft') {
            throw ValidationException::withMessages(['release' => 'Only drafts can be edited. Published releases are signed and locked.']);
        }
        $release->update([
            'name' => $data['name'] ?? $release->name,
            'notes' => $data['notes'] ?? null,
            'requires' => $this->requires($data['requires'] ?? null, []),
            'upgrade_from_min' => $data['upgrade_from_min'] ?? null,
            'upgrade_from_max' => $data['upgrade_from_max'] ?? null,
            'has_migrations' => (bool) ($data['has_migrations'] ?? false),
            'database_rollback' => (bool) ($data['database_rollback'] ?? false),
        ]);

        return $release;
    }

    /** Build and sign the manifest, then make the release deployable. */
    public function publish(Release $release, ?User $user = null): Release
    {
        if ($release->status === 'published') {
            return $release;
        }
        if ($release->status === 'withdrawn' && $release->manifest) {
            $release->update(['status' => 'published', 'withdrawn_at' => null]);
            Event::log('release.republished', "Release {$release->label()} published again.", ['user' => $user]);

            return $release;
        }

        $disk = Storage::disk(config('central.packages_disk'));
        if (! $disk->exists($release->package_path)) {
            throw ValidationException::withMessages(['release' => 'The package file is missing. Upload the release again.']);
        }
        if (hash_file('sha256', $disk->path($release->package_path)) !== $release->package_sha256) {
            throw ValidationException::withMessages(['release' => 'The stored package no longer matches its checksum. Upload it again.']);
        }
        $listing = json_decode((string) $disk->get($release->package_path.'.files.json'), true);

        $manifest = [
            'format' => 1,
            'release' => $release->uuid,
            'type' => $release->type,
            'slug' => $release->slug,
            'version' => $release->version,
            // Extensions are bound to one client: the agent refuses a manifest for another client.
            'client' => $release->client?->key,
            'requires' => (object) ($release->requires ?: []),
            'upgrade_from' => ['min' => $release->upgrade_from_min, 'max' => $release->upgrade_from_max],
            'migrations' => $release->has_migrations,
            'rollback' => ['files' => true, 'database' => $release->database_rollback],
            'package' => ['sha256' => $release->package_sha256, 'size' => $release->package_size, 'root' => $listing['root'] ?? ''],
            'files' => (object) ($listing['files'] ?? []),
            'instructions' => match ($release->type) {
                'core' => 'Replace core application files; never touch .env, storage/, modules/, extensions/ or custom public files. Run migrations.',
                'module' => "Install into modules/{$release->slug}. Run its migrations.",
                'extension' => "Install into extensions/{$release->slug}. Run its migrations.",
            },
            'key_id' => $this->signer->keyId(),
            'signed_at' => now()->toIso8601String(),
        ];

        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $release->update([
            'manifest' => $json,
            'signature' => $this->signer->sign($json),
            'signing_key_id' => $this->signer->keyId(),
            'status' => 'published',
            'published_at' => now(),
            'withdrawn_at' => null,
        ]);
        $disk->delete($release->package_path.'.files.json');

        Event::log('release.published', "Release {$release->label()} published.", ['user' => $user, 'level' => 'success', 'client' => $release->client]);

        return $release;
    }

    /** No new deployments. Queued (not yet delivered) targets of this release are cancelled. */
    public function withdraw(Release $release, ?User $user = null): Release
    {
        $release->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $cancelled = 0;
        foreach ($release->deployments as $deployment) {
            $cancelled += app(DeploymentService::class)->cancel($deployment, $user, 'Release withdrawn');
        }
        Event::log('release.withdrawn', "Release {$release->label()} withdrawn".($cancelled ? "; {$cancelled} queued updates cancelled." : '.'), ['user' => $user, 'level' => 'warning']);

        return $release;
    }

    public function deleteDraft(Release $release): void
    {
        if ($release->status !== 'draft' || $release->deployments()->exists()) {
            throw ValidationException::withMessages(['release' => 'Only unused drafts can be deleted. Withdraw published releases instead.']);
        }
        $disk = Storage::disk(config('central.packages_disk'));
        $disk->delete([$release->package_path, $release->package_path.'.files.json']);
        $release->delete();
    }

    /** Merge requirements typed in the form with those declared in module.json; validate constraints. */
    protected function requires(?array $input, array $declared): array
    {
        $requires = array_filter([
            'core' => $input['core'] ?? $declared['core'] ?? null,
            'php' => $input['php'] ?? $declared['php'] ?? null,
            'modules' => array_filter((array) ($input['modules'] ?? $declared['modules'] ?? [])),
        ]);

        foreach (['core', 'php'] as $key) {
            if (isset($requires[$key]) && ! VersionConstraint::isValid($requires[$key])) {
                throw ValidationException::withMessages(["requires.{$key}" => "\"{$requires[$key]}\" is not a valid version constraint (e.g. >=1.2 <2.0, ^1.2)."]);
            }
        }
        foreach ($requires['modules'] ?? [] as $slug => $constraint) {
            if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', (string) $slug) || ! VersionConstraint::isValid($constraint)) {
                throw ValidationException::withMessages(['requires.modules' => "Invalid module requirement: {$slug} {$constraint}"]);
            }
        }

        return $requires;
    }
}
