<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Release;
use App\Services\ReleaseService;
use App\Support\VersionConstraint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReleaseController extends Controller
{
    public function __construct(protected ReleaseService $releases) {}

    public function index(Request $request): Response
    {
        $type = $request->query('type');

        return Inertia::render('Releases/Index', [
            'releases' => Release::query()->with('client:id,name')->withCount('deployments')
                ->when(in_array($type, array_keys(Release::TYPES), true), fn ($q) => $q->where('type', $type))
                ->latest('id')->paginate(30)->withQueryString(),
            'type' => $type,
            'types' => Release::TYPES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Releases/Create', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'max_mb' => min(config('central.max_package_mb'), $this->phpUploadLimitMb()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'package' => ['required', 'file', 'mimes:zip', 'max:'.(config('central.max_package_mb') * 1024)],
            'client_id' => ['nullable', 'exists:clients,id'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'requires.core' => ['nullable', 'string', 'max:100'],
            'requires.php' => ['nullable', 'string', 'max:100'],
            'requires.modules' => ['nullable', 'array'],
            'upgrade_from_min' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'upgrade_from_max' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'database_rollback' => ['boolean'],
        ]);

        $release = $this->releases->createFromPackage($request->file('package')->getRealPath(), $data, $request->user());

        return redirect()->route('releases.show', $release)->with('success', "Draft {$release->label()} created. Check the details, then publish it.");
    }

    public function show(Release $release): Response
    {
        $release->load('client:id,name', 'deployments.targets:id,deployment_id,status');
        $manifest = $release->manifestData();

        return Inertia::render('Releases/Show', [
            'release' => $release->makeVisible([])->toArray() + ['label' => $release->label()],
            'manifest' => $manifest ? [
                'files' => count((array) ($manifest['files'] ?? [])),
                'sample' => array_slice(array_keys((array) ($manifest['files'] ?? [])), 0, 12),
                'key_id' => $manifest['key_id'] ?? null,
                'signed_at' => $manifest['signed_at'] ?? null,
                'instructions' => $manifest['instructions'] ?? null,
            ] : null,
            'deployments' => $release->deployments->map(fn ($d) => [
                'id' => $d->id, 'status' => $d->status, 'created_at' => $d->created_at,
                'counts' => $d->targets->countBy('status'),
            ])->sortByDesc('id')->values(),
            'previous' => Release::query()->where('type', $release->type)->where('slug', $release->slug)
                ->where('client_id', $release->client_id)->where('id', '!=', $release->id)
                ->get(['id', 'version', 'status'])->sortByDesc(fn ($r) => VersionConstraint::normalize($r->version))->values()->take(10),
        ]);
    }

    public function update(Request $request, Release $release): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'requires.core' => ['nullable', 'string', 'max:100'],
            'requires.php' => ['nullable', 'string', 'max:100'],
            'requires.modules' => ['nullable', 'array'],
            'upgrade_from_min' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'upgrade_from_max' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'has_migrations' => ['boolean'],
            'database_rollback' => ['boolean'],
        ]);
        $this->releases->updateDraft($release, $data);

        return back()->with('success', 'Draft saved.');
    }

    public function publish(Request $request, Release $release): RedirectResponse
    {
        $this->releases->publish($release, $request->user());

        return back()->with('success', "{$release->label()} published and signed. It can now be deployed.");
    }

    public function withdraw(Request $request, Release $release): RedirectResponse
    {
        $this->releases->withdraw($release, $request->user());

        return back()->with('success', "{$release->label()} withdrawn. Pending deliveries were cancelled.");
    }

    public function destroy(Release $release): RedirectResponse
    {
        $this->releases->deleteDraft($release);

        return redirect()->route('releases.index')->with('success', 'Draft deleted.');
    }

    protected function phpUploadLimitMb(): int
    {
        $toMb = function (string $v): int {
            $n = (int) $v;

            return match (strtolower(substr(trim($v), -1))) {
                'g' => $n * 1024,
                'k' => (int) ceil($n / 1024),
                'm' => $n,
                default => (int) ceil($n / 1048576),
            };
        };

        return max(1, min($toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size'))));
    }
}
