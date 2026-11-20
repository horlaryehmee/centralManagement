<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Registration: few attempts per IP (codes can't be guessed, but don't let anyone try).
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));

        // Signed installation calls: per installation, enough for downloads and progress reports.
        RateLimiter::for('installation', fn (Request $r) => Limit::perMinute(120)->by('inst:'.($r->header('X-Installation-Id') ?: $r->ip())));

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
    }
}
