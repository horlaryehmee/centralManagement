<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Event;
use App\Models\Installation;
use App\Models\InstallationModule;
use App\Support\VersionConstraint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InstallationController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->only('search', 'client', 'status', 'version', 'module');

        $installations = Installation::query()->with('client:id,name', 'modules:id,installation_id,slug,version,type')
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('domain', 'like', "%{$s}%")))
            ->when($f['client'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'offline' ? $q->offline() : $q->where('status', $s))
            ->when($f['version'] ?? null, fn ($q, $v) => $q->where('core_version', $v))
            ->when($f['module'] ?? null, fn ($q, $m) => $q->whereHas('modules', fn ($w) => $w->where('slug', $m)))
            ->orderBy('name')->paginate(30)->withQueryString();

        return Inertia::render('Installations/Index', [
            'installations' => $installations->through(fn (Installation $i) => $this->row($i)),
            'filters' => $f,
            'options' => $this->filterOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Installations/Form', [
            'installation' => null,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'client_id' => $request->integer('client') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $installation = Installation::create($data + ['check_interval' => $data['check_interval'] ?? config('central.check_interval')]);
        $code = $installation->issueEnrollmentCode();
        Event::log('installation.created', "Installation {$installation->name} ({$installation->domain}) added.", ['installation' => $installation]);

        return redirect()->route('installations.show', $installation)
            ->with('enrollment', ['code' => $code, 'expires_at' => $installation->enrollment_expires_at->toIso8601String(), 'url' => url('/')]);
    }

    public function show(Installation $installation): Response
    {
        $installation->load('client:id,name,status,key', 'modules');

        return Inertia::render('Installations/Show', [
            'installation' => $this->row($installation) + [
                'server' => $installation->server,
                'framework_version' => $installation->framework_version,
                'registered_at' => $installation->registered_at,
                'last_ip' => $installation->last_ip,
                'secret_issued_at' => $installation->secret_issued_at,
                'enrollment_expires_at' => $installation->enrollment_expires_at,
                'environment' => $installation->environment,
                'notes' => $installation->notes,
                'check_interval' => $installation->check_interval,
                'modules' => $installation->modules->sortBy('slug')->values(),
            ],
            'deployments' => $installation->targets()->with('deployment.release:id,type,slug,version')->latest('id')->limit(50)->get(),
            'events' => $installation->events()->latest('id')->limit(40)->get(),
            'backups' => $this->backupList($installation),
        ]);
    }

    protected function backupList(Installation $installation): array
    {
        app(\App\Services\BackupService::class)->expireStale($installation);

        return [
            'supported' => $installation->supports('backup'),
            'keep' => (int) config('central.backups_keep'),
            'items' => $installation->backups()->with('requester:id,name')->latest('id')->limit(30)->get()->map(fn (\App\Models\Backup $b) => [
                'id' => $b->id,
                'status' => $b->status,
                'status_label' => \App\Models\Backup::STATUSES[$b->status] ?? $b->status,
                'active' => $b->isActive(),
                'include_files' => $b->include_files,
                'size' => $b->size ? \App\Services\BackupService::humanBytes($b->size) : null,
                'progress' => $b->status === 'uploading' ? \App\Services\BackupService::humanBytes($b->received_bytes) : null,
                'contents' => $b->contents,
                'encrypted' => $b->encrypted,
                'error' => $b->error,
                'requested_by' => $b->requester?->name,
                'created_at' => $b->created_at,
                'completed_at' => $b->completed_at,
                'download_url' => $b->status === 'completed' ? route('backups.download', $b) : null,
            ]),
        ];
    }

    public function edit(Installation $installation): Response
    {
        return Inertia::render('Installations/Form', [
            'installation' => $installation,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'client_id' => $installation->client_id,
        ]);
    }

    public function update(Request $request, Installation $installation): RedirectResponse
    {
        $installation->update($this->validated($request));
        Event::log('installation.updated', 'Installation details updated.', ['installation' => $installation]);

        return redirect()->route('installations.show', $installation)->with('success', 'Installation saved.');
    }

    /** activate | suspend (no updates, still monitored) | revoke (credentials stop working). */
    public function status(Request $request, Installation $installation): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:activate,suspend,revoke']])['action'];

        if ($action === 'activate' && ! $installation->secret) {
            return back()->with('error', 'This installation has no credentials. Create a new connection code instead.');
        }
        $installation->forceFill([
            'status' => ['activate' => 'active', 'suspend' => 'suspended', 'revoke' => 'revoked'][$action],
            'secret' => $action === 'revoke' ? null : $installation->secret,
        ])->save();

        Event::log("installation.{$action}", match ($action) {
            'activate' => 'Installation activated.',
            'suspend' => 'Installation suspended: no updates, monitoring continues.',
            'revoke' => 'Credentials revoked: the website can no longer contact the update server.',
        }, ['installation' => $installation, 'level' => $action === 'activate' ? 'info' : 'warning']);

        return back()->with('success', match ($action) {
            'activate' => 'Installation activated.',
            'suspend' => 'Installation suspended. The website keeps working but gets no updates.',
            'revoke' => 'Access revoked. To reconnect it later, create a new connection code.',
        });
    }

    /** New one-time connection code (replaces any current credentials). */
    public function enrollment(Installation $installation): RedirectResponse
    {
        $code = $installation->issueEnrollmentCode();
        Event::log('installation.new_code', 'New connection code created; previous credentials no longer work.', ['installation' => $installation, 'level' => 'warning']);

        return back()->with('enrollment', ['code' => $code, 'expires_at' => $installation->enrollment_expires_at->toIso8601String(), 'url' => url('/')]);
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:production,staging,test'],
            'is_test' => ['boolean'],
            'check_interval' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['domain'] = preg_replace('~^https?://~i', '', rtrim(trim($data['domain']), '/'));

        return $data;
    }

    protected function row(Installation $i): array
    {
        return [
            'id' => $i->id,
            'uuid' => $i->uuid,
            'name' => $i->name,
            'domain' => $i->domain,
            'status' => $i->status,
            'online' => $i->isOnline(),
            'is_test' => $i->is_test,
            'environment' => $i->environment,
            'core_version' => $i->core_version,
            'php_version' => $i->php_version,
            'last_seen_at' => $i->last_seen_at,
            'client' => $i->client ? ['id' => $i->client->id, 'name' => $i->client->name, 'status' => $i->client->status ?? null] : null,
            'modules' => $i->modules->map(fn ($m) => ['slug' => $m->slug, 'version' => $m->version, 'type' => $m->type])->values(),
        ];
    }

    public function filterOptions(): array
    {
        return [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'versions' => Installation::query()->whereNotNull('core_version')->distinct()->pluck('core_version')->sort(fn ($a, $b) => VersionConstraint::compare($b, $a))->values(),
            'modules' => InstallationModule::query()->distinct()->orderBy('slug')->pluck('slug'),
            'statuses' => ['active' => 'Active', 'offline' => 'Offline', 'pending' => 'Waiting to connect', 'suspended' => 'Suspended', 'revoked' => 'Revoked'],
        ];
    }
}
