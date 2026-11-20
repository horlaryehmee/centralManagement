<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Event;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deployment lifecycle. Central never marks an update as done by itself:
 * a target moves to "succeeded" (or "failed", "rolled back"…) only when the
 * installation reports that result. Targets the installation never reports on
 * become "no_response", not "succeeded".
 *
 *   waiting_canary → queued → delivered → running → succeeded | failed
 *                                     ↘ retry_wait → queued (retryable failures)
 *   succeeded → rollback_requested → rolling_back → rolled_back | rollback_failed
 */
class DeploymentService
{
    public function __construct(protected Compatibility $compatibility) {}

    /** Per-installation compatibility for the confirmation screen. */
    public function preview(Release $release, Collection $installations): Collection
    {
        return $installations->map(fn (Installation $i) => ['installation' => $i] + $this->compatibility->check($release, $i));
    }

    /**
     * @param  array{scheduled_at?: ?string, canary_installation_id?: ?int, max_attempts?: int,
     *               allow_modified_files?: bool, notes?: ?string, selection?: array}  $options
     */
    public function create(Release $release, array $installationIds, array $options, ?User $user = null): Deployment
    {
        if (! $release->isPublished()) {
            throw ValidationException::withMessages(['release' => 'Only published releases can be deployed.']);
        }

        $installations = Installation::query()->with('modules', 'client')->whereIn('id', $installationIds)->get();
        $checked = $this->preview($release, $installations);
        $eligible = $checked->where('ok', true)->pluck('installation');
        $skipped = $checked->where('ok', false);

        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages(['installations' => 'None of the selected installations can receive this release.']);
        }

        $canaryId = $options['canary_installation_id'] ?? null;
        if ($canaryId && ! $eligible->contains('id', (int) $canaryId)) {
            throw ValidationException::withMessages(['canary_installation_id' => 'The test installation must be one of the eligible installations.']);
        }

        $scheduledAt = filled($options['scheduled_at'] ?? null) ? Carbon::parse($options['scheduled_at']) : null;

