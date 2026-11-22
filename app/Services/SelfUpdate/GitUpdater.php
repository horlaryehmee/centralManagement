<?php

namespace App\Services\SelfUpdate;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One-click updates straight from the GitHub repository (no release zips).
 *
 * The install folder is a Git working copy of the repository. "Update now" runs these
 * steps, each its own short request so no single request hits the host's time limit:
 *
 *   fetch → backup → pull → dependencies → finish
 *
 * fetch:        git fetch; what's new between the current commit and the branch.
 * backup:       database dump + the current commit, for rollback.
 * pull:         maintenance mode, git reset --hard to the new commit (on failure: back to the old one).
 * dependencies: composer install, only when composer.json/lock changed (or vendor/ is missing).
 * finish:       runs on the NEW code: migrations (batch recorded), cache clearing, back online.
 *
 * Only files tracked by Git change. .env, storage/ and vendor/ are not in Git and are never
 * touched by Git itself. The access token goes in a per-command HTTP header, never in .git/config.
 */
class GitUpdater
{
    public const STEPS = ['fetch', 'backup', 'pull', 'dependencies', 'finish'];

    protected string $basePath;

    protected string $workPath;

    public function __construct(?string $basePath = null, ?string $workPath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/\\');
        $this->workPath = rtrim($workPath ?? storage_path('app/updates'), '/\\');
    }

    /* ------------------------------------------------------------------ */
    /* Source                                                              */
    /* ------------------------------------------------------------------ */

