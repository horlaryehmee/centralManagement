<?php

use App\Models\Client;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\ReleaseService;
use App\Services\Signer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('central:keys', function (Signer $signer) {
    if ($signer->hasKey()) {
        $this->info('Signing key already exists (it is never replaced automatically).');
    } else {
        $signer->generate();
        $this->info('Signing key created at '.config('central.signing_key_path'));
        $this->warn('Back this file up safely. Installations trust this key; if it is lost, every site must reconnect.');
    }
    $this->line('Key ID:      '.$signer->keyId());
    $this->line('Fingerprint: '.$signer->fingerprint());
})->purpose('Create the Ed25519 key that signs release packages');

Artisan::command('central:admin {email} {--name=Administrator} {--password=}', function () {
    $password = $this->option('password') ?: $this->secret('Password (min 12 characters)');
    if (strlen((string) $password) < 12) {
        return $this->error('The password must be at least 12 characters.');
    }
    $user = User::query()->updateOrCreate(['email' => strtolower($this->argument('email'))], ['name' => $this->option('name'), 'password' => Hash::make($password)]);
    $this->info("Administrator {$user->email} saved.");
})->purpose('Create or update a central administrator');

Artisan::command('central:import-release {package} {--client= : Client key or ID (extensions)} {--notes=} {--core= : Required core constraint} {--php=} {--from-min=} {--from-max=} {--db-rollback : Migrations can be rolled back} {--publish}', function (ReleaseService $releases) {
    $path = $this->argument('package');
    if (! is_file($path)) {
        return $this->error("File not found: {$path}");
    }
    $client = $this->option('client') ? Client::query()->where('key', $this->option('client'))->orWhere('id', $this->option('client'))->first() : null;

    try {
        $release = $releases->createFromPackage($path, [
            'client_id' => $client?->id,
            'notes' => $this->option('notes'),
            'requires' => array_filter(['core' => $this->option('core'), 'php' => $this->option('php')]) ?: null,
            'upgrade_from_min' => $this->option('from-min'),
            'upgrade_from_max' => $this->option('from-max'),
            'database_rollback' => $this->option('db-rollback'),
        ]);
        if ($this->option('publish')) {
            $releases->publish($release);
        }
    } catch (ValidationException $e) {
        return $this->error(collect($e->errors())->flatten()->implode(' '));
    }
    $this->info("Imported {$release->label()} ({$release->type}, {$release->file_count} files) as {$release->fresh()->status}.");
})->purpose('Add a release from a package file (for CI or packages too large for browser upload)');

Artisan::command('central:tick', function (DeploymentService $deployments) {
    $r = $deployments->tick();
    if (array_sum($r)) {
        $this->info("Started {$r['started']}, retried {$r['retried']}, timed out {$r['timed_out']}.");
    }
})->purpose('Start scheduled deployments, release retries, time out silent installations');

Artisan::command('central:github-sync', function (\App\Services\GithubImporter $importer) {
    foreach (\App\Models\GithubSource::query()->where('auto_import', true)->get() as $source) {
        try {
            $r = $importer->sync($source);
            if ($r['imported'] || $r['failed']) {
                $this->line("{$source->repository}: imported ".(implode(', ', $r['imported']) ?: 'nothing').($r['failed'] ? '; failed: '.implode(' ', $r['failed']) : ''));
            }
        } catch (\Throwable $e) {
            $this->error("{$source->repository}: {$e->getMessage()}");
        }
    }
})->purpose('Import new GitHub releases from repositories set to auto-import');

// Cron on the central server: * * * * * php /path/to/artisan schedule:run
Schedule::command('central:github-sync')->hourly()->withoutOverlapping();
Schedule::command('central:tick')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => DB::table('api_nonces')->where('created_at', '<', now()->subHour())->delete())->hourly()->name('prune-nonces');
