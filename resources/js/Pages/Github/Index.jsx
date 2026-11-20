import { Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Download, ExternalLink, GitBranch, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ago, bytes, TypeBadge } from '@/Components/central';
import { AdminEmpty, Badge, Button, Card, ConfirmModal, PageHeader, Select, TextInput, Toggle } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

function SourceForm({ source, clients, onDone }) {
    const form = useForm({
        repository: source?.repository ?? '',
        token: '',
        remove_token: false,
        type: source?.type ?? 'core',
        client_id: source?.client_id ?? '',
        asset_pattern: source?.asset_pattern ?? '*.zip',
        include_prereleases: source?.include_prereleases ?? false,
        auto_import: source?.auto_import ?? true,
        auto_publish: source?.auto_publish ?? false,
    });
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone?.() };
        source ? form.put(`/github/${source.id}`, opts) : form.post('/github', opts);
    };
    return (
        <form onSubmit={submit} className="space-y-5">
            <TextInput label="GitHub repository" placeholder="my-company/catalog" value={form.data.repository} onChange={(e) => form.setData('repository', e.target.value)} error={form.errors.repository} help="owner/name, or paste the github.com link." />
            <TextInput
                label="Access token"
                type="password"
                autoComplete="new-password"
                placeholder={source?.has_token ? '•••••••• (saved)' : 'github_pat_… (only for private repositories)'}
                value={form.data.token}
                onChange={(e) => form.setData('token', e.target.value)}
                error={form.errors.token}
                help="Private repositories: a fine-grained token with read-only Contents access. Recommended for public ones too (higher GitHub limits). Stored encrypted."
                optional
            />
            {source?.has_token && <Toggle label="Remove the saved token" checked={form.data.remove_token} onChange={(v) => form.setData('remove_token', v)} />}
            <div className="grid gap-5 sm:grid-cols-2">
                <Select
                    label="What this repository contains"
                    value={form.data.type}
                    onChange={(e) => form.setData('type', e.target.value)}
                    options={[
                        { value: 'core', label: 'The ecommerce app (core)' },
                        { value: 'module', label: 'A module' },
                        { value: 'extension', label: 'A client extension' },
                    ]}
                />
                {form.data.type === 'extension' ? (
                    <Select label="Client" placeholder="Choose…" value={form.data.client_id} onChange={(e) => form.setData('client_id', e.target.value)} error={form.errors.client_id} options={clients.map((c) => ({ value: c.id, label: c.name }))} />
                ) : (
                    <TextInput label="Package file name" value={form.data.asset_pattern} onChange={(e) => form.setData('asset_pattern', e.target.value)} help="Which attached file to use; * = anything." />
                )}
            </div>
            <Toggle label="Import new releases automatically" description="Checks GitHub every hour and imports new releases." checked={form.data.auto_import} onChange={(v) => form.setData('auto_import', v)} />
            <Toggle label="Publish them automatically" description="Off: imported releases wait as drafts for you to check and publish. Deploying is always your decision." checked={form.data.auto_publish} onChange={(v) => form.setData('auto_publish', v)} />
            <Toggle label="Include pre-releases" checked={form.data.include_prereleases} onChange={(v) => form.setData('include_prereleases', v)} />
            <div className="flex justify-end gap-2">
                {onDone && source && (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                )}
                <Button loading={form.processing}>{source ? 'Save' : 'Connect repository'}</Button>
            </div>
        </form>
    );
}