    /** ['repository', 'branch', 'token', 'remote']; values saved on the Updates page win over .env. */
    public function source(): array
    {
        $s = SystemSetting::current();
        $repository = self::cleanRepository($s->update_repository ?: config('selfupdate.repository'));
        $branch = self::cleanBranch($s->update_branch ?: config('selfupdate.branch')) ?? 'main';

        return [
            'repository' => $repository,
            'branch' => $branch,
            'token' => $s->update_token ?: config('selfupdate.token'),
            'remote' => config('selfupdate.remote') ?: ($repository ? "https://github.com/{$repository}.git" : null),
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

    public static function cleanBranch(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('~^[A-Za-z0-9._/-]{1,100}$~', $value) && ! str_contains($value, '..') && ! str_starts_with($value, '-') ? $value : null;
    }

    /* ------------------------------------------------------------------ */
    /* Status                                                              */
    /* ------------------------------------------------------------------ */

    public function isRepository(): bool
    {
        return is_dir($this->basePath.'/.git');
    }

    /** The commit this install runs: ['hash', 'short', 'subject', 'date'] or null. */
    public function current(): ?array
    {
        if (! $this->isRepository()) {
            return null;
        }
        try {
            return $this->commitInfo('HEAD');
        } catch (Throwable) {
            return null;
        }
    }

    /** Cached result of the last check, without contacting GitHub (for the menu badge). */
    public function cachedStatus(): ?array
    {
        $status = Cache::get('selfupdate.git');

        return is_array($status) ? $status : null;
    }

    /**
     * Fetch the branch and compare: ['behind' => n, 'commits' => [...], 'current' => …, 'latest' => …,
     * 'changed_files' => [...], 'error' => ?string].
     */
    public function check(bool $fresh = false): array
    {
        if (! $fresh && ($cached = $this->cachedStatus())) {
            return $cached;
        }
        $source = $this->source();
        $base = ['current' => $this->current(), 'behind' => 0, 'commits' => [], 'latest' => null, 'changed_files' => [], 'branch' => $source['branch'], 'checked_at' => now()->toIso8601String()];

        if (! $source['remote']) {
            return $base + ['error' => 'No repository is set up yet. Add it under "Update source".'];
        }
        if (! $this->isRepository()) {
            return $base + ['error' => 'This folder is not connected to GitHub yet. Press "Connect to GitHub" below.'];
        }

        try {
            $this->fetch();
            $target = $this->git(['rev-parse', 'origin/'.$source['branch']]);
            $behind = (int) $this->git(['rev-list', '--count', 'HEAD..'.$target]);
            $log = $behind ? $this->git(['log', '-n', '30', '--pretty=format:%h%x1f%an%x1f%aI%x1f%s', 'HEAD..'.$target]) : '';
            $status = [
                ...$base,
                'behind' => $behind,
                'latest' => $this->commitInfo($target),
                'commits' => collect(array_filter(explode("\n", $log)))->map(function ($line) {
                    [$short, $author, $date, $subject] = array_pad(explode("\x1f", $line), 4, '');

                    return compact('short', 'author', 'date', 'subject');
                })->values()->all(),
                'changed_files' => $changed = $this->locallyChanged(),
                'first_sync' => $this->justConnected() && $changed !== [],
                'error' => null,
            ];
        } catch (Throwable $e) {
            return $base + ['error' => $this->friendly($e->getMessage())];
        }

        $status['available'] = $status['behind'] > 0 || $status['first_sync'];
        Cache::put('selfupdate.git', $status, config('selfupdate.check_ttl'));

        return $status;
    }

    /** Tracked files that were changed by hand on the server (an update replaces them). */
    public function locallyChanged(): array
    {
        $out = $this->git(['status', '--porcelain', '--untracked-files=no']);

        return collect(preg_split('/\R/', $out))->map(fn ($l) => preg_match('/^.. (.+)$/', $l, $m) ? trim($m[1], " \"") : null)->filter()->take(50)->values()->all();
    }

    /** Server checks shown on the page: [['label', 'ok', 'detail'], …]. */
    public function preflight(): array
    {
        $procOpen = function_exists('proc_open') && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $git = $procOpen ? $this->gitBinary() : null;
        $php = $procOpen ? $this->phpBinary() : null;
        $composer = $procOpen && $php ? $this->composerCommand(false) : null;
        $writable = is_writable($this->basePath) && (! is_dir($this->basePath.'/.git') || is_writable($this->basePath.'/.git'));

        return [
            ['label' => 'PHP can run programs (proc_open)', 'ok' => $procOpen, 'detail' => 'Your host has disabled proc_open. Ask them to enable it (cPanel → Select PHP Version → Options → disable_functions).'],
            ['label' => 'Git'.($git ? ' ('.$git.')' : ''), 'ok' => (bool) $git, 'detail' => 'Git was not found. On cPanel it is usually /usr/local/cpanel/3rdparty/bin/git: set CENTRAL_GIT_BINARY in .env, or ask your host to enable Git.'],
            ['label' => 'PHP command line'.($php ? ' ('.$php.')' : ''), 'ok' => (bool) $php, 'detail' => 'No PHP command-line program was found for Composer. Set CENTRAL_PHP_BINARY in .env, e.g. /opt/cpanel/ea-php83/root/usr/bin/php.'],
            ['label' => $composer ? 'Composer ('.$composer['label'].')' : 'Composer (downloaded automatically when needed)', 'ok' => true, 'detail' => ''],
            ['label' => 'App files can be updated', 'ok' => $writable, 'detail' => 'The app folder (and .git) must be writable by PHP. Ask your host to fix the file permissions.'],
        ];
    }

    public function ready(): bool
    {
        return collect($this->preflight())->every('ok');
    }

    /* ------------------------------------------------------------------ */
    /* Connecting a folder that was installed from the zip                 */
    /* ------------------------------------------------------------------ */

    /**
     * Turn this folder into a Git working copy of the repository without changing any file:
     * the next "Update now" then brings it to the branch's latest commit. .env, storage/ and
     * vendor/ are ignored by the repository, so Git never touches them.
     */
    public function connect(): array
    {
        $this->prepareLongStep();
        $source = $this->source();
        if (! $source['remote']) {
            throw new RuntimeException('Add the repository under "Update source" first.');
        }
        if (! $this->isRepository()) {
            $this->git(['init', '-q']);
        }
        $remotes = explode("\n", $this->git(['remote']));
        $this->git(in_array('origin', $remotes, true) ? ['remote', 'set-url', 'origin', $source['remote']] : ['remote', 'add', 'origin', $source['remote']]);
        $this->fetch();
        // Point the branch at the repository without touching the files (mixed reset).
        $this->git(['checkout', '-q', '-B', $source['branch']], allowFailure: true);
        $this->git(['reset', '-q', 'origin/'.$source['branch']]);
        $this->writeJson($this->workPath.'/git-connected.json', ['at' => now()->toIso8601String(), 'branch' => $source['branch']]);
        Cache::forget('selfupdate.git');

        return ['message' => 'Connected to '.($source['repository'] ?: $source['remote']).' ('.$source['branch'].').'];
    }

    /** Right after connecting, files still match the older install: the first update may overwrite them. */
    public function justConnected(): bool
    {
        return is_file($this->workPath.'/git-connected.json');
    }

    /* ------------------------------------------------------------------ */
    /* Steps                                                               */
    /* ------------------------------------------------------------------ */

    /** Step 1: fetch, decide the target commit, check hand-edited files. */
    public function fetch_step(bool $discardLocalChanges = false): array
    {
        $this->prepareLongStep();
        if (! $this->ready()) {
            throw new RuntimeException('The server is not ready for updates yet. See the checks on this page.');
        }
        if ($this->readState()) {
            throw new RuntimeException('An update is already in progress. Continue or cancel it first.');
        }
        $source = $this->source();
        $this->fetch();
        $from = $this->git(['rev-parse', 'HEAD']);
        $to = $this->git(['rev-parse', 'origin/'.$source['branch']]);
        $changed = $this->locallyChanged();
        $firstSync = $this->justConnected() && $changed !== [];
        if ($from === $to && ! $firstSync) {
            throw new RuntimeException('Central Management is already up to date.');
        }
        if ($changed && ! $discardLocalChanges && ! $firstSync) {
            throw new RuntimeException('These files were changed on the server and would be replaced: '.implode(', ', array_slice($changed, 0, 10)).(count($changed) > 10 ? '…' : '').'. Tick "Replace changed files" to continue.');
        }
        $depsChanged = trim($this->git(['diff', '--name-only', $to, '--', 'composer.json', 'composer.lock'])) !== '' || ! is_file($this->basePath.'/vendor/autoload.php');

        $this->ensureDir($this->workPath);
        $this->writeState(['step' => 'fetch', 'from' => $from, 'to' => $to, 'branch' => $source['branch'], 'deps' => $depsChanged, 'started_at' => now()->toIso8601String()]);

        return ['message' => $from === $to ? 'First update since connecting: bringing every file to '.substr($to, 0, 7).'.' : 'New version found: '.substr($to, 0, 7).' ('.(int) $this->git(['rev-list', '--count', $from.'..'.$to]).' new changes).'];
    }

    /** Step 2: back up the database and remember the current commit. */
    public function backup(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('fetch');
        $id = now()->format('Y-m-d_His').'_'.substr($state['from'], 0, 7);
        $dir = $this->workPath.'/backups/'.$id;
        $this->ensureDir($dir);

        $result = app(DatabaseBackup::class)->dump($dir.'/database.sql.gz', 'Central Management database backup made before updating');
        $from = $this->commitInfo($state['from']);
        $this->writeJson($dir.'/manifest.json', ['from' => $state['from'], 'from_subject' => $from['subject'], 'from_date' => $from['date'], 'to' => $state['to'], 'created_at' => now()->toIso8601String()]);
        $this->writeState([...$state, 'step' => 'backup', 'backup' => $id]);

        return ['message' => "Backed up the database ({$result['tables']} tables, ".number_format($result['rows']).' rows).'];
    }

    /** Step 3: maintenance mode, move the files to the new commit (back to the old one on failure). */
    public function pull(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('backup');
        Artisan::call('down', ['--retry' => 30, '--refresh' => 15]);

        try {
            $this->git(['reset', '-q', '--hard', $state['to']]);
        } catch (Throwable $e) {
            rescue(fn () => $this->git(['reset', '-q', '--hard', $state['from']]), report: false);
            $this->bringOnline();
            throw new RuntimeException('Updating the files failed, so the previous version was put back. ('.$this->friendly($e->getMessage()).')', 0, $e);
        }
        @unlink($this->workPath.'/git-connected.json');
        $this->resetOpcache();
        $this->writeState([...$state, 'step' => 'pull']);

        return ['message' => 'Updated the files to '.substr($state['to'], 0, 7).'.'];
    }

    /** Step 4: composer install, only when the dependencies changed. */
    public function dependencies(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('pull');
        if (! $state['deps']) {
            $this->writeState([...$state, 'step' => 'dependencies']);

            return ['message' => 'Dependencies unchanged; Composer not needed.'];
        }

        try {
            $this->composerInstall();
        } catch (Throwable $e) {
            // Don't leave new code on old packages (or half-installed ones): go back completely.
            rescue(function () use ($state) {
                $this->git(['reset', '-q', '--hard', $state['from']]);
                $this->composerInstall();
            }, report: false);
            $this->clearCompiledFiles();
            $this->bringOnline();
            $this->forgetState();
            throw new RuntimeException('Installing the PHP packages (Composer) failed, so the previous version was put back. ('.$this->friendly($e->getMessage()).')', 0, $e);
        }
        $this->writeState([...$state, 'step' => 'dependencies']);

        return ['message' => 'Installed the PHP packages (Composer).'];
    }

    /** Step 5 (runs on the new code): migrate, clear caches, back online. */
    public function finish(): array
    {
        $this->prepareLongStep();
        $state = $this->requireState('dependencies');
        $notes = [];

        try {
            $before = $this->maxMigrationBatch();
            Artisan::call('migrate', ['--force' => true]);
            $notes[] = trim(Artisan::output()) ?: 'Database up to date.';
            if (($after = $this->maxMigrationBatch()) > $before) {
                $this->updateManifest($state['backup'], ['migration_batch' => $after]);
            }
        } finally {
            try {
                Artisan::call('optimize:clear');
            } catch (Throwable) {
                $this->clearCompiledFiles();
            }
            $this->bringOnline();
        }

        $this->forgetState();
        $this->pruneBackups();
        Cache::forget('selfupdate.git');
        $now = $this->commitInfo('HEAD');

        return ['message' => 'Updated to '.$now['short'].' ('.$now['subject'].').', 'version' => $now['short'], 'details' => $notes];
    }

    /** Run a step by name (the page calls them one by one). */
    public function step(string $step, array $options = []): array
    {
        return $step === 'fetch' ? $this->fetch_step((bool) ($options['discard'] ?? false)) : $this->{$step}();
    }

    /* ------------------------------------------------------------------ */
    /* Rollback, state, backups                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Back to the commit a backup was taken at: undo the database changes of every later update
     * (newest first, while the newest code with all their migrations is still here), then move the
     * files back and reinstall packages if they differ.
     */
    public function rollbackTo(string $id): array
    {
        $this->prepareLongStep();
        if ($this->readState()) {
            throw new RuntimeException('An update is in progress. Finish or cancel it first.');
        }
        $target = collect($this->backups())->firstWhere('id', $this->backupId($id)) ?? throw new RuntimeException('Backup not found.');
        $chain = collect($this->backups())->filter(fn ($b) => strcmp($b['id'], $target['id']) >= 0)->sortByDesc('id')->values();
        $current = $this->git(['rev-parse', 'HEAD']);

        Artisan::call('down', ['--retry' => 30]);
        $details = [];
        try {
            foreach ($chain as $backup) {
                $manifest = $this->readJson($this->backupDir($backup['id']).'/manifest.json');
                if (! empty($manifest['migration_batch'])) {
                    try {
                        Artisan::call('migrate:rollback', ['--batch' => (int) $manifest['migration_batch'], '--force' => true]);
                    } catch (Throwable $e) {
                        rescue(fn () => Artisan::call('migrate', ['--force' => true]), report: false);
                        throw new RuntimeException('Undoing the database changes of the update to '.substr($manifest['to'], 0, 7).' failed, so nothing was rolled back: '.$e->getMessage());
                    }
                    $details[] = 'Undid the database changes of the update to '.substr($manifest['to'], 0, 7).'.';
                }
            }

            $depsDiffer = trim($this->git(['diff', '--name-only', $target['commit'], $current, '--', 'composer.json', 'composer.lock'])) !== '';
            $this->git(['reset', '-q', '--hard', $target['commit']]);
            if ($depsDiffer) {
                $this->composerInstall();
                $details[] = 'Reinstalled the PHP packages of that version.';
            }
        } finally {
            $this->clearCompiledFiles();
            $this->resetOpcache();
            $this->bringOnline();
        }

        foreach ($chain as $backup) {
            $this->delete($this->backupDir($backup['id']));
        }
        Cache::forget('selfupdate.git');
        $details[] = 'Files back at '.substr($target['commit'], 0, 7).'.';

        return ['message' => 'Rolled back to '.substr($target['commit'], 0, 7).($target['subject'] ? ' ('.$target['subject'].')' : '').'.', 'details' => $details];
    }

    /** An update that was started but not finished. */
    public function pending(): ?array
    {
        $state = $this->readState();

        return $state ? ['step' => $state['step'], 'from' => substr($state['from'], 0, 7), 'to' => substr($state['to'], 0, 7), 'started_at' => $state['started_at'] ?? null] : null;
    }

    /** Throw away a half-done update (before the files changed). */
    public function cancel(): void
    {
        $state = $this->readState();
        if (in_array($state['step'] ?? null, ['pull', 'dependencies'], true)) {
            throw new RuntimeException('The new files are already in place. Continue the update instead (or roll back afterwards).');
        }
        if (! empty($state['backup'])) {
            $this->delete($this->backupDir($state['backup']));
        }
        $this->forgetState();
    }

    public function bringOnline(): void
    {
        @unlink(storage_path('framework/down'));
        @unlink(storage_path('framework/maintenance.php'));
    }

    public function isDown(): bool
    {
        return is_file(storage_path('framework/down'));
    }

    /** Previous versions to roll back to, newest first. */
    public function backups(): array
    {
        return collect(glob($this->workPath.'/backups/*', GLOB_ONLYDIR) ?: [])
            ->map(function ($dir) {
                $m = $this->readJson($dir.'/manifest.json');
                $db = $dir.'/database.sql.gz';

                return empty($m['from']) ? null : [
                    'id' => basename($dir),
                    'commit' => $m['from'],
                    'short' => substr($m['from'], 0, 7),
                    'subject' => $m['from_subject'] ?? null,
                    'replaced_by' => isset($m['to']) ? substr($m['to'], 0, 7) : null,
                    'created_at' => $m['created_at'] ?? null,
                    'database_size' => is_file($db) ? filesize($db) : null,
                ];
            })
            ->filter()->sortByDesc('id')->values()->all();
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
    /* Git, PHP and Composer                                               */
    /* ------------------------------------------------------------------ */

    protected function fetch(): void
    {
        $source = $this->source();
        $this->git(['fetch', '-q', '--prune', 'origin', '+refs/heads/'.$source['branch'].':refs/remotes/origin/'.$source['branch']], auth: true, timeout: 300);
    }

    /** Run git in the app folder and return its trimmed output. */
    public function git(array $args, bool $auth = false, int $timeout = 120, bool $allowFailure = false): string
    {
        $binary = $this->gitBinary() ?? throw new RuntimeException('Git was not found on this server.');
        $config = ['-c', 'safe.directory='.str_replace('\\', '/', $this->basePath), '-c', 'credential.helper='];
        $token = $this->source()['token'];
        if ($auth && $token) {
            $config = [...$config, '-c', 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.$token)];
        }

        $process = new Process([$binary, ...$config, ...$args], $this->basePath, ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '', 'HOME' => $this->homeDir()], null, $timeout);
        $process->run();
        if (! $process->isSuccessful() && ! $allowFailure) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'git '.($args[0] ?? '').' failed.';
            throw new RuntimeException($token ? str_replace($token, '***', $message) : $message);
        }

        return rtrim($process->getOutput());
    }

