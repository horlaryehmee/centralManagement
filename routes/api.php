<?php

use App\Http\Controllers\Api\InstallationApiController as Api;
use Illuminate\Support\Facades\Route;

/*
| Installation API v1 (see docs/API.md). HTTPS only. Every route except
| "register" needs a signed request from a connected installation.
*/
Route::prefix('v1')->middleware(['central.https', \App\Http\Middleware\CompressJson::class])->group(function () {
    Route::post('register', [Api::class, 'register'])->middleware('throttle:register');

    Route::middleware(['throttle:installation', 'central.installation'])->group(function () {
        Route::post('heartbeat', [Api::class, 'heartbeat']);
        Route::get('updates', [Api::class, 'updates']);
        Route::get('manifests/{type}/{slug}/{version}', [Api::class, 'manifest'])
            ->where(['type' => 'core|module|extension', 'slug' => '[a-z0-9-]+', 'version' => '[0-9.]+']);
        Route::post('deployments/{target}/accept', [Api::class, 'accept'])->whereNumber('target');
        Route::get('deployments/{target}/package', [Api::class, 'package'])->whereNumber('target');
        Route::post('deployments/{target}/progress', [Api::class, 'progress'])->whereNumber('target');
        Route::post('deployments/{target}/complete', [Api::class, 'complete'])->whereNumber('target');

        // Website backups requested from central (docs/API.md → Backups).
        Route::post('backups/{backup}/accept', [\App\Http\Controllers\Api\BackupApiController::class, 'accept'])->whereNumber('backup');
        Route::post('backups/{backup}/chunk', [\App\Http\Controllers\Api\BackupApiController::class, 'chunk'])->whereNumber('backup');
        Route::post('backups/{backup}/complete', [\App\Http\Controllers\Api\BackupApiController::class, 'complete'])->whereNumber('backup');
    });
});
