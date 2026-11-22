<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\SystemSetting;
use App\Services\SelfUpdate\GitUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Updates (of this central app), straight from its GitHub repository. The page runs the
 * update one step at a time (POST /updates/step/{step}); these routes stay reachable while
 * the app is in maintenance mode (bootstrap/app.php).
 */
class UpdateController extends Controller
{
    public function __construct(protected GitUpdater $updater) {}

    public function index(Request $request): Response
    {
        $source = $this->updater->source();
        $settings = SystemSetting::current();

        return Inertia::render('Updates', [
            'status' => $this->updater->check($request->boolean('refresh')),
            'connected' => $this->updater->isRepository(),
            'justConnected' => $this->updater->justConnected(),
            'checks' => $this->updater->preflight(),
            'pending' => $this->updater->pending(),
            'down' => $this->updater->isDown(),
            'backups' => $this->updater->backups(),
            'steps' => GitUpdater::STEPS,
            'source' => [
                'repository' => $settings->update_repository ?? '',
                'branch' => $settings->update_branch ?? '',
                'has_token' => filled($settings->update_token),
                'env_repository' => config('selfupdate.repository'),
                'env_branch' => config('selfupdate.branch'),
                'env_token' => filled(config('selfupdate.token')),
                'effective' => $source['repository'],
                'effective_branch' => $source['branch'],
            ],
        ]);
    }

    public function step(Request $request, string $step): JsonResponse
    {
        abort_unless(in_array($step, GitUpdater::STEPS, true), 404);

        $lock = Cache::lock('selfupdate.step', 900);
        if (! $lock->get()) {
            return response()->json(['message' => 'Another update step is still running. Please wait a moment.'], 409);
        }

        try {
            $result = $this->updater->step($step, ['discard' => $request->boolean('discard')]);
            if ($step === 'finish') {
                Event::log('central.updated', $result['message']);
            }

            return response()->json($result);
        } catch (Throwable $e) {
            report($e);
            if ($step !== 'fetch') {
                Event::log('central.update_failed', 'Update step "'.$step.'" failed: '.$e->getMessage(), ['level' => 'warning']);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
    }

    /** Make this folder a Git working copy of the repository (installs made from the zip). */
    public function connect(): RedirectResponse
    {
        try {
            $result = $this->updater->connect();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not connect to GitHub: '.$e->getMessage());
        }
        Event::log('central.update_source', $result['message']);

        return redirect()->route('updates.index', ['refresh' => 1])->with('success', $result['message'].' Press "Update now" to bring the files to the latest version.');
    }

    public function cancel(): RedirectResponse
    {
        try {
            $this->updater->cancel();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The unfinished update was cancelled.');
    }

    public function online(): RedirectResponse
    {
        $this->updater->bringOnline();

        return back()->with('success', 'Central Management is back online.');
    }

    /** One-click rollback to the commit a backup was taken at (later migrations undone first). */
    public function rollback(string $backup): JsonResponse
    {
        try {
            $result = $this->updater->rollbackTo($backup);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        Event::log('central.rolled_back', $result['message'], ['level' => 'warning']);

        return response()->json($result);
    }

    public function database(string $backup): BinaryFileResponse
    {
        try {
            $path = $this->updater->databaseBackupPath($backup);
        } catch (Throwable) {
            abort(404);
        }

        return response()->download($path, 'central-database-backup-'.$backup.'.sql.gz', ['Cache-Control' => 'private, no-store']);
    }

    public function source(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'repository' => ['nullable', 'string', 'max:200'],
            'branch' => ['nullable', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'max:500'],
            'remove_token' => ['boolean'],
        ]);

        $repository = filled($data['repository'] ?? null) ? GitUpdater::cleanRepository($data['repository']) : null;
        if (filled($data['repository'] ?? null) && ! $repository) {
            return back()->withErrors(['repository' => 'Enter the repository as owner/name, e.g. my-company/central-management.']);
        }
        $branch = filled($data['branch'] ?? null) ? GitUpdater::cleanBranch($data['branch']) : null;
        if (filled($data['branch'] ?? null) && ! $branch) {
            return back()->withErrors(['branch' => 'Enter a branch name, e.g. main.']);
        }

        $settings = SystemSetting::current();
        $settings->update_repository = $repository;
        $settings->update_branch = $branch;
        if (filled($data['token'] ?? null)) {
            $settings->update_token = trim($data['token']);
        } elseif ($request->boolean('remove_token')) {
            $settings->update_token = null;
        }
        $settings->save();
        Cache::forget('selfupdate.git');
        Event::log('central.update_source', 'Update source saved'.($repository ? " ({$repository}".($branch ? ", {$branch}" : '').')' : '').'.');

        return redirect()->route('updates.index', ['refresh' => 1])->with('success', 'Update source saved.');
    }
}