export default function GithubIndex({ sources, selected, releases, error, clients }) {
    const [adding, setAdding] = useState(sources.length === 0);
    const [editing, setEditing] = useState(null);
    const [removing, setRemoving] = useState(null);
    const current = sources.find((s) => s.id === selected);

    return (
        <CentralLayout title="GitHub">
            <PageHeader
                back={{ href: '/releases', label: 'Releases' }}
                title="Releases from GitHub"
                description="Tag a version on GitHub; its release workflow builds the package; import it here with one click, or automatically."
                actions={
                    !adding && (
                        <Button type="button" onClick={() => setAdding(true)}>
                            <Plus size={16} /> Add repository
                        </Button>
                    )
                }
            />

            <div className="grid gap-6">
                {adding && (
                    <Card title="Connect a GitHub repository">
                        <SourceForm clients={clients} onDone={sources.length ? () => setAdding(false) : null} />
                    </Card>
                )}

                {sources.length > 0 && (
                    <Card title="Repositories" padded={false}>
                        <ul className="divide-y divide-zinc-100">
                            {sources.map((s) => (
                                <li key={s.id} className={`px-4 py-4 sm:px-5 ${s.id === selected ? 'bg-zinc-50' : ''}`}>
                                    {editing === s.id ? (
                                        <SourceForm source={s} clients={clients} onDone={() => setEditing(null)} />
                                    ) : (
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-white">
                                                <GitBranch size={17} />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <a href={`https://github.com/${s.repository}`} target="_blank" rel="noopener noreferrer" className="font-semibold break-all hover:underline">
                                                        {s.repository}
                                                    </a>
                                                    <TypeBadge type={s.type} />
                                                    {s.client && <Badge color="amber">{s.client}</Badge>}
                                                    {s.auto_import && <Badge color="blue">Auto import{s.auto_publish ? ' + publish' : ''}</Badge>}
                                                </span>
                                                <span className="block text-xs text-zinc-500">
                                                    {s.releases_count} imported · checked {ago(s.last_checked_at)}
                                                    {s.has_token ? ' · token saved' : ''}
                                                </span>
                                                {s.last_error && <span className="block text-xs text-red-700">{s.last_error}</span>}
                                            </span>
                                            <span className="flex w-full flex-wrap gap-2 sm:w-auto">
                                                <Button variant={s.id === selected ? 'primary' : 'secondary'} href={`/github?source=${s.id}`} preserveScroll className="flex-1 sm:flex-none">
                                                    <RefreshCw size={15} /> Releases
                                                </Button>
                                                <Button variant="secondary" type="button" onClick={() => router.post(`/github/${s.id}/sync`, {}, { preserveScroll: true })} className="flex-1 sm:flex-none">
                                                    <Download size={15} /> Import new
                                                </Button>
                                                <button type="button" onClick={() => setEditing(s.id)} className="rounded-lg p-2.5 text-zinc-500 hover:bg-zinc-100" aria-label="Edit">
                                                    <Pencil size={16} />
                                                </button>
                                                <button type="button" onClick={() => setRemoving(s)} className="rounded-lg p-2.5 text-zinc-500 hover:bg-red-50 hover:text-red-600" aria-label="Remove">
                                                    <Trash2 size={16} />
                                                </button>
                                            </span>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {current && (
                    <Card title={`Releases on GitHub · ${current.repository}`} description="Newest first. Importing downloads the attached package, checks it and signs nothing until you publish." padded={false}>
                        {error && <p className="m-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 sm:m-5">{error}</p>}
                        {releases?.length ? (
                            <ul className="divide-y divide-zinc-100">
                                {releases.map((r) => (
                                    <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 sm:px-5">
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-semibold">{r.tag}</span>
                                                {r.name !== r.tag && <span className="text-sm text-zinc-600">{r.name}</span>}
                                                {r.prerelease && <Badge color="amber">Pre-release</Badge>}
                                            </span>
                                            <span className="block text-xs text-zinc-500">
                                                {ago(r.published_at)}
                                                {r.asset ? ` · ${r.asset.name} (${bytes(r.asset.size)})` : ''}
                                                {r.url && (
                                                    <a href={r.url} target="_blank" rel="noopener noreferrer" className="ml-1 inline-flex items-center gap-0.5 hover:underline">
                                                        · GitHub <ExternalLink size={11} />
                                                    </a>
                                                )}
                                            </span>
                                            {r.problem && <span className="block text-xs text-amber-700">{r.problem}</span>}
                                        </span>
                                        {r.release_id ? (
                                            <Link href={`/releases/${r.release_id}`} className="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 hover:underline">
                                                <CheckCircle2 size={16} /> Imported
                                            </Link>
                                        ) : (
                                            r.asset && (
                                                <Button type="button" onClick={() => router.post(`/github/${current.id}/import/${r.id}`)}>
                                                    <Download size={15} /> Import
                                                </Button>
                                            )
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            !error && <AdminEmpty icon={GitBranch} title="No releases on GitHub yet" message="Push a version tag (e.g. git tag v1.2.0 && git push origin v1.2.0). The release workflow creates the GitHub release with the package." />
                        )}
                    </Card>
                )}
            </div>

            <ConfirmModal open={!!removing} onClose={() => setRemoving(null)} onConfirm={() => router.delete(`/github/${removing.id}`, { onFinish: () => setRemoving(null) })} title={`Remove ${removing?.repository}?`} confirmLabel="Remove" message="Releases already imported from it are kept." />
        </CentralLayout>
    );
}
