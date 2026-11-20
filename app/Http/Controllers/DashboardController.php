<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\DeploymentTarget;
use App\Models\Event;
use App\Models\Installation;
use App\Models\Release;
use App\Services\Signer;
use App\Support\VersionConstraint;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Signer $signer): Response
    {
        $latestCore = Release::query()->where('type', 'core')->where('status', 'published')->get()
            ->sortByDesc(fn ($r) => VersionConstraint::normalize($r->version))->first();

        $active = Installation::query()->where('status', 'active');
        $versions = Installation::query()->where('status', 'active')->whereNotNull('core_version')
            ->selectRaw('core_version, count(*) as n')->groupBy('core_version')->pluck('n', 'core_version')
            ->sortKeysUsing(fn ($a, $b) => VersionConstraint::compare($b, $a));

        $behind = $latestCore ? (clone $active)->get(['core_version'])->filter(fn ($i) => $i->core_version && VersionConstraint::compare($i->core_version, $latestCore->version) < 0)->count() : 0;

        return Inertia::render('Dashboard', [
            'stats' => [
                'clients' => Client::count(),
                'installations' => (clone $active)->count(),
                'offline' => Installation::query()->offline()->count(),
                'pending' => Installation::query()->where('status', 'pending')->count(),
                'behind' => $behind,
                'succeeded' => DeploymentTarget::query()->where('status', 'succeeded')->count(),
                'failed' => DeploymentTarget::query()->whereIn('status', ['failed', 'no_response', 'rollback_failed'])->count(),
                'in_progress' => DeploymentTarget::query()->whereNotIn('status', DeploymentTarget::FINAL)->count(),
            ],
            'latest_core' => $latestCore?->version,
            'versions' => $versions->map(fn ($n, $v) => ['version' => $v, 'count' => $n, 'latest' => $latestCore && $v === $latestCore->version])->values(),
            'offline' => Installation::query()->offline()->with('client:id,name')->orderBy('last_seen_at')->limit(6)->get(['id', 'name', 'domain', 'client_id', 'last_seen_at']),
            'events' => Event::query()->with('installation:id,name', 'deployment:id')->latest('id')->limit(15)->get(),
            'signing' => $signer->hasKey() ? ['fingerprint' => $signer->fingerprint(), 'key_id' => $signer->keyId()] : null,
        ]);
    }
}