    protected function commitInfo(string $ref): array
    {
        [$hash, $date, $subject] = array_pad(explode("\x1f", $this->git(['log', '-1', '--pretty=format:%H%x1f%aI%x1f%s', $ref])), 3, '');

        return ['hash' => $hash, 'short' => substr($hash, 0, 7), 'date' => $date, 'subject' => $subject];
    }

    public function gitBinary(): ?string
    {
        static $found = [];
        $configured = (string) config('selfupdate.git_binary');
        $key = $configured ?: 'auto';
        if (array_key_exists($key, $found)) {
            return $found[$key];
        }
        $candidates = $configured ? [$configured] : ['git', '/usr/local/cpanel/3rdparty/bin/git', '/usr/bin/git', '/usr/local/bin/git'];

        return $found[$key] = collect($candidates)->first(fn ($c) => $this->runs([$c, '--version'], 'git version'));
    }

    /** A PHP command-line program (the web server's PHP binary is often not one). */
    public function phpBinary(): ?string
    {
        static $found = [];
        $configured = (string) config('selfupdate.php_binary');
        $key = $configured ?: 'auto';
        if (array_key_exists($key, $found)) {
            return $found[$key];
        }
        $version = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;
        $candidates = $configured ? [$configured] : array_values(array_unique(array_filter([
            preg_match('~(^|[/\\\\])php[0-9.]*(\.exe)?$~i', PHP_BINARY) ? PHP_BINARY : null,
            "/opt/cpanel/ea-php{$version}/root/usr/bin/php",
            '/usr/local/bin/php', '/usr/bin/php', 'php',
        ])));

        return $found[$key] = collect($candidates)->first(fn ($c) => $this->runs([$c, '-v'], 'PHP '.PHP_MAJOR_VERSION));
    }

