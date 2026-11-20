import { Link, router } from '@inertiajs/react';
import { ChevronRight, Pencil, Plus, Server } from 'lucide-react';
import { useState } from 'react';
import { ago, Details, InstallationStatus, ReleaseStatus, TargetStatus } from '@/Components/central';
import { AdminEmpty, Badge, Button, Card, ConfirmModal, PageHeader } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function ClientShow({ client, history, extensions }) {
    const [confirm, setConfirm] = useState(false);
    const suspended = client.status !== 'active';

    return (
        <CentralLayout title={client.name}>
            <PageHeader
                back={{ href: '/clients', label: 'Clients' }}
                title={client.name}
                description={suspended ? 'Suspended: websites keep working but receive no updates.' : `${client.installations.length} installation(s)`}
                actions={
                    <>
                        <Button variant="secondary" href={`/clients/${client.id}/edit`}>
                            <Pencil size={15} /> Edit
                        </Button>
                        <Button variant={suspended ? 'primary' : 'secondary'} type="button" onClick={() => (suspended ? router.patch(`/clients/${client.id}/status`, { status: 'active' }) : setConfirm(true))}>
                            {suspended ? 'Reactivate' : 'Suspend'}
                        </Button>
                    </>
                }
            />

            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <Card
                        title="Installations"
                        description="Each independently hosted website."
                        padded={false}
                        actions={
                            <Link href={`/installations/create?client=${client.id}`} className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-zinc-600 hover:text-zinc-900">
                                <Plus size={15} /> Add
                            </Link>
                        }
                    >
                        {client.installations.length ? (
                            <ul className="divide-y divide-zinc-100">
                                {client.installations.map((i) => (
                                    <li key={i.id}>
                                        <Link href={`/installations/${i.id}`} className="flex items-center gap-3 px-4 py-3.5 hover:bg-zinc-50 sm:px-5">
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium text-zinc-900">{i.name}</span>
                                                    <InstallationStatus status={i.status} />
                                                    {i.is_test && <Badge color="blue">Test site</Badge>}
                                                </span>
                                                <span className="block truncate text-xs text-zinc-500">
                                                    {i.domain} · {i.core_version ? `v${i.core_version}` : 'version unknown'} · {i.modules_count} module(s) · seen {ago(i.last_seen_at)}
                                                </span>
                                            </span>
                                            <ChevronRight size={18} className="shrink-0 text-zinc-300" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <AdminEmpty icon={Server} title="No installations yet" message="Add the client's website to get a connection code for it." action={<Button href={`/installations/create?client=${client.id}`}>Add installation</Button>} />
                        )}
                    </Card>

                    <Card title="Update history" padded={false}>
                        {history.length ? (
                            <ul className="divide-y divide-zinc-100">
                                {history.map((t) => (
                                    <li key={t.id}>
                                        <Link href={`/deployments/${t.deployment_id}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm hover:bg-zinc-50 sm:px-5">
                                            <span className="min-w-0 flex-1">
                                                <span className="font-medium">
                                                    {t.deployment.release.type === 'core' ? 'Core' : t.deployment.release.slug} {t.deployment.release.version}
                                                </span>
                                                <span className="text-zinc-500"> → {t.installation.name}</span>
                                            </span>
                                            <TargetStatus status={t.status} />
                                            <span className="text-xs text-zinc-400">{ago(t.updated_at)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-6 text-center text-sm text-zinc-400">No updates sent yet.</p>
                        )}
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card title="Details">
                        <Details
                            rows={[
                                ['Contact', client.contact_name],
                                ['Email', client.email && <a href={`mailto:${client.email}`} className="hover:underline">{client.email}</a>],
                                ['Phone', client.phone],
                                ['Website', client.website],
                                ['Hosting', client.hosting_provider],
                                ['Client key', <code className="font-mono text-xs break-all">{client.key}</code>],
                            ]}
                        />
                        {client.hosting_notes && <p className="mt-3 rounded-lg bg-zinc-50 p-3 text-sm whitespace-pre-line text-zinc-700">{client.hosting_notes}</p>}
                        {client.notes && <p className="mt-3 text-sm whitespace-pre-line text-zinc-600">{client.notes}</p>}
                    </Card>
                    <Card title="Client extensions" description="Custom code made only for this client.">
                        {extensions.length ? (
                            <ul className="space-y-2">
                                {extensions.map((r) => (
                                    <li key={r.id}>
                                        <Link href={`/releases/${r.id}`} className="flex items-center justify-between gap-2 text-sm hover:text-zinc-600">
                                            <span className="truncate font-medium">
                                                {r.slug} {r.version}
                                            </span>
                                            <ReleaseStatus status={r.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-zinc-400">None. Upload a package with "type": "extension" to add one.</p>
                        )}
                    </Card>
                </div>
            </div>

            <ConfirmModal
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => router.patch(`/clients/${client.id}/status`, { status: 'suspended' }, { onFinish: () => setConfirm(false) })}
                title={`Suspend ${client.name}?`}
                confirmLabel="Suspend"
                message="Their websites keep working normally, but they stop receiving updates until reactivated. Queued updates are held."
            />
        </CentralLayout>
    );
}
