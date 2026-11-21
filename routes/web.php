<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\ReleaseController;
use Illuminate\Support\Facades\Route;

// Setup wizard for a fresh upload (closed once installed; see EnsureInstalled).
Route::prefix('install')->middleware('throttle:30,1')->group(function () {
    Route::get('/', [\App\Http\Controllers\InstallController::class, 'welcome']);
    Route::get('database', [\App\Http\Controllers\InstallController::class, 'database']);
    Route::post('database', [\App\Http\Controllers\InstallController::class, 'saveDatabase']);
    Route::get('site', [\App\Http\Controllers\InstallController::class, 'site']);
    Route::post('finish', [\App\Http\Controllers\InstallController::class, 'finish']);
});

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('clients', ClientController::class)->except('destroy');
    Route::patch('clients/{client}/status', [ClientController::class, 'status'])->name('clients.status');

    Route::resource('installations', InstallationController::class)->except('destroy');
    Route::patch('installations/{installation}/status', [InstallationController::class, 'status'])->name('installations.status');
    Route::post('installations/{installation}/enrollment', [InstallationController::class, 'enrollment'])->name('installations.enrollment');

    Route::get('github', [\App\Http\Controllers\GithubController::class, 'index'])->name('github.index');
    Route::post('github', [\App\Http\Controllers\GithubController::class, 'store'])->name('github.store');
    Route::put('github/{source}', [\App\Http\Controllers\GithubController::class, 'update'])->name('github.update');
    Route::delete('github/{source}', [\App\Http\Controllers\GithubController::class, 'destroy'])->name('github.destroy');
    Route::post('github/{source}/sync', [\App\Http\Controllers\GithubController::class, 'sync'])->name('github.sync');
    Route::post('github/{source}/import/{githubRelease}', [\App\Http\Controllers\GithubController::class, 'import'])->whereNumber('githubRelease')->name('github.import');

    Route::resource('releases', ReleaseController::class)->except('edit');
    Route::post('releases/{release}/publish', [ReleaseController::class, 'publish'])->name('releases.publish');
    Route::post('releases/{release}/withdraw', [ReleaseController::class, 'withdraw'])->name('releases.withdraw');

    Route::get('deployments', [DeploymentController::class, 'index'])->name('deployments.index');
    Route::get('deployments/create', [DeploymentController::class, 'create'])->name('deployments.create');
    Route::match(['get', 'post'], 'deployments/preview', [DeploymentController::class, 'preview'])->name('deployments.preview');
    Route::post('deployments', [DeploymentController::class, 'store'])->name('deployments.store');
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
    Route::post('deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');
    Route::post('deployments/{deployment}/retry', [DeploymentController::class, 'retry'])->name('deployments.retry');
    Route::post('targets/{target}/rollback', [DeploymentController::class, 'rollback'])->name('targets.rollback');
});
