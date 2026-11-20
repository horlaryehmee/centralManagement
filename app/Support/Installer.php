<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * WordPress-style web installer support: upload the files, open the site,
 * fill in the database details and the admin account. No terminal needed.
 *
 * "Installed" = storage/app/installed.json exists. Sites installed before the
 * installer existed are detected automatically (a configured database with a
 * migrations table) and get the lock file on their first request.
 */
class Installer
{
    public const LOCK = 'storage/app/installed.json';

    /** Present while the wizard is running (written when the database details are saved). */
    public const INSTALLING = 'storage/app/installing.json';

    public static function isInstalled(string $base): bool
    {
        if (is_file($base.'/'.self::LOCK)) {
            return true;
        }
        // Mid-setup (the wizard has started): never mistake a half-finished install for a finished one.
        if (is_file($base.'/'.self::INSTALLING) || ! is_file($base.'/.env') || ! env('DB_DATABASE')) {
            return false;
        }
        // Installed before the installer existed: database set up and an administrator exists.
        try {
            $schema = DB::connection()->getSchemaBuilder();
            if ($schema->hasTable('migrations') && $schema->hasTable('users') && DB::table('users')->exists()) {
                self::markInstalled($base, ['detected' => true]);

                return true;
            }
        } catch (Throwable) {
        }

        return false;
    }

    /**
     * First visit of a fresh upload: create .env from .env.example with a new
     * APP_KEY and file-based sessions/cache, so the wizard runs without a database.
     */
    public static function prepareEnv(string $base): void
    {
        if (is_file($base.'/.env') || ! is_file($base.'/.env.example') || ! is_writable($base)) {
            return;
        }
        $env = (string) file_get_contents($base.'/.env.example');
        $env = self::setValues($env, [
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'DB_DATABASE' => '',
            // Until the real address is confirmed: no forced https, cookies work on http too.
            'APP_URL' => '',
            'SESSION_SECURE_COOKIE' => 'false',
        ]);
        @file_put_contents($base.'/.env', $env);
        @chmod($base.'/.env', 0640);
    }

    /** @return array<int, array{label: string, ok: bool, help?: string}> */
    public static function requirements(string $base, array $extensions): array
    {
        $checks = [['label' => 'PHP '.PHP_VERSION.' (8.2 or newer)', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'help' => 'Choose PHP 8.2+ in cPanel → MultiPHP Manager (or Select PHP Version).']];
        foreach ($extensions as $ext) {
            $checks[] = ['label' => "PHP extension: {$ext}", 'ok' => extension_loaded($ext), 'help' => "Turn on \"{$ext}\" in cPanel → Select PHP Version → Extensions."];
        }
        foreach (['.env', 'storage', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $path) {
            $full = $base.'/'.$path;
            $checks[] = ['label' => "Writable: {$path}", 'ok' => file_exists($full) ? is_writable($full) : is_writable(dirname($full)), 'help' => 'Set this folder/file to permission 755 (folders) or 644 (files) in cPanel → File Manager → Permissions.'];
        }

        return $checks;
    }

    /** Try the database details; returns an error message for people, or null when they work. */
    public static function testDatabase(array $db): ?string
    {
        try {
            $pdo = new PDO("mysql:host={$db['host']};port={$db['port']}", $db['username'], (string) $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'Access denied')
                ? 'The database username or password is wrong.'
                : 'Could not reach the database server. Check the host (usually "localhost" on cPanel) and port.';
        }
        try {
            $pdo->exec('USE `'.str_replace('`', '', $db['database']).'`');
        } catch (Throwable) {
            // Try to create it (works when the user has the right; on cPanel create it in "MySQL Databases").
            try {
                $pdo->exec('CREATE DATABASE `'.str_replace('`', '', $db['database']).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (Throwable) {
                return "The database \"{$db['database']}\" doesn't exist (or this user has no access to it). On cPanel: MySQL Databases → create the database, then add the user to it with ALL PRIVILEGES.";
            }
        }

        return null;
    }

    /** Point the running app at the database (for the rest of this request). */
    public static function useDatabase(array $db): void
    {
        config(['database.connections.mysql' => array_merge(config('database.connections.mysql'), [
            'host' => $db['host'], 'port' => $db['port'], 'database' => $db['database'],
            'username' => $db['username'], 'password' => (string) $db['password'],
        ]), 'database.default' => 'mysql']);
        DB::purge('mysql');
    }

    /** Update KEY=value lines in .env (adds missing keys). */
    public static function writeEnv(string $base, array $values): void
    {
        $file = $base.'/.env';
        $env = is_file($file) ? (string) file_get_contents($file) : (string) @file_get_contents($base.'/.env.example');
        file_put_contents($file, self::setValues($env, $values));
    }

    public static function setValues(string $env, array $values): string
    {
        foreach ($values as $key => $value) {
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if ($value !== '' && preg_match('/[\s#"\'$\\\\]/', $value)) {
                $value = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
            }
            $line = $key.'='.$value;
            $env = preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)
                ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env)
                : rtrim($env)."\n".$line."\n";
        }

        return $env;
    }

    public static function markInstalled(string $base, array $info = []): void
    {
        @mkdir(dirname($base.'/'.self::LOCK), 0755, true);
        @file_put_contents($base.'/'.self::LOCK, json_encode($info + ['installed_at' => date('c')], JSON_PRETTY_PRINT));
        @unlink($base.'/'.self::INSTALLING);
    }

    public static function markInstalling(string $base): void
    {
        @mkdir(dirname($base.'/'.self::INSTALLING), 0755, true);
        @file_put_contents($base.'/'.self::INSTALLING, json_encode(['started_at' => date('c')]));
    }

    /** The address the site is being opened at, e.g. https://shop.example.com or https://example.com/shop. */
    public static function detectUrl(): string
    {
        $request = request();
        $base = rtrim(preg_replace('~/(public/)?index\.php$~', '', $request->getBaseUrl()), '/');

        return $request->getSchemeAndHttpHost().$base;
    }
}