        return DB::transaction(function () use ($release, $eligible, $skipped, $options, $canaryId, $scheduledAt, $user) {
            $deployment = Deployment::create([
                'release_id' => $release->id,
                'created_by' => $user?->id,
                'status' => $scheduledAt && $scheduledAt->isFuture() ? 'scheduled' : 'running',
                'scheduled_at' => $scheduledAt,
                'canary_installation_id' => $canaryId,
                'max_attempts' => max(1, min(10, (int) ($options['max_attempts'] ?? 3))),
                'allow_modified_files' => (bool) ($options['allow_modified_files'] ?? false),
                'notes' => $options['notes'] ?? null,
                'selection' => ($options['selection'] ?? []) + ['skipped' => $skipped->map(fn ($s) => ['id' => $s['installation']->id, 'name' => $s['installation']->name, 'reasons' => $s['reasons']])->values()->all()],
                'started_at' => $scheduledAt && $scheduledAt->isFuture() ? null : now(),
            ]);

            foreach ($eligible as $installation) {
                $isCanary = $canaryId && $installation->id === (int) $canaryId;
                $deployment->targets()->create([
                    'installation_id' => $installation->id,
                    'is_canary' => $isCanary,
                    'status' => $canaryId && ! $isCanary ? 'waiting_canary' : 'queued',
                    'from_version' => $this->currentVersion($release, $installation),
                    'to_version' => $release->version,
                ]);
            }

            Event::log('deployment.created', sprintf(
                '%s to %d installation(s)%s%s.',
                $release->label(),
                $eligible->count(),
                $scheduledAt && $scheduledAt->isFuture() ? ', scheduled for '.$scheduledAt->toDayDateTimeString() : '',
                $canaryId ? ', test installation first' : '',
            ), ['deployment' => $deployment, 'user' => $user, 'context' => ['skipped' => $skipped->count()]]);

            return $deployment;
        });
    }

    /* ------------------------------------------------------------------ */
    /* What an installation is asked to do                                 */
    /* ------------------------------------------------------------------ */

    /** At most one instruction at a time, oldest first: a rollback, or a deployment. */
    public function instructionsFor(Installation $installation): array
    {
        if (! $installation->canReceiveUpdates()) {
            return [];
        }

        // Something already handed over and unfinished: ask again (the agent resumes it).
        $current = $installation->targets()->whereIn('status', ['delivered', 'running', 'rolling_back'])->oldest('id')->first();
        if ($current) {
            return [$this->instruction($current)];
        }

        $rollback = $installation->targets()->where('status', 'rollback_requested')->oldest('id')->first();
        if ($rollback) {
            return [$this->instruction($rollback)];
        }

        $next = $installation->targets()
            ->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereHas('deployment', fn ($q) => $q->whereNull('cancelled_at')->where(fn ($w) => $w->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now())))
            ->whereHas('deployment.release', fn ($q) => $q->where('status', 'published'))
            ->oldest('id')->first();

        return $next ? [$this->instruction($next)] : [];
    }

    protected function instruction(DeploymentTarget $target): array
    {
        $release = $target->deployment->release;

        return [
            'type' => in_array($target->status, ['rollback_requested', 'rolling_back'], true) ? 'rollback' : 'deploy',
            'target' => $target->id,
            'release' => ['type' => $release->type, 'slug' => $release->slug, 'version' => $release->version],
        ];
    }

    /** The installation picks up a deploy instruction: re-check it and hand over the signed manifest. */
    public function accept(DeploymentTarget $target): array
    {
        // Status changes must be committed before an error is returned, so errors are thrown after the transaction.
        $result = DB::transaction(function () use ($target) {
            $target = DeploymentTarget::query()->lockForUpdate()->findOrFail($target->id);
            $deployment = $target->deployment;
            $release = $deployment->release;
            $installation = $target->installation()->with('modules', 'client')->first();

            if (! in_array($target->status, ['queued', 'delivered', 'running'], true)) {
                return new ApiException('not_deliverable', 'This update is '.$target->status.'.', 409);
            }
            if ($deployment->cancelled_at || ! $deployment->isDue()) {
                return new ApiException('not_deliverable', 'This update is not due.', 409);
            }
            if ($release->status !== 'published') {
                $this->finishTarget($target, 'cancelled', 'release_withdrawn', 'The release was withdrawn.');
                $this->refreshStatus($deployment);

                return new ApiException('release_withdrawn', 'The release was withdrawn.', 409);
            }

            if ($target->status === 'queued') {
                $check = $this->compatibility->check($release, $installation);
                if (! $check['ok']) {
                    $this->finishTarget($target, 'failed', 'incompatible', implode(' ', $check['reasons']));
                    $this->refreshStatus($deployment);

                    return new ApiException('incompatible', implode(' ', $check['reasons']), 409);
                }
                $target->update(['status' => 'delivered', 'attempts' => $target->attempts + 1, 'delivered_at' => now(), 'last_report_at' => now(), 'error' => null, 'error_code' => null, 'step' => null]);
                Event::log('target.delivered', "Delivered {$release->label()} (attempt {$target->attempts}).", ['target' => $target, 'user' => null]);
            }

            return [
                'target' => $target->id,
                'action' => 'deploy',
                'release' => [
                    'uuid' => $release->uuid, 'type' => $release->type, 'slug' => $release->slug,
                    'version' => $release->version, 'notes' => $release->notes,
                ],
                'manifest' => $release->manifest,
                'signature' => $release->signature,
                'key_id' => $release->signing_key_id,
                'download' => ['path' => "api/v1/deployments/{$target->id}/package", 'size' => $release->package_size, 'sha256' => $release->package_sha256],
                'options' => ['allow_modified_files' => $deployment->allow_modified_files],
            ];
        });

        if ($result instanceof ApiException) {
            throw $result;
        }

        return $result;
    }

    /** Signed manifest of the version this rollback should return to: nothing to download, files come from the site's own backup. */
    public function acceptRollback(DeploymentTarget $target): array
    {
        if (! in_array($target->status, ['rollback_requested', 'rolling_back'], true)) {
            throw new ApiException('not_deliverable', 'No rollback was requested for this update.', 409);
        }
        $target->update(['status' => 'rolling_back', 'last_report_at' => now()]);
        $release = $target->deployment->release;

        return [
            'target' => $target->id,
            'action' => 'rollback',
            'release' => ['uuid' => $release->uuid, 'type' => $release->type, 'slug' => $release->slug, 'version' => $release->version],
            'manifest' => $release->manifest,
            'signature' => $release->signature,
            'key_id' => $release->signing_key_id,
            'rollback_database' => $release->database_rollback,
        ];
    }

    public function progress(DeploymentTarget $target, string $step, ?string $message): void
    {
        if (! in_array($target->status, DeploymentTarget::IN_FLIGHT, true)) {
            throw new ApiException('not_in_progress', 'This update is not in progress.', 409);
        }
        $target->update([
            'status' => $target->status === 'delivered' ? 'running' : $target->status,
            'step' => mb_substr($step, 0, 40),
            'last_report_at' => now(),
        ]);
        Event::log('target.progress', $message ? mb_substr($message, 0, 500) : "Step: {$step}", ['target' => $target, 'user' => null, 'context' => ['step' => $step]]);
    }

    /**
     * Final report from the installation.
     *
     * @param  array{result: string, error?: ?string, error_code?: ?string, retryable?: bool, version?: ?string, log?: array}  $report
     */
    public function complete(DeploymentTarget $target, array $report): DeploymentTarget
    {
        return DB::transaction(function () use ($target, $report) {
            $target = DeploymentTarget::query()->lockForUpdate()->findOrFail($target->id);
            $result = $report['result'];
            $release = $target->deployment->release;
            $context = ['log' => array_slice((array) ($report['log'] ?? []), -50)];

            // Rollback results.
            if (in_array($target->status, ['rollback_requested', 'rolling_back'], true)) {
                if (! in_array($result, ['rolled_back', 'rollback_failed'], true)) {
                    throw new ApiException('invalid_result', 'Expected a rollback result.', 422);
                }
                $target->update(['status' => $result, 'error' => $report['error'] ?? null, 'error_code' => $report['error_code'] ?? null, 'last_report_at' => now(), 'finished_at' => now()]);
                if ($result === 'rolled_back') {
                    $this->recordVersion($target->installation, $release, $target->from_version);
                }
                Event::log("target.{$result}", $result === 'rolled_back' ? "Rolled back to {$target->from_version}." : 'Rollback failed: '.($report['error'] ?? 'unknown error'), ['target' => $target, 'user' => null, 'level' => $result === 'rolled_back' ? 'warning' : 'error', 'context' => $context]);
                $this->refreshStatus($target->deployment);

                return $target;
            }

            if (! in_array($target->status, DeploymentTarget::IN_FLIGHT, true)) {
                throw new ApiException('not_in_progress', 'This update is not in progress (status '.$target->status.').', 409);
            }

            if ($result === 'succeeded') {
                $target->update(['status' => 'succeeded', 'step' => 'done', 'error' => null, 'error_code' => null, 'last_report_at' => now(), 'finished_at' => now()]);
                $this->recordVersion($target->installation, $release, $report['version'] ?? $release->version);
                Event::log('target.succeeded', "Installed {$release->label()}.", ['target' => $target, 'user' => null, 'level' => 'success', 'context' => $context]);
                if ($target->is_canary) {
                    $released = $target->deployment->targets()->where('status', 'waiting_canary')->update(['status' => 'queued']);
                    Event::log('deployment.canary_passed', "Test installation succeeded; releasing to {$released} more installation(s).", ['deployment' => $target->deployment, 'user' => null, 'level' => 'success']);
                }
            } elseif ($result === 'failed') {
                $retryable = (bool) ($report['retryable'] ?? false);
                $error = mb_substr((string) ($report['error'] ?? 'Unknown error'), 0, 2000);
                if ($retryable && $target->attempts < $target->deployment->max_attempts) {
                    $backoff = config('central.retry_backoff');
                    $minutes = $backoff[min($target->attempts - 1, count($backoff) - 1)] ?? 30;
                    $target->update(['status' => 'retry_wait', 'next_attempt_at' => now()->addMinutes($minutes), 'error' => $error, 'error_code' => $report['error_code'] ?? null, 'last_report_at' => now()]);
                    Event::log('target.retry', "Failed ({$error}); retrying in {$minutes} min (attempt {$target->attempts} of {$target->deployment->max_attempts}).", ['target' => $target, 'user' => null, 'level' => 'warning', 'context' => $context]);
                } else {
                    $this->finishTarget($target, 'failed', $report['error_code'] ?? null, $error, $context);
                }
            } else {
                throw new ApiException('invalid_result', 'Unknown result.', 422);
            }

            $this->refreshStatus($target->deployment);

            return $target->fresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Admin actions                                                       */
    /* ------------------------------------------------------------------ */

    /** Cancel what hasn't been handed over yet. Updates already running finish (stopping them midway is not safe). */
    public function cancel(Deployment $deployment, ?User $user = null, string $reason = 'Cancelled'): int
    {
        if (in_array($deployment->status, Deployment::FINAL, true)) {
            return 0;
        }
        $count = $deployment->targets()->whereIn('status', DeploymentTarget::CANCELLABLE)
            ->update(['status' => 'cancelled', 'error' => $reason, 'error_code' => 'cancelled', 'finished_at' => now()]);
        $deployment->update(['cancelled_at' => now()]);
        Event::log('deployment.cancelled', "{$reason}: {$count} pending update(s) cancelled.", ['deployment' => $deployment, 'user' => $user, 'level' => 'warning']);
        $this->refreshStatus($deployment);

        return $count;
    }

    public function requestRollback(DeploymentTarget $target, ?User $user = null): void
    {
        if ($target->status !== 'succeeded') {
            throw ValidationException::withMessages(['target' => 'Only successful updates can be rolled back.']);
        }
        $newer = DeploymentTarget::query()->where('installation_id', $target->installation_id)->where('id', '>', $target->id)
            ->whereIn('status', ['succeeded', 'delivered', 'running'])
            ->whereHas('deployment.release', fn ($q) => $q->where('type', $target->deployment->release->type)->where('slug', $target->deployment->release->slug))
            ->exists();
        if ($newer) {
            throw ValidationException::withMessages(['target' => 'A newer update of the same component was installed afterwards; roll that back first.']);
        }
        $target->update(['status' => 'rollback_requested', 'finished_at' => null]);
        $target->deployment->update(['status' => 'running', 'finished_at' => null]);
        Event::log('target.rollback_requested', 'Rollback requested.', ['target' => $target, 'user' => $user, 'level' => 'warning']);
    }

    public function retryFailed(Deployment $deployment, ?User $user = null): int
    {
        $count = 0;
        foreach ($deployment->targets()->whereIn('status', ['failed', 'no_response'])->get() as $target) {
            if (! $this->compatibility->check($deployment->release, $target->installation)['ok']) {
                continue;
            }
            $target->update(['status' => 'queued', 'next_attempt_at' => null, 'finished_at' => null]);
            $count++;
        }
        if ($count) {
            $deployment->update(['status' => 'running', 'finished_at' => null, 'cancelled_at' => null]);
            Event::log('deployment.retry', "Retrying {$count} installation(s).", ['deployment' => $deployment, 'user' => $user]);
        }

        return $count;
    }

    /** Run every minute: start scheduled deployments, release due retries, time out silent targets. */
    public function tick(): array
    {
        $started = Deployment::query()->where('status', 'scheduled')->whereNull('cancelled_at')->where('scheduled_at', '<=', now())->get()
            ->each(fn ($d) => $d->update(['status' => 'running', 'started_at' => now()]))->count();

        $retried = DeploymentTarget::query()->where('status', 'retry_wait')->where('next_attempt_at', '<=', now())->update(['status' => 'queued']);

        $silent = DeploymentTarget::query()->whereIn('status', DeploymentTarget::IN_FLIGHT)
            ->where('last_report_at', '<', now()->subMinutes((int) config('central.no_response_after_minutes')))->get();
        foreach ($silent as $target) {
            $this->finishTarget($target, $target->status === 'rolling_back' || $target->status === 'rollback_requested' ? 'rollback_failed' : 'no_response', 'no_response', 'No report from the website. Check it directly; the update may or may not have been applied.');
        }

        Deployment::query()->whereNotIn('status', Deployment::FINAL)->get()->each(fn ($d) => $this->refreshStatus($d));

        return ['started' => $started, 'retried' => $retried, 'timed_out' => $silent->count()];
    }

    /* ------------------------------------------------------------------ */

    protected function finishTarget(DeploymentTarget $target, string $status, ?string $code, string $error, array $context = []): void
    {
        $target->update(['status' => $status, 'error_code' => $code, 'error' => $error, 'finished_at' => now(), 'last_report_at' => $target->last_report_at ?? now()]);
        Event::log("target.{$status}", ucfirst(str_replace('_', ' ', $status)).": {$error}", ['target' => $target, 'user' => null, 'level' => 'error', 'context' => $context ?: null]);

        // A failed test installation stops the rest of the deployment.
        if ($target->is_canary && in_array($status, ['failed', 'no_response', 'cancelled'], true)) {
            $n = $target->deployment->targets()->where('status', 'waiting_canary')
                ->update(['status' => 'cancelled', 'error_code' => 'canary_failed', 'error' => 'Not sent: the test installation failed.', 'finished_at' => now()]);
            if ($n) {
                Event::log('deployment.canary_failed', "Test installation failed; {$n} installation(s) were not updated.", ['deployment' => $target->deployment, 'user' => null, 'level' => 'error']);
            }
        }
    }

    public function refreshStatus(Deployment $deployment): void
    {
        $counts = $deployment->targets()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $open = $counts->except(DeploymentTarget::FINAL)->sum();

        if ($open > 0) {
            $status = $deployment->scheduled_at && $deployment->scheduled_at->isFuture() && ! $deployment->cancelled_at ? 'scheduled' : 'running';
            $deployment->update(['status' => $status, 'finished_at' => null]);

            return;
        }

        $ok = ($counts['succeeded'] ?? 0) + ($counts['rolled_back'] ?? 0);
        $bad = ($counts['failed'] ?? 0) + ($counts['no_response'] ?? 0) + ($counts['rollback_failed'] ?? 0);
        $status = match (true) {
            $deployment->cancelled_at !== null && $ok === 0 && $bad === 0 => 'cancelled',
            $bad === 0 && $ok > 0 => 'completed',
            $ok > 0 => 'completed_with_failures',
            $deployment->cancelled_at !== null && $bad === 0 => 'cancelled',
            default => 'failed',
        };
        if ($deployment->status !== $status) {
            $deployment->update(['status' => $status, 'finished_at' => now()]);
            Event::log('deployment.'.$status, 'Deployment '.str_replace('_', ' ', $status).'.', ['deployment' => $deployment, 'user' => null, 'level' => $status === 'completed' ? 'success' : ($status === 'cancelled' ? 'warning' : 'error')]);
        }
    }

    protected function currentVersion(Release $release, Installation $installation): ?string
    {
        return $release->type === 'core' ? $installation->core_version : $installation->modules->firstWhere('slug', $release->slug)?->version;
    }

    /** Keep the reported version in sync right away (the next heartbeat confirms it). */
    protected function recordVersion(Installation $installation, Release $release, ?string $version): void
    {
        if (! $version) {
            // Rolling back a first-time module install removes the module.
            if ($release->type !== 'core') {
                InstallationModule::query()->where('installation_id', $installation->id)->where('slug', $release->slug)->delete();
            }

            return;
        }
        if ($release->type === 'core') {
            $installation->update(['core_version' => $version]);

            return;
        }
        InstallationModule::query()->updateOrCreate(
            ['installation_id' => $installation->id, 'slug' => $release->slug],
            ['type' => $release->type, 'version' => $version, 'name' => $release->name]
        );
    }
}
