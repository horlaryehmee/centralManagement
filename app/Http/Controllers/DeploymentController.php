<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Installation;
use App\Models\Release;
use App\Services\DeploymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    public function __construct(protected DeploymentService $deployments) {}

    public function index(): Response
    {
        return Inertia::render('Deployments/Index', [
            'deployments' => Deployment::query()->with('release:id,type,slug,version', 'creator:id,name')
                ->withCount(['targets', 'targets as succeeded_count' => fn ($q) => $q->where('status', 'succeeded'),
                    'targets as failed_count' => fn ($q) => $q->whereIn('status', ['failed', 'no_response', 'rollback_failed'])])
                ->latest('id')->paginate(30),
        ]);
    }

    /** Step 1: pick the release and the installations (all / filtered / selected / one). */
    public function create(Request $request): Response
    {
        $releaseId = $request->integer('release') ?: null;

        return Inertia::render('Deployments/Create', [
            'releases' => Release::query()->with('client:id,name')->where('status', 'published')->latest('id')->get(['id', 'type', 'slug', 'version', 'client_id', 'name']),
            'release_id' => $releaseId,
            'installations' => Installation::query()->with('client:id,name', 'modules:id,installation_id,slug,version')
                ->whereIn('status', ['active', 'suspended'])->orderBy('name')->get()
                ->map(fn (Installation $i) => [
                    'id' => $i->id, 'name' => $i->name, 'domain' => $i->domain, 'status' => $i->status, 'online' => $i->isOnline(),
                    'is_test' => $i->is_test, 'core_version' => $i->core_version, 'client_id' => $i->client_id, 'client' => $i->client?->name,
                    'modules' => $i->modules->pluck('version', 'slug'),
                ]),
            'options' => app(InstallationController::class)->filterOptions(),
            'preselect' => array_map('intval', (array) $request->query('installations', [])),
        ]);
    }

    /** Step 2: confirmation screen showing exactly which installations will (and won't) get it. */
    public function preview(Request $request): Response
    {
        $data = $request->validate(['release_id' => ['required', 'exists:releases,id'], 'installations' => ['required', 'array', 'min:1'], 'installations.*' => ['integer']]);
        $release = Release::query()->with('client:id,name')->findOrFail($data['release_id']);
        $installations = Installation::query()->with('client', 'modules')->whereIn('id', $data['installations'])->orderBy('name')->get();

        return Inertia::render('Deployments/Confirm', [
            'release' => $release->only('id', 'type', 'slug', 'version', 'name', 'notes', 'has_migrations', 'database_rollback') + ['label' => $release->label(), 'client' => $release->client?->name],
            'rows' => $this->deployments->preview($release, $installations)->map(fn ($r) => [
                'id' => $r['installation']->id, 'name' => $r['installation']->name, 'domain' => $r['installation']->domain,
                'client' => $r['installation']->client?->name, 'is_test' => $r['installation']->is_test, 'online' => $r['installation']->isOnline(),
                'current' => $release->type === 'core' ? $r['installation']->core_version : $r['installation']->modules->firstWhere('slug', $release->slug)?->version,
                'ok' => $r['ok'], 'action' => $r['action'], 'reasons' => $r['reasons'],
            ])->values(),
            'selection' => $request->input('selection', []),
        ]);
    }

    /** Step 3: create it. */
    public function store(Request $request): RedirectResponse
    {
        $data = Validator::make($request->all(), [
            'release_id' => ['required', 'exists:releases,id'],
            'installations' => ['required', 'array', 'min:1'],
            'installations.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'canary_installation_id' => ['nullable', 'integer'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'allow_modified_files' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'selection' => ['nullable', 'array'],
        ])->validate();

        $deployment = $this->deployments->create(Release::findOrFail($data['release_id']), $data['installations'], $data, $request->user());

        return redirect()->route('deployments.show', $deployment)->with('success', $deployment->status === 'scheduled' ? 'Deployment scheduled.' : 'Deployment started. Each website picks it up on its next check.');
    }

    public function show(Deployment $deployment): Response
    {
        $deployment->load('release.client:id,name', 'creator:id,name', 'canary:id,name');

        return Inertia::render('Deployments/Show', [
            'deployment' => $deployment,
            'release' => $deployment->release->only('id', 'type', 'slug', 'version', 'database_rollback', 'status') + ['label' => $deployment->release->label()],
            'targets' => $deployment->targets()->with('installation:id,name,domain,client_id,last_seen_at,check_interval', 'installation.client:id,name')->orderByDesc('is_canary')->orderBy('id')->get()
                ->map(fn (DeploymentTarget $t) => $t->toArray() + ['online' => $t->installation->isOnline()]),
            'events' => $deployment->events()->with('installation:id,name')->latest('id')->limit(100)->get(),
            'counts' => $deployment->targets()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function cancel(Request $request, Deployment $deployment): RedirectResponse
    {
        $n = $this->deployments->cancel($deployment, $request->user());

        return back()->with('success', $n ? "{$n} pending update(s) cancelled. Updates already running will finish and report back." : 'Nothing left to cancel; running updates will finish and report back.');
    }

    public function retry(Request $request, Deployment $deployment): RedirectResponse
    {
        $n = $this->deployments->retryFailed($deployment, $request->user());

        return back()->with($n ? 'success' : 'error', $n ? "Retrying {$n} installation(s)." : 'No failed installations can be retried (they may no longer be compatible).');
    }

    public function rollback(Request $request, DeploymentTarget $target): RedirectResponse
    {
        $this->deployments->requestRollback($target, $request->user());

        return back()->with('success', 'Rollback requested. The website rolls back on its next check and reports the result.');
    }
}