    /** ['command' => [...], 'label' => …] or null; downloads composer.phar into storage when allowed. */
    public function composerCommand(bool $download = true): ?array
    {
        $php = $this->phpBinary();
        $configured = (string) config('selfupdate.composer_binary');
        $local = storage_path('app/composer/composer.phar');
        $candidates = $configured ? [$configured] : ['/opt/cpanel/composer/bin/composer', '/usr/local/bin/composer', '/usr/bin/composer', $this->basePath.'/composer.phar', $local];

        foreach ($candidates as $c) {
            if ($php && is_file($c) && $this->runs([$php, $c, '--version', '--no-interaction'], 'Composer')) {
                return ['command' => [$php, $c], 'label' => $c];
            }
        }
        if (! $configured && $this->runs(['composer', '--version', '--no-interaction'], 'Composer')) {
            return ['command' => ['composer'], 'label' => 'composer'];
        }
        if ($download && $php) {
            $this->downloadComposer($local);

            return ['command' => [$php, $local], 'label' => 'downloaded'];
        }

        return null;
    }

    protected function composerInstall(): void
    {
        $composer = $this->composerCommand() ?? throw new RuntimeException('Composer is not available and could not be downloaded.');
        $home = storage_path('app/composer');
        $this->ensureDir($home);
        $process = new Process([...$composer['command'], 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts'], $this->basePath,
            ['COMPOSER_HOME' => $home, 'HOME' => $this->homeDir(), 'COMPOSER_MEMORY_LIMIT' => '-1', 'COMPOSER_NO_INTERACTION' => '1'], null, 900);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(Str::limit(trim($process->getErrorOutput() ?: $process->getOutput()), 600));
        }
        // --no-scripts skipped Laravel's package discovery; do it now with the new packages.
        @unlink($this->basePath.'/bootstrap/cache/packages.php');
        @unlink($this->basePath.'/bootstrap/cache/services.php');
    }

