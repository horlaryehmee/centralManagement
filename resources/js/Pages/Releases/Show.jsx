import { Link, router, useForm } from '@inertiajs/react';
import { Rocket, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { bytes, dateTime, Details, DeploymentStatus, ReleaseStatus, TypeBadge } from '@/Components/central';
import { Button, Card, ConfirmModal, FormActions, PageHeader, Textarea, TextInput, Toggle } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

function DraftForm({ release }) {
    const form = useForm({
        name: release.name ?? '',
        notes: release.notes ?? '',
        requires: { core: release.requires?.core ?? '', php: release.requires?.php ?? '', modules: release.requires?.modules ?? {} },
        upgrade_from_min: release.upgrade_from_min ?? '',
        upgrade_from_max: release.upgrade_from_max ?? '',
        has_migrations: release.has_migrations,
        database_rollback: release.database_rollback,
    });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(`/releases/${release.id}`, { preserveScroll: true });
            }}
        >
            <Card title="Draft details" description="Editable until published; then the release is signed and locked.">
                <div className="space-y-5">
                    <TextInput label="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} optional />
                    <Textarea label="Release notes" rows={5} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} optional />
                    <div className="grid gap-5 sm:grid-cols-2">
                        <TextInput label="Requires core" value={form.data.requires.core} onChange={(e) => form.setData('requires', { ...form.data.requires, core: e.target.value })} error={form.errors['requires.core']} optional />
                        <TextInput label="Requires PHP" value={form.data.requires.php} onChange={(e) => form.setData('requires', { ...form.data.requires, php: e.target.value })} error={form.errors['requires.php']} optional />
                        <TextInput label="Upgrade from (minimum)" value={form.data.upgrade_from_min} onChange={(e) => form.setData('upgrade_from_min', e.target.value)} error={form.errors.upgrade_from_min} optional />
                        <TextInput label="Upgrade from (maximum)" value={form.data.upgrade_from_max} onChange={(e) => form.setData('upgrade_from_max', e.target.value)} error={form.errors.upgrade_from_max} optional />
                    </div>
                    <Toggle label="Includes database migrations" checked={form.data.has_migrations} onChange={(v) => form.setData('has_migrations', v)} />
                    <Toggle label="Database changes can be rolled back" description="Only if every new migration has a working down() method." checked={form.data.database_rollback} onChange={(v) => form.setData('database_rollback', v)} />
                </div>
                <FormActions dirty={form.isDirty}>
                    <Button loading={form.processing}>Save draft</Button>
                </FormActions>
            </Card>
        </form>
    );
}

