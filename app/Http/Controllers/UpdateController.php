<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\SystemSetting;
use App\Services\SelfUpdate\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Updates (of this central app). The page runs the update one step at a time
 * (POST /updates/step/{step}), each its own short request; these routes stay
 * reachable while the app is in maintenance mode (bootstrap/app.php).
 */
class UpdateController extends Controller
{
    public function __construct(protected Updater $updater) {}

    public function index(Request $request): Response
    {
        $status = $this->updater->check($request->boolean('refresh'));
        $settings = SystemSetting::current();

        return Inertia::render('Updates', [
            'status' => $status,
            'checks' => $this->updater->preflight($status['asset']['size'] ?? null),
            'pending' => $this->updater->pending(),
            'down' => $this->updater->isDown(),
            'backups' => $this->updater->backups(),
            'steps' => Updater::STEPS,
            'source' => [
                'repository' => $settings->update_repository ?? '',
                'has_token' => filled($settings->update_token),
                'env_repository' => config('selfupdate.repository'),
                'env_token' => filled(config('selfupdate.token')),
                'effective' => $this->updater->source()['repository'],
            ],
        ]);
    }

    public function step(string $step): JsonResponse
    {
        abort_unless(in_array($step, Updater::STEPS, true), 404);

        $lock = Cache::lock('selfupdate.step', 900);
        if (! $lock->get()) {
            return response()->json(['message' => 'Another update step is still running. Please wait a moment.'], 409);
        }

        try {
            $result = $this->updater->{$step}();
            if ($step === 'finish') {
                Event::log('central.updated', $result['message']);
            }

            return response()->json($result);
        } catch (Throwable $e) {
            report($e);
            if ($step !== 'download') {
                Event::log('central.update_failed', 'Update step "'.$step.'" failed: '.$e->getMessage(), ['level' => 'warning']);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
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

    /** One-click rollback: undo later updates' database changes, then put the previous files back. */
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
            'token' => ['nullable', 'string', 'max:500'],
            'remove_token' => ['boolean'],
        ]);

        $repository = filled($data['repository'] ?? null) ? Updater::cleanRepository($data['repository']) : null;
        if (filled($data['repository'] ?? null) && ! $repository) {
            return back()->withErrors(['repository' => 'Enter the repository as owner/name, e.g. my-company/central-management.']);
        }

        $settings = SystemSetting::current();
        $settings->update_repository = $repository;
        if (filled($data['token'] ?? null)) {
            $settings->update_token = trim($data['token']);
        } elseif ($request->boolean('remove_token')) {
            $settings->update_token = null;
        }
        $settings->save();
        Cache::forget('selfupdate.latest');
        Event::log('central.update_source', 'Update source saved'.($repository ? " ({$repository})." : '.'));

        return redirect()->route('updates.index', ['refresh' => 1])->with('success', 'Update source saved.');
    }
}