    /** composer.phar from getcomposer.org, checked against its published SHA-256. */
    protected function downloadComposer(string $target): void
    {
        $this->ensureDir(dirname($target));
        $phar = Http::timeout(120)->get('https://getcomposer.org/download/latest-stable/composer.phar');
        $sum = Http::timeout(30)->get('https://getcomposer.org/download/latest-stable/composer.phar.sha256');
        $expected = preg_match('/\b([a-f0-9]{64})\b/i', (string) $sum->body(), $m) ? strtolower($m[1]) : null;
        if (! $phar->successful() || ! $expected || ! hash_equals($expected, hash('sha256', $phar->body()))) {
            throw new RuntimeException('Composer could not be downloaded safely. Set CENTRAL_COMPOSER_BINARY in .env, or ask your host to install Composer.');
        }
        file_put_contents($target, $phar->body());
    }

    protected function runs(array $command, string $expect): bool
    {
        try {
            $p = new Process($command, $this->basePath, ['HOME' => $this->homeDir()], null, 20);
            $p->run();

            return $p->isSuccessful() && str_contains($p->getOutput().$p->getErrorOutput(), $expect);
        } catch (Throwable) {
            return false;
        }
    }

    protected function homeDir(): string
    {
        return getenv('HOME') ?: storage_path('app');
    }