export default function ReleaseShow({ release: r, manifest, deployments, previous }) {
    const [confirm, setConfirm] = useState(null);
    const modules = Object.entries(r.requires?.modules || {});

    return (
        <CentralLayout title={r.label}>
            <PageHeader
                back={{ href: '/releases', label: 'Releases' }}
                title={r.type === 'core' ? `Core ${r.version}` : `${r.slug} ${r.version}`}
                description={r.name}
                actions={
                    <>
                        {r.status === 'draft' && (
                            <>
                                <Button variant="ghost" type="button" onClick={() => setConfirm('delete')}>
                                    <Trash2 size={15} /> Delete
                                </Button>
                                <Button type="button" onClick={() => setConfirm('publish')}>
                                    <ShieldCheck size={16} /> Sign & publish
                                </Button>
                            </>
                        )}
                        {r.status === 'published' && (
                            <>
                                <Button variant="secondary" type="button" onClick={() => setConfirm('withdraw')}>
                                    Withdraw
                                </Button>
                                <Button href={`/deployments/create?release=${r.id}`}>
                                    <Rocket size={16} /> Deploy
                                </Button>
                            </>
                        )}
                        {r.status === 'withdrawn' && (
                            <Button variant="secondary" type="button" onClick={() => router.post(`/releases/${r.id}/publish`)}>
                                Publish again
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                <div className="space-y-6">
                    {r.status === 'draft' ? (
                        <DraftForm release={r} />
                    ) : (
                        <Card title="Release notes">{r.notes ? <p className="text-sm whitespace-pre-line text-zinc-700">{r.notes}</p> : <p className="text-sm text-zinc-400">No notes.</p>}</Card>
                    )}
                    <Card title="Deployments" padded={false}>
                        {deployments.length ? (
                            <ul className="divide-y divide-zinc-100">
                                {deployments.map((d) => (
                                    <li key={d.id}>
                                        <Link href={`/deployments/${d.id}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm hover:bg-zinc-50 sm:px-5">
                                            <span className="min-w-0 flex-1 font-medium">Deployment #{d.id}</span>
                                            <span className="text-xs text-zinc-500">
                                                {d.counts.succeeded || 0} ok · {(d.counts.failed || 0) + (d.counts.no_response || 0)} failed
                                            </span>
                                            <DeploymentStatus status={d.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-6 text-center text-sm text-zinc-400">Not deployed yet.</p>
                        )}
                    </Card>
                </div>
                <div className="space-y-6">
                    <Card title="Package">
                        <Details
                            rows={[
                                ['Status', <span className="flex flex-wrap gap-1.5"><ReleaseStatus status={r.status} /><TypeBadge type={r.type} /></span>],
                                r.client && ['Client', <Link href={`/clients/${r.client.id}`} className="hover:underline">{r.client.name}</Link>],
                                ['Size', `${bytes(r.package_size)} · ${r.file_count} files`],
                                ['SHA-256', <code className="font-mono text-xs break-all">{r.package_sha256}</code>],
                                ['Requires core', r.requires?.core],
                                ['Requires PHP', r.requires?.php],
                                modules.length > 0 && ['Requires modules', modules.map(([s, c]) => `${s} ${c}`).join(', ')],
                                ['Upgrades from', r.upgrade_from_min || r.upgrade_from_max ? `${r.upgrade_from_min || 'any'} – ${r.upgrade_from_max || 'any'}` : 'Any older version'],
                                ['Migrations', r.has_migrations ? (r.database_rollback ? 'Yes, can be rolled back' : 'Yes, files-only rollback') : 'None'],
                                ['Published', r.published_at ? dateTime(r.published_at) : '—'],
                            ]}
                        />
                    </Card>
                    {manifest && (
                        <Card title="Signed manifest">
                            <p className="flex items-center gap-2 text-sm text-emerald-700">
                                <ShieldCheck size={16} /> Signed with key {manifest.key_id}
                            </p>
                            <p className="mt-2 text-sm text-zinc-600">{manifest.files} files with individual checksums.</p>
                            <p className="mt-2 text-xs text-zinc-500">{manifest.instructions}</p>
                        </Card>
                    )}
                    {previous.length > 0 && (
                        <Card title="Other versions">
                            <ul className="space-y-1.5">
                                {previous.map((p) => (
                                    <li key={p.id}>
                                        <Link href={`/releases/${p.id}`} className="flex items-center justify-between text-sm hover:text-zinc-600">
                                            {p.version} <ReleaseStatus status={p.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>

            <ConfirmModal open={confirm === 'publish'} onClose={() => setConfirm(null)} onConfirm={() => router.post(`/releases/${r.id}/publish`, {}, { onFinish: () => setConfirm(null) })} title="Sign and publish?" confirmLabel="Publish" message="The manifest (every file's checksum) is signed and the release becomes deployable. Its details are locked after this." />
            <ConfirmModal open={confirm === 'withdraw'} onClose={() => setConfirm(null)} onConfirm={() => router.post(`/releases/${r.id}/withdraw`, {}, { onFinish: () => setConfirm(null) })} title="Withdraw this release?" confirmLabel="Withdraw" message="It can't be deployed any more and updates not yet picked up are cancelled. Websites that already installed it are not changed." />
            <ConfirmModal open={confirm === 'delete'} onClose={() => setConfirm(null)} onConfirm={() => router.delete(`/releases/${r.id}`)} title="Delete this draft?" message="The uploaded package is deleted." />
        </CentralLayout>
    );
}
