import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { InstallationStatus } from '@/Components/central';
import { Badge, Button, Card, PageHeader, Select } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function DeploymentCreate({ releases, release_id, installations, options, preselect }) {
    const [releaseId, setReleaseId] = useState(release_id ? String(release_id) : '');
    const [f, setF] = useState({ search: '', client: '', status: '', version: '', module: '' });
    const [selected, setSelected] = useState(() => new Set(preselect));
    const release = releases.find((r) => String(r.id) === releaseId);

    const shown = useMemo(
        () =>
            installations.filter((i) => {
                if (release?.type === 'extension' && i.client_id !== release.client_id) return false;
                if (f.search && !`${i.name} ${i.domain} ${i.client}`.toLowerCase().includes(f.search.toLowerCase())) return false;
                if (f.client && String(i.client_id) !== f.client) return false;
                if (f.status === 'offline' && i.online) return false;
                if (f.status && f.status !== 'offline' && i.status !== f.status) return false;
                if (f.version && i.core_version !== f.version) return false;
                if (f.module && !(f.module in (i.modules || {}))) return false;
                return true;
            }),
        [installations, f, release],
    );

    const toggle = (id) => setSelected((s) => {
        const n = new Set(s);
        n.has(id) ? n.delete(id) : n.add(id);
        return n;
    });
    const chosen = installations.filter((i) => selected.has(i.id) && shown.some((s) => s.id === i.id));

    const review = () =>
        router.post('/deployments/preview', {
            release_id: releaseId,
            installations: chosen.map((i) => i.id),
            selection: { filters: Object.fromEntries(Object.entries(f).filter(([, v]) => v)), mode: chosen.length === installations.length ? 'all' : chosen.length === 1 ? 'single' : 'selected' },
        });

    const select = (key, label, opts) => (
        <select className="input sm:w-auto" value={f[key]} onChange={(e) => setF({ ...f, [key]: e.target.value })} aria-label={label}>
            <option value="">{label}</option>
            {opts.map(([v, l]) => (
                <option key={v} value={v}>
                    {l}
                </option>
            ))}
        </select>
    );

    return (
        <CentralLayout title="New deployment">
            <PageHeader back={{ href: '/deployments', label: 'Deployments' }} title="New deployment" description="Choose a release and the websites to send it to. You'll see a confirmation with compatibility checks before anything is sent." />

            <div className="grid gap-6">
                <Card title="1. Release">
                    <Select
                        label="Published release"
                        placeholder="Choose…"
                        value={releaseId}
                        onChange={(e) => setReleaseId(e.target.value)}
                        options={releases.map((r) => ({ value: String(r.id), label: `${r.type === 'core' ? 'Core' : r.slug} ${r.version}${r.client ? ` (${r.client.name} only)` : r.type === 'module' ? ' (module)' : ''}` }))}
                    />
                    {release?.type === 'extension' && <p className="mt-2 text-sm text-amber-700">Client extension: only {release.client?.name}'s websites are listed.</p>}
                </Card>

                <Card title="2. Websites" description={`${chosen.length} selected of ${shown.length} shown`} padded={false}>
                    <div className="grid grid-cols-2 gap-2 border-b border-zinc-100 p-4 sm:flex sm:flex-wrap sm:px-5">
                        <input className="input col-span-2 sm:w-52" placeholder="Search" value={f.search} onChange={(e) => setF({ ...f, search: e.target.value })} aria-label="Search" />
                        {select('client', 'All clients', options.clients.map((c) => [String(c.id), c.name]))}
                        {select('status', 'Any status', [['active', 'Active'], ['offline', 'Offline'], ['suspended', 'Suspended']])}
                        {select('version', 'Any core version', options.versions.map((v) => [v, v]))}
                        {select('module', 'Any module', options.modules.map((m) => [m, m]))}
                    </div>
                    <div className="flex flex-wrap gap-2 px-4 pt-3 sm:px-5">
                        <Button type="button" variant="secondary" onClick={() => setSelected(new Set([...selected, ...shown.map((i) => i.id)]))}>
                            Select all shown
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setSelected(new Set())}>
                            Clear
                        </Button>
                    </div>
                    <ul className="divide-y divide-zinc-100 pt-2">
                        {shown.map((i) => (
                            <li key={i.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-zinc-50 sm:px-5">
                                    <input type="checkbox" checked={selected.has(i.id)} onChange={() => toggle(i.id)} className="h-5 w-5 shrink-0 rounded border-zinc-300" />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{i.name}</span>
                                            <InstallationStatus status={i.status} online={i.online} />
                                            {i.is_test && <Badge color="blue">Test site</Badge>}
                                        </span>
                                        <span className="block truncate text-xs text-zinc-500">
                                            {i.client} · {i.domain} · core {i.core_version || '?'}
                                            {release && release.type !== 'core' && i.modules?.[release.slug] ? ` · ${release.slug} ${i.modules[release.slug]}` : ''}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        ))}
                        {!shown.length && <li className="px-5 py-8 text-center text-sm text-zinc-400">No websites match.</li>}
                    </ul>
                </Card>

                <div className="flex justify-end">
                    <Button type="button" disabled={!release || !chosen.length} onClick={review}>
                        Review {chosen.length || ''} website{chosen.length === 1 ? '' : 's'}
                    </Button>
                </div>
            </div>
        </CentralLayout>
    );
}
