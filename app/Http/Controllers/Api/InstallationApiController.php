<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeploymentTarget;
use App\Models\Event;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Models\Release;
use App\Services\ApiException;
use App\Services\Compatibility;
use App\Services\DeploymentService;
use App\Services\Signer;
use App\Support\VersionConstraint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API used by the update agent inside each ecommerce installation (v1).
 * All calls are outgoing from the website; see docs/API.md for the contract.
 * Every lookup is scoped to the authenticated installation.
 */
class InstallationApiController extends Controller
{
    public function __construct(protected DeploymentService $deployments, protected Signer $signer) {}

    /** One-time registration with the enrollment code from the central admin. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'site_url' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:40'],
        ]);

        $installation = Installation::query()->with('client')->where('enrollment_code_hash', Installation::hashCode($data['code']))->first();
        if (! $installation || $installation->status !== 'pending') {
            throw new ApiException('invalid_code', 'This connection code is not valid. Ask for a new one.', 422);
        }
        if (! $installation->enrollment_expires_at || $installation->enrollment_expires_at->isPast()) {
            throw new ApiException('expired_code', 'This connection code has expired. Ask for a new one.', 422);
        }
        if (! $installation->client?->isActive()) {
            throw new ApiException('client_suspended', 'This account is suspended.', 403);
        }

        $reportedHost = strtolower((string) parse_url($data['site_url'], PHP_URL_HOST));
        $expectedHost = strtolower((string) (parse_url($installation->domain, PHP_URL_HOST) ?: $installation->domain));
        $secret = Str::random(64);

        $installation->forceFill([
            'secret' => $secret,
            'secret_issued_at' => now(),
            'enrollment_code_hash' => null,
            'enrollment_expires_at' => null,
            'status' => 'active',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'core_version' => $data['version'] ?? $installation->core_version,
        ])->save();

        Event::log('installation.registered', "Connected from {$data['site_url']}.", [
            'installation' => $installation, 'user' => null,
            'level' => $reportedHost && $expectedHost && $reportedHost !== $expectedHost ? 'warning' : 'success',
            'context' => $reportedHost !== $expectedHost ? ['expected_domain' => $expectedHost, 'reported' => $reportedHost] : null,
        ]);

        return response()->json([
            'installation_id' => $installation->uuid,
            'secret' => $secret,
            'client_key' => $installation->client->key,
            'signing' => ['key_id' => $this->signer->keyId(), 'public_key' => $this->signer->publicKey(), 'fingerprint' => $this->signer->fingerprint()],
            'check_interval' => $installation->check_interval,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Status report; the answer carries any pending instruction. */
    public function heartbeat(Request $request): JsonResponse
    {
        $installation = $this->installation($request);
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40'],
            'php' => ['nullable', 'string', 'max:40'],
            'framework' => ['nullable', 'string', 'max:40'],
            'server' => ['nullable', 'array'],
            'modules' => ['array', 'max:200'],
            'modules.*.slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'modules.*.type' => ['required', 'in:module,extension'],
            'modules.*.name' => ['nullable', 'string', 'max:255'],
            'modules.*.version' => ['required', 'string', 'max:40'],
            'modules.*.enabled' => ['boolean'],
            'modules.*.requires' => ['nullable', 'array'],
            // What the agent can do (newer agents only), e.g. ["backup"].
            'capabilities' => ['nullable', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
        ]);

        DB::transaction(function () use ($installation, $data) {
            if ($installation->core_version && $installation->core_version !== $data['version']) {
                Event::log('installation.version_changed', "Now reports version {$data['version']} (was {$installation->core_version}).", ['installation' => $installation, 'user' => null]);
            }
            $installation->forceFill([
                'core_version' => $data['version'],
                'php_version' => $data['php'] ?? null,
                'framework_version' => $data['framework'] ?? null,
                'server' => array_slice((array) ($data['server'] ?? []), 0, 30),
                'capabilities' => array_values(array_unique((array) ($data['capabilities'] ?? []))),
            ])->save();

            $slugs = [];
            foreach ($data['modules'] ?? [] as $m) {
                $slugs[] = $m['slug'];
                InstallationModule::query()->updateOrCreate(
                    ['installation_id' => $installation->id, 'slug' => $m['slug']],
                    ['type' => $m['type'], 'name' => $m['name'] ?? null, 'version' => $m['version'], 'enabled' => $m['enabled'] ?? true, 'requires' => $m['requires'] ?? null]
                );
            }
            InstallationModule::query()->where('installation_id', $installation->id)->whereNotIn('slug', $slugs ?: ['__none__'])->delete();
        });

        // At most one instruction. An update already handed over finishes first; otherwise a
        // waiting backup goes before new updates (backups also work while updates are suspended).
        $installation = $installation->fresh(['client']);
        $deploy = $this->deployments->instructionsFor($installation);
        $updateRunning = $installation->targets()->whereIn('status', ['delivered', 'running', 'rolling_back'])->exists();
        $backup = $updateRunning ? null : app(\App\Services\BackupService::class)->instructionFor($installation);

