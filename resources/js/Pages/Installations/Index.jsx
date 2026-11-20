import { Link, router } from '@inertiajs/react';
import { ChevronRight, Plus, Rocket, Server } from 'lucide-react';
import { ago, InstallationStatus } from '@/Components/central';
import { AdminEmpty, AdminPagination, Badge, Button, Card, PageHeader } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export function FilterBar({ filters, options, onChange }) {
    const select = (key, label, opts) => (
        <select className="input sm:w-auto" value={filters[key] || ''} onChange={(e) => onChange({ ...filters, [key]: e.target.value || undefined })} aria-label={label}>
            <option value="">{label}</option>
            {opts.map(([v, l]) => (
                <option key={v} value={v}>
                    {l}
                </option>
            ))}
        </select>
    );
    return (
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
            <input className="input col-span-2 sm:w-56" placeholder="Search name or domain" value={filters.search || ''} onChange={(e) => onChange({ ...filters, search: e.target.value || undefined })} aria-label="Search" />
            {select('client', 'All clients', options.clients.map((c) => [String(c.id), c.name]))}
            {select('status', 'Any status', Object.entries(options.statuses))}
            {select('version', 'Any version', options.versions.map((v) => [v, `Core ${v}`]))}
            {select('module', 'Any module', options.modules.map((m) => [m, m]))}
        </div>
    );
}

export default function InstallationsIndex({ installations, filters, options }) {
    const apply = (f) => router.get('/installations', f, { preserveState: true, replace: true });
    const ids = installations.data.map((i) => i.id);

    return (
        <CentralLayout title="Installations">
            <PageHeader
                title="Installations"
                description="Every independently hosted website."
                actions={
                    ids.length > 0 && (
                        <Button variant="secondary" href={`/deployments/create?${ids.map((id) => `installations[]=${id}`).join('&')}`}>
                            <Rocket size={16} /> Deploy to these
                        </Button>
                    )
                }
                fab={{ href: '/installations/create', label: 'Add installation', icon: Plus }}
            />
            <div className="mb-4">
                <FilterBar filters={filters} options={options} onChange={apply} />
            </div>
            <Card padded={false}>
                {installations.data.length ? (
                    <ul className="divide-y divide-zinc-100">
                        {installations.data.map((i) => (
                            <li key={i.id}>
                                <Link href={`/installations/${i.id}`} className="flex items-center gap-3 px-4 py-3.5 hover:bg-zinc-50 sm:px-5">
                                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${i.status !== 'active' ? 'bg-zinc-300' : i.online ? 'bg-emerald-500' : 'bg-amber-500'}`} aria-hidden />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium text-zinc-900">{i.name}</span>
                                            <InstallationStatus status={i.status} online={i.online} />
                                            {i.is_test && <Badge color="blue">Test site</Badge>}
                                        </span>
                                        <span className="block truncate text-xs text-zinc-500">
                                            {i.client?.name} · {i.domain}
                                        </span>
                                        {i.modules.length > 0 && (
                                            <span className="mt-1 flex flex-wrap gap-1">
                                                {i.modules.map((m) => (
                                                    <span key={m.slug} className={`rounded px-1.5 py-0.5 text-[11px] ${m.type === 'extension' ? 'bg-amber-50 text-amber-800' : 'bg-zinc-100 text-zinc-600'}`}>
                                                        {m.slug} {m.version}
                                                    </span>
                                                ))}
                                            </span>
                                        )}
                                    </span>
                                    <span className="shrink-0 text-right">
                                        <span className="block text-sm font-semibold text-zinc-900 tabular-nums">{i.core_version ? `v${i.core_version}` : '—'}</span>
                                        <span className="block text-xs text-zinc-400">{ago(i.last_seen_at)}</span>
                                    </span>
                                    <ChevronRight size={18} className="hidden shrink-0 text-zinc-300 sm:block" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <AdminEmpty icon={Server} title="No installations match" message="Add a website from its client's page, or change the filters." />
                )}
                <AdminPagination paginator={installations} />
            </Card>
        </CentralLayout>
    );
}
