import { Link } from '@inertiajs/react';
import { KeyRound, Plus, Rocket } from 'lucide-react';
import { ago, EventList, Stat } from '@/Components/central';
import { Button, Card, PageHeader } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function Dashboard({ stats, latest_core, versions, offline, events, signing }) {
    const total = versions.reduce((n, v) => n + v.count, 0) || 1;

    return (
        <CentralLayout title="Dashboard">
            <PageHeader
                title="Dashboard"
                description={latest_core ? `Latest core release: ${latest_core}` : 'No core release published yet.'}
                actions={
                    <>
                        <Button variant="secondary" href="/releases/create">
                            <Plus size={16} /> New release
                        </Button>
                        <Button href="/deployments/create">
                            <Rocket size={16} /> Deploy
                        </Button>
                    </>
                }
            />

            {!signing && (
                <p className="mb-6 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">
                    No signing key yet: releases can't be published. Run <code className="rounded bg-red-100 px-1">php artisan central:keys</code> on the server.
                </p>
            )}

            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Clients" value={stats.clients} />
                <Stat label="Active installations" value={stats.installations} hint={stats.pending ? `${stats.pending} waiting to connect` : null} />
                <Stat label="Offline" value={stats.offline} tone={stats.offline ? 'text-amber-600' : 'text-zinc-900'} hint="No contact for over an hour" />
                <Stat label="Behind latest core" value={stats.behind} tone={stats.behind ? 'text-sky-700' : 'text-zinc-900'} hint="Updates available" />
                <Stat label="Successful updates" value={stats.succeeded} tone="text-emerald-700" />
                <Stat label="Failed updates" value={stats.failed} tone={stats.failed ? 'text-red-600' : 'text-zinc-900'} />
                <Stat label="In progress" value={stats.in_progress} />
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_380px]">
                <Card title="Recent activity">
                    <EventList events={events} />
                </Card>
                <div className="space-y-6">
                    <Card title="Core versions in use">
                        {versions.length ? (
                            <ul className="space-y-3">
                                {versions.map((v) => (
                                    <li key={v.version}>
                                        <Link href={`/installations?version=${encodeURIComponent(v.version)}`} className="block">
                                            <span className="flex items-center justify-between text-sm">
                                                <span className="font-medium text-zinc-800">
                                                    {v.version} {v.latest && <span className="ml-1 text-xs font-semibold text-emerald-700">latest</span>}
                                                </span>
                                                <span className="text-zinc-500 tabular-nums">{v.count}</span>
                                            </span>
                                            <span className="mt-1 block h-1.5 overflow-hidden rounded-full bg-zinc-100">
                                                <span className={`block h-full rounded-full ${v.latest ? 'bg-emerald-500' : 'bg-sky-500'}`} style={{ width: `${(v.count / total) * 100}%` }} />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-zinc-400">No installations have reported yet.</p>
                        )}
                    </Card>
                    <Card title="Offline installations" description="Not heard from recently. Their websites may still be fine: only the update checks are missing.">
                        {offline.length ? (
                            <ul className="divide-y divide-zinc-100">
                                {offline.map((i) => (
                                    <li key={i.id}>
                                        <Link href={`/installations/${i.id}`} className="flex items-center justify-between gap-3 py-2 text-sm hover:text-zinc-600">
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">{i.name}</span>
                                                <span className="block truncate text-xs text-zinc-500">{i.client?.name}</span>
                                            </span>
                                            <span className="shrink-0 text-xs text-zinc-500">{ago(i.last_seen_at)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-zinc-400">All installations are checking in.</p>
                        )}
                    </Card>
                    {signing && (
                        <Card title="Package signing key">
                            <p className="flex items-start gap-2 text-sm text-zinc-600">
                                <KeyRound size={16} className="mt-0.5 shrink-0 text-zinc-400" />
                                <span className="min-w-0">
                                    Websites show this fingerprint after connecting; it should match:
                                    <code className="mt-1 block font-mono text-xs break-all text-zinc-900">{signing.fingerprint}</code>
                                </span>
                            </p>
                        </Card>
                    )}
                </div>
            </div>
        </CentralLayout>
    );
}
