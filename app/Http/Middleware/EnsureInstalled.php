<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before installation every page goes to the setup wizard (/install);
 * afterwards the wizard is closed for good.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        $installed = Installer::isInstalled(base_path());
        $wizard = $request->is('install', 'install/*');

        if (! $installed && ! $wizard && ! $request->is('build/*', 'up')) {
            return redirect()->to(Installer::detectUrl().'/install');
        }
        if ($installed && $wizard) {
            return redirect('/');
        }

        return $next($request);
    }
}
