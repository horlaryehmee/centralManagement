import { Link, router, usePage } from '@inertiajs/react';
import { Copy, KeyRound, Pencil, Rocket } from 'lucide-react';
import { useState } from 'react';
import { ago, dateTime, Details, EventList, InstallationStatus, TargetStatus } from '@/Components/central';
import { ActionMenu, Badge, Button, Card, ConfirmModal, PageHeader } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

function CopyField({ label, value }) {
    const [copied, setCopied] = useState(false);
    return (
        <div>
            <p className="mb-1 text-xs font-medium text-sky-900">{label}</p>
            <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 rounded-lg bg-white px-3 py-2 font-mono text-sm break-all text-zinc-900 ring-1 ring-sky-200">{value}</code>
                <button
                    type="button"
                    onClick={() => navigator.clipboard?.writeText(value).then(() => (setCopied(true), setTimeout(() => setCopied(false), 1500)))}
                    className="shrink-0 rounded-lg bg-white p-2 text-sky-800 ring-1 ring-sky-200 hover:bg-sky-100"
                    aria-label={`Copy ${label}`}
                >
                    {copied ? <span className="text-xs font-semibold">Copied</span> : <Copy size={16} />}
                </button>
            </div>
        </div>
    );
}

export default function InstallationShow({ installation: i, deployments, events }) {
    const { flash } = usePage().props;
    const [confirm, setConfirm] = useState(null);
    const enrollment = flash?.enrollment;

    const actions = {
        suspend: { title: 'Suspend updates?', label: 'Suspend', message: 'The website keeps working and keeps reporting in, but receives no updates until you activate it again.', run: () => router.patch(`/installations/${i.id}/status`, { action: 'suspend' }) },
        revoke: { title: 'Revoke access?', label: 'Revoke', message: 'Its credentials stop working immediately. The website keeps working normally but can no longer contact the update server. To reconnect, create a new connection code.', run: () => router.patch(`/installations/${i.id}/status`, { action: 'revoke' }) },
        code: { title: 'Create a new connection code?', label: 'Create code', message: 'The current credentials stop working. The website must be connected again with the new code (Updates → Update server).', run: () => router.post(`/installations/${i.id}/enrollment`) },
    };

    return (
        <CentralLayout title={i.name}>
            <PageHeader
                back={{ href: `/clients/${i.client.id}`, label: i.client.name }}
                title={i.name}
                description={i.domain}
                actions={
                    <>
                        {i.status === 'active' && (
                            <Button href={`/deployments/create?installations[]=${i.id}`}>
                                <Rocket size={16} /> Deploy
                            </Button>
                        )}
                        <Button variant="secondary" href={`/installations/${i.id}/edit`}>
                            <Pencil size={15} /> Edit
                        </Button>
                        <ActionMenu
                            items={[
                                i.status === 'suspended' && { label: 'Activate updates', onClick: () => router.patch(`/installations/${i.id}/status`, { action: 'activate' }) },
                                i.status === 'active' && { label: 'Suspend updates', onClick: () => setConfirm('suspend') },
                                ['active', 'suspended'].includes(i.status) && { label: 'Revoke access', danger: true, onClick: () => setConfirm('revoke') },
                                { label: 'New connection code', icon: KeyRound, onClick: () => setConfirm('code') },
                            ]}
                        />
                    </>
                }
            />

            {enrollment && (
                <div className="mb-6 space-y-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 sm:p-5">
                    <p className="font-semibold text-sky-950">Connection code (shown only once)</p>
                    <p className="text-sm text-sky-900">
                        In the website's admin open <strong>Updates → Update server</strong> and enter these. The code works once and expires {dateTime(enrollment.expires_at)}.
                    </p>
                    <CopyField label="Update server address" value={enrollment.url} />
                    <CopyField label="Connection code" value={enrollment.code} />
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                <div className="space-y-6">
                    <Card title="Deployment history" padded={false}>
                        {deployments.length ? (
                            <ul className="divide-y divide-zinc-100">
                                {deployments.map((t) => (
                                    <li key={t.id}>
                                        <Link href={`/deployments/${t.deployment_id}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm hover:bg-zinc-50 sm:px-5">
                                            <span className="min-w-0 flex-1">
                                                <span className="font-medium">
                                                    {t.deployment.release.type === 'core' ? 'Core' : t.deployment.release.slug} {t.from_version ? `${t.from_version} → ` : ''}
                                                    {t.to_version}
                                                </span>
                                                {t.error && <span className="block truncate text-xs text-red-600">{t.error}</span>}
                                            </span>
                                            <TargetStatus status={t.status} />
                                            <span className="text-xs text-zinc-400">{ago(t.updated_at)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-6 text-center text-sm text-zinc-400">No updates sent to this website yet.</p>
                        )}
                    </Card>
                    <Card title="Activity">
                        <EventList events={events} showInstallation={false} />
                    </Card>
                </div>
                <div className="space-y-6">
                    <Card title="Status">
                        <Details
                            rows={[
                                ['Status', <span className="flex flex-wrap gap-1.5"><InstallationStatus status={i.status} online={i.online} />{i.is_test && <Badge color="blue">Test site</Badge>}</span>],
                                ['Core version', i.core_version],
                                ['PHP', i.php_version],
                                ['Framework', i.framework_version],
                                ['Last check-in', i.last_seen_at ? `${ago(i.last_seen_at)} (${i.last_ip || '?'})` : 'Never'],
                                ['Check interval', `${i.check_interval} min`],
                                ['Environment', i.environment],
                                ['Connected', i.registered_at ? dateTime(i.registered_at) : i.status === 'pending' ? `Code valid until ${dateTime(i.enrollment_expires_at)}` : '—'],
                                ['Installation ID', <code className="font-mono text-xs break-all">{i.uuid}</code>],
                            ]}
                        />
                        {i.server && Object.keys(i.server).length > 0 && (
                            <details className="mt-3">
                                <summary className="cursor-pointer text-sm font-medium text-zinc-600">Server details</summary>
                                <Details rows={Object.entries(i.server).map(([k, v]) => [k.replace(/_/g, ' '), typeof v === 'object' ? JSON.stringify(v) : String(v)])} />
                            </details>
                        )}
                    </Card>
                    <Card title="Modules & extensions">
                        {i.modules.length ? (
                            <ul className="space-y-2.5">
                                {i.modules.map((m) => (
                                    <li key={m.slug} className="flex items-start justify-between gap-3 text-sm">
                                        <span className="min-w-0">
                                            <span className="block font-medium text-zinc-900">{m.name || m.slug}</span>
                                            <span className="block text-xs text-zinc-500">
                                                {m.type === 'extension' ? 'Client extension' : 'Module'}
                                                {m.requires?.core ? ` · needs core ${m.requires.core}` : ''}
                                                {!m.enabled ? ' · disabled' : ''}
                                            </span>
                                        </span>
                                        <span className="shrink-0 font-semibold tabular-nums">{m.version}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-zinc-400">None reported.</p>
                        )}
                    </Card>
                    {i.notes && (
                        <Card title="Notes">
                            <p className="text-sm whitespace-pre-line text-zinc-700">{i.notes}</p>
                        </Card>
                    )}
                </div>
            </div>

            {confirm && (
                <ConfirmModal
                    open
                    onClose={() => setConfirm(null)}
                    onConfirm={() => {
                        actions[confirm].run();
                        setConfirm(null);
                    }}
                    title={actions[confirm].title}
                    confirmLabel={actions[confirm].label}
                    message={actions[confirm].message}
                />
            )}
        </CentralLayout>
    );
}
