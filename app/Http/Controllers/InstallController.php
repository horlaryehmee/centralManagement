<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Signer;
use App\Support\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/** Setup wizard for a fresh upload of the central app (see EnsureInstalled). */
class InstallController extends Controller
{
    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'fileinfo', 'curl', 'zip', 'sodium'];

    public function welcome(): View
    {
        $checks = Installer::requirements(base_path(), self::EXTENSIONS);

        return view('install.welcome', ['checks' => $checks, 'ready' => collect($checks)->every('ok'), 'app' => 'your update server']);
    }

    public function database(): View
    {
        return view('install.database', ['db' => [
            'host' => in_array(env('DB_HOST'), [null, '127.0.0.1'], true) ? 'localhost' : env('DB_HOST'),
            'port' => env('DB_PORT', 3306), 'database' => env('DB_DATABASE'), 'username' => env('DB_USERNAME'),
        ]]);
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        $db = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
        if ($error = Installer::testDatabase($db)) {
            return back()->withInput($request->except('password'))->withErrors(['database' => $error]);
        }
        Installer::markInstalling(base_path());
        Installer::writeEnv(base_path(), [
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'],
            'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'] ?? '',
        ]);

        return redirect()->to(Installer::detectUrl().'/install/site');
    }

    public function site(): View|RedirectResponse
    {
        if (! env('DB_DATABASE')) {
            return redirect()->to(Installer::detectUrl().'/install/database');
        }

        return view('install.site', ['url' => Installer::detectUrl(), 'kind' => 'update server', 'siteName' => 'Central Management', 'minPassword' => 'At least 12 characters.']);
    }

    public function finish(Request $request, Signer $signer): View|RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        @set_time_limit(300);
        $url = rtrim($data['url'], '/');
        $https = str_starts_with($url, 'https://');
        $steps = [];

        try {
            Installer::useDatabase(['host' => env('DB_HOST'), 'port' => env('DB_PORT'), 'database' => env('DB_DATABASE'), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD')]);
            Artisan::call('migrate', ['--force' => true]);
            $steps[] = 'Created the database tables.';

            if (! $signer->hasKey()) {
                $signer->generate();
            }
            $steps[] = 'Created the package signing key (fingerprint '.$signer->fingerprint().').';

            User::query()->updateOrCreate(['email' => strtolower($data['email'])], ['name' => $data['name'], 'password' => Hash::make($data['password'])]);
            $steps[] = 'Created your administrator account.';

            Installer::writeEnv(base_path(), [
                'APP_NAME' => $data['site_name'], 'APP_URL' => $url, 'APP_ENV' => 'production', 'APP_DEBUG' => false,
                'SESSION_DRIVER' => 'database', 'SESSION_SECURE_COOKIE' => $https, 'CACHE_STORE' => 'file',
                'CENTRAL_ALLOW_INSECURE_API' => false,
            ]);
            Artisan::call('optimize:clear');
            Installer::markInstalled(base_path(), ['app' => 'central', 'url' => $url]);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['install' => 'Installation stopped: '.Str::limit($e->getMessage(), 300).' Fix the problem and press Install again (it is safe to repeat).']);
        }

        return view('install.done', [
            'steps' => $steps, 'url' => $url, 'admin' => $url, 'https' => $https,
            'cron' => '* * * * * php '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
            'backup' => 'Download a copy of storage/app/private/signing/ed25519.key (cPanel → File Manager) and keep it safe. Websites trust this key; if it is lost they must all reconnect.',
        ]);
    }
}