    protected function friendly(string $message): string
    {
        return match (true) {
            str_contains($message, 'Authentication failed'), str_contains($message, 'could not read Username'), str_contains($message, '403') => 'GitHub refused access. Add (or renew) the access token under "Update source"; a private repository needs one.',
            str_contains($message, 'not found'), str_contains($message, "couldn't find remote ref") => 'The repository or branch was not found. Check "Update source".',
            str_contains($message, 'Could not resolve host') => 'Could not reach GitHub. Check the server\'s internet connection and try again.',
            default => Str::limit($message, 400),
        };
    }

    /* ------------------------------------------------------------------ */
    /* Housekeeping                                                        */
    /* ------------------------------------------------------------------ */

    protected function maxMigrationBatch(): int
    {
        try {
            return (int) DB::table('migrations')->max('batch');
        } catch (Throwable) {
            return 0;
        }
    }

    protected function updateManifest(string $id, array $data): void
    {
        $file = $this->backupDir($id).'/manifest.json';
        $this->writeJson($file, [...$this->readJson($file), ...$data]);
    }

    protected function pruneBackups(): void
    {
        $keep = max(2, (int) config('selfupdate.keep_backups'));
        collect(glob($this->workPath.'/backups/*', GLOB_ONLYDIR) ?: [])->sortDesc()->values()->slice($keep)->each(fn ($dir) => $this->delete($dir));
    }

