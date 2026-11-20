<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\DeploymentTarget;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));

        return Inertia::render('Clients/Index', [
            'clients' => Client::query()
                ->withCount(['installations', 'installations as active_installations_count' => fn ($q) => $q->where('status', 'active')])
                ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('website', 'like', "%{$search}%")))
                ->orderBy('name')->paginate(25)->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Form', ['client' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = Client::create($this->validated($request));
        Event::log('client.created', "Client {$client->name} registered.", ['client' => $client]);

        return redirect()->route('clients.show', $client)->with('success', 'Client registered. Now add their website as an installation.');
    }

    public function show(Client $client): Response
    {
        $client->load(['installations' => fn ($q) => $q->withCount('modules')->orderBy('name'), 'installations.modules']);

        return Inertia::render('Clients/Show', [
            'client' => $client,
            'history' => DeploymentTarget::query()->with('deployment.release:id,type,slug,version', 'installation:id,name')
                ->whereIn('installation_id', $client->installations->pluck('id'))->latest('id')->limit(30)->get(),
            'extensions' => $client->releases()->latest('id')->get(['id', 'slug', 'version', 'status', 'created_at']),
        ]);
    }

    public function edit(Client $client): Response
    {
        return Inertia::render('Clients/Form', ['client' => $client]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request));
        Event::log('client.updated', "Client {$client->name} updated.", ['client' => $client]);

        return redirect()->route('clients.show', $client)->with('success', 'Client saved.');
    }

    /** Suspending a client stops update services for all of its installations (their websites keep working). */
    public function status(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);
        $client->update($data);
        Event::log('client.'.$data['status'], "Client {$client->name} ".($data['status'] === 'active' ? 'reactivated' : 'suspended').'.', ['client' => $client, 'level' => $data['status'] === 'active' ? 'info' : 'warning']);

        return back()->with('success', $data['status'] === 'active' ? 'Client reactivated.' : 'Client suspended: their websites keep working but get no updates.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'hosting_provider' => ['nullable', 'string', 'max:255'],
            'hosting_notes' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