        return response()->json([
            'status' => $installation->canReceiveUpdates() ? 'active' : 'suspended',
            'check_interval' => $installation->check_interval,
            'instructions' => $backup ? [$backup] : $deploy,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Published releases this installation could take (for the site's own "check for updates"). */
    public function updates(Request $request, Compatibility $compatibility): JsonResponse
    {
        $installation = $this->installation($request)->load('modules', 'client');
        $releases = Release::query()->where('status', 'published')
            ->where(fn ($q) => $q->whereIn('type', ['core', 'module'])->orWhere(fn ($w) => $w->where('type', 'extension')->where('client_id', $installation->client_id)))
            ->get()
            ->filter(fn (Release $r) => $r->type === 'core' || $installation->modules->contains('slug', $r->slug))
            ->map(fn (Release $r) => ['release' => $r] + $compatibility->check($r, $installation))
            ->filter(fn ($x) => $x['ok'])
            ->sortBy(fn ($x) => [$x['release']->slug, VersionConstraint::normalize($x['release']->version)])
            ->map(fn ($x) => ['type' => $x['release']->type, 'slug' => $x['release']->slug, 'version' => $x['release']->version, 'name' => $x['release']->name, 'notes' => $x['release']->notes, 'published_at' => $x['release']->published_at?->toIso8601String()])
            ->values();

        return response()->json(['updates' => $releases]);
    }

    /** Signed manifest of an installed version, used as the baseline for detecting locally modified files. */
    public function manifest(Request $request, string $type, string $slug, string $version): JsonResponse
    {
        $installation = $this->installation($request);
        $release = Release::query()->where('type', $type)->where('slug', $slug)->where('version', $version)
            ->whereIn('status', ['published', 'withdrawn'])->whereNotNull('manifest')
            ->when($type === 'extension', fn ($q) => $q->where('client_id', $installation->client_id))
            ->first();
        if (! $release) {
            throw new ApiException('not_found', 'No manifest for that version.', 404);
        }

        return response()->json(['manifest' => $release->manifest, 'signature' => $release->signature, 'key_id' => $release->signing_key_id]);
    }

    public function accept(Request $request, int $target): JsonResponse
    {
        $installation = $this->installation($request);
        $this->assertCanUpdate($installation);
        $t = $this->target($installation, $target);

        return response()->json(in_array($t->status, ['rollback_requested', 'rolling_back'], true) ? $this->deployments->acceptRollback($t) : $this->deployments->accept($t));
    }

    public function package(Request $request, int $target): BinaryFileResponse
    {
        $installation = $this->installation($request);
        $this->assertCanUpdate($installation);
        $t = $this->target($installation, $target);
        if (! in_array($t->status, ['delivered', 'running'], true)) {
            throw new ApiException('not_deliverable', 'Accept the update before downloading it.', 409);
        }
        $release = $t->deployment->release;
        $disk = Storage::disk(config('central.packages_disk'));
        if (! $release->package_path || ! $disk->exists($release->package_path)) {
            throw new ApiException('package_missing', 'The package file is missing on the update server.', 410);
        }

        // A file response supports HTTP Range requests, so an interrupted download can resume.
        return response()->download($disk->path($release->package_path), Str::slug($release->label()).'.zip', ['Content-Type' => 'application/zip', 'X-Package-Sha256' => $release->package_sha256]);
    }

    public function progress(Request $request, int $target): JsonResponse
    {
        $data = $request->validate(['step' => ['required', 'string', 'max:40'], 'message' => ['nullable', 'string', 'max:500']]);
        $t = $this->target($this->installation($request), $target);
        $this->deployments->progress($t, $data['step'], $data['message'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function complete(Request $request, int $target): JsonResponse
    {
        $data = $request->validate([
            'result' => ['required', 'in:succeeded,failed,rolled_back,rollback_failed'],
            'error' => ['nullable', 'string', 'max:2000'],
            'error_code' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'retryable' => ['boolean'],
            'version' => ['nullable', 'string', 'max:40'],
            'log' => ['nullable', 'array', 'max:200'],
            'log.*' => ['string', 'max:500'],
        ]);
        $t = $this->target($this->installation($request), $target);
        $t = $this->deployments->complete($t, $data);

        return response()->json(['ok' => true, 'status' => $t->status]);
    }

    /* ------------------------------------------------------------------ */

    protected function installation(Request $request): Installation
    {
        return $request->attributes->get('installation');
    }

    /** 404 (not 403) for other installations' targets: never confirm they exist. */
    protected function target(Installation $installation, int $id): DeploymentTarget
    {
        $target = DeploymentTarget::query()->with('deployment.release', 'installation')->where('installation_id', $installation->id)->find($id);
        if (! $target) {
            throw new ApiException('not_found', 'Not found.', 404);
        }

        return $target;
    }

    protected function assertCanUpdate(Installation $installation): void
    {
        if (! $installation->canReceiveUpdates()) {
            throw new ApiException('suspended', 'Updates are suspended for this website.', 403);
        }
    }
}
