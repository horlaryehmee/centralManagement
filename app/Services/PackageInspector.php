<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Reads an uploaded update package and works out what it is:
 *
 *   core      – contains VERSION and artisan at its root
 *   module    – contains module.json with "type": "module"
 *   extension – contains module.json with "type": "extension"
 *
 * Also lists every file with its SHA-256 for the signed manifest, and rejects
 * unsafe paths and files that must never ship (.env, client storage).
 */
class PackageInspector
{
    /** Never allowed inside a core package. */
    protected const FORBIDDEN_CORE = ['.env', 'storage/app/', 'storage/logs/', 'storage/framework/sessions/', 'modules/', 'extensions/'];

    /**
     * @return array{type: string, slug: string, version: string, name: ?string, requires: array,
     *               root: string, files: array<string, string>, has_migrations: bool, warnings: string[]}
     */
    public function inspect(string $zipPath): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The file is not a valid zip archive.');
        }

        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($name === '' || str_contains($name, '../') || str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name) || str_contains($name, "\0")) {
                    throw new RuntimeException("Unsafe path in package: {$name}");
                }
                $names[$i] = $name;
            }

            $root = $this->commonRoot($names);
            $files = [];
            $hasMigrations = false;
            foreach ($names as $i => $name) {
                if (str_ends_with($name, '/')) {
                    continue; // directory entry
                }
                $relative = substr($name, strlen($root));
                $stream = $zip->getStream($name);
                if (! $stream) {
                    throw new RuntimeException("Could not read {$relative} from the package.");
                }
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                fclose($stream);
                $files[$relative] = hash_final($ctx);
                if (preg_match('~(^|/)database/migrations/[^/]+\.php$~', $relative)) {
                    $hasMigrations = true;
                }
            }
            ksort($files);

            $warnings = [];
            if (isset($files['VERSION'], $files['artisan'])) {
                $type = 'core';
                $slug = 'core';
                $version = trim((string) $zip->getFromName($root.'VERSION'));
                $name = null;
                $requires = [];
                foreach (array_keys($files) as $f) {
                    foreach (self::FORBIDDEN_CORE as $bad) {
                        if ($f === rtrim($bad, '/') || (str_ends_with($bad, '/') && str_starts_with($f, $bad) && ! str_ends_with($f, '.gitignore'))) {
                            throw new RuntimeException("A core package must not contain {$f} (client data or a module folder).");
                        }
                    }
                }
                foreach (['vendor/autoload.php', 'bootstrap/app.php', 'public/build/manifest.json'] as $required) {
                    if (! isset($files[$required])) {
                        throw new RuntimeException("The core package is incomplete: {$required} is missing. Build it with the release workflow (composer install and npm run build).");
                    }
                }
            } elseif (isset($files['module.json'])) {
                $meta = json_decode((string) $zip->getFromName($root.'module.json'), true);
                if (! is_array($meta)) {
                    throw new RuntimeException('module.json is not valid JSON.');
                }
                $type = ($meta['type'] ?? 'module') === 'extension' ? 'extension' : 'module';
                $slug = (string) ($meta['slug'] ?? '');
                $version = (string) ($meta['version'] ?? '');
                $name = $meta['name'] ?? null;
                $requires = is_array($meta['requires'] ?? null) ? $meta['requires'] : [];
                if (! preg_match('/^[a-z0-9][a-z0-9-]{1,98}$/', $slug)) {
                    throw new RuntimeException('module.json needs a "slug" of lowercase letters, numbers and dashes.');
                }
            } else {
                throw new RuntimeException('Unknown package: expected VERSION + artisan (core) or module.json (module / extension) at the top level.');
            }

            if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                throw new RuntimeException("Invalid version \"{$version}\". Use MAJOR.MINOR.PATCH, e.g. 1.4.0.");
            }
            if (! $files) {
                throw new RuntimeException('The package is empty.');
            }

            return compact('type', 'slug', 'version', 'name', 'requires', 'root', 'files', 'warnings') + ['has_migrations' => $hasMigrations];
        } finally {
            $zip->close();
        }
    }

    /** "pkg/" when every entry sits in one top-level folder that is itself not the payload. */
    protected function commonRoot(array $names): string
    {
        $tops = array_unique(array_map(fn ($n) => explode('/', $n)[0], $names));
        if (count($tops) !== 1) {
            return '';
        }
        $top = reset($tops);
        $isFolder = collect($names)->contains(fn ($n) => str_starts_with($n, $top.'/'));
        $hasMarker = collect($names)->contains(fn ($n) => in_array($n, ['VERSION', 'module.json'], true));

        return $isFolder && ! $hasMarker ? $top.'/' : '';
    }
}