    protected function backupId(string $id): string
    {
        return basename($this->backupDir($id));
    }

    protected function backupDir(string $id): string
    {
        if (! preg_match('~^[\w.-]+$~', $id) || ! is_dir($dir = $this->workPath.'/backups/'.$id)) {
            throw new RuntimeException('Backup not found.');
        }

        return $dir;
    }

    protected function statePath(): string
    {
        return $this->workPath.'/git-state.json';
    }

    protected function readState(): ?array
    {
        $state = $this->readJson($this->statePath());

        return $state ?: null;
    }

    protected function writeState(array $state): void
    {
        $this->ensureDir($this->workPath);
        $this->writeJson($this->statePath(), $state);
    }

    protected function forgetState(): void
    {
        @unlink($this->statePath());
    }

    protected function requireState(string $previous): array
    {
        $state = $this->readState();
        if (($state['step'] ?? null) !== $previous) {
            throw new RuntimeException('The update steps ran out of order. Please start the update again.');
        }

        return $state;
    }

    protected function readJson(string $file): array
    {
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    protected function writeJson(string $file, array $data): void
    {
        $this->ensureDir(dirname($file));
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
    }

    protected function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            $this->delete($item->getPathname());
        }
        @rmdir($path);
    }

    protected function prepareLongStep(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');
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
}
