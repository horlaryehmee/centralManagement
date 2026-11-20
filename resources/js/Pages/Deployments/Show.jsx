import { Link, router } from '@inertiajs/react';
import { RotateCcw, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ago, dateTime, DeploymentStatus, EventList, TARGET, TargetStatus } from '@/Components/central';
import { Badge, Button, Card, ConfirmModal, PageHeader } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

const FINAL = ['completed', 'completed_with_failures', 'failed', 'cancelled'];

export default function DeploymentShow({ deployment: d, release, targets, events, counts }) {
    const [confirm, setConfirm] = useState(null);
    const live = !FINAL.includes(d.status);

    // Live monitoring: refresh while websites are still working on it.
    useEffect(() => {
        if (!live) return;
        const t = setInterval(() => router.reload({ only: ['deployment', 'targets', 'events', 'counts'] }), 5000);
        return () => clearInterval(t);
    }, [live]);

    const cancellable = targets.some((t) => ['waiting_canary', 'queued', 'retry_wait'].includes(t.status));
    const retryable = targets.some((t) => ['failed', 'no_response'].includes(t.status));

    return (
        <CentralLayout title={`Deployment #${d.id}`}>
            <PageHeader
                back={{ href: '/deployments', label: 'Deployments' }}
                title={`${release.label} · #${d.id}`}
                description={d.scheduled_at && d.status === 'scheduled' ? `Scheduled for ${dateTime(d.scheduled_at)}` : `Started ${dateTime(d.started_at || d.created_at)} by ${d.creator?.name || 'system'}`}
                actions={
                    <>
                        {retryable && (
                            <Button variant="secondary" type="button" onClick={() => router.post(`/deployments/${d.id}/retry`, {}, { preserveScroll: true })}>
                                <RotateCcw size={15} /> Retry failed
                            </Button>
                        )}
                        {cancellable && (
                            <Button variant="danger" type="button" onClick={() => setConfirm({ type: 'cancel' })}>
                                Cancel pending
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <DeploymentStatus status={d.status} />
                {Object.entries(counts).map(([s, n]) => (
                    <span key={s} className="rounded-full bg-white px-2.5 py-0.5 text-xs text-zinc-600 ring-1 ring-zinc-200">
                        {TARGET[s]?.[1] || s}: <strong>{n}</strong>
                    </span>
                ))}
                {live && <span className="text-xs text-zinc-400">Updating live…</span>}
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                <Card title="Websites" padded={false}>
                    <ul className="divide-y divide-zinc-100">
                        {targets.map((t) => (
                            <li key={t.id} className="px-4 py-3.5 sm:px-5">
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <Link href={`/installations/${t.installation.id}`} className="min-w-0 flex-1 hover:underline">
                                        <span className="flex flex-wrap items-center gap-2 font-medium">
                                            {t.installation.name}
                                            {t.is_canary && <Badge color="blue">Tested first</Badge>}
                                            {!t.online && <Badge color="amber">Offline</Badge>}
                                        </span>
                                        <span className="block text-xs text-zinc-500">
                                            {t.installation.client?.name} · {t.from_version || 'new'} → {t.to_version}
                                            {t.attempts > 0 ? ` · attempt ${t.attempts}` : ''}
                                        </span>
                                    </Link>
                                    <TargetStatus status={t.status} />
                                    {t.status === 'succeeded' && (
                                        <button type="button" onClick={() => setConfirm({ type: 'rollback', target: t })} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900">
                                            <Undo2 size={14} /> Roll back
                                        </button>
                                    )}
                                </div>
                                {['delivered', 'running', 'rolling_back'].includes(t.status) && t.step && <p className="mt-1 text-xs text-sky-700">Step: {t.step} · last report {ago(t.last_report_at)}</p>}
                                {t.status === 'queued' && <p className="mt-1 text-xs text-zinc-500">Waiting for the website's next check-in (every {t.installation.check_interval} min; last {ago(t.installation.last_seen_at)}).</p>}
                                {t.status === 'retry_wait' && <p className="mt-1 text-xs text-amber-700">Retrying {ago(t.next_attempt_at)}.</p>}
                                {t.error && t.status !== 'succeeded' && <p className="mt-1 text-xs whitespace-pre-line text-red-700">{t.error}</p>}
                            </li>
                        ))}
                    </ul>
                </Card>
                <div className="space-y-6">
                    {d.notes && (
                        <Card title="Notes">
                            <p className="text-sm whitespace-pre-line text-zinc-700">{d.notes}</p>
                        </Card>
                    )}
                    {d.selection?.skipped?.length > 0 && (
                        <Card title="Skipped when created">
                            <ul className="space-y-2 text-sm">
                                {d.selection.skipped.map((s) => (
                                    <li key={s.id}>
                                        <span className="font-medium">{s.name}</span>
                                        <span className="block text-xs text-zinc-500">{s.reasons.join(' ')}</span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                    <Card title="Log">
                        <EventList events={events} />
                    </Card>
                </div>
            </div>

            <ConfirmModal open={confirm?.type === 'cancel'} onClose={() => setConfirm(null)} onConfirm={() => router.post(`/deployments/${d.id}/cancel`, {}, { onFinish: () => setConfirm(null), preserveScroll: true })} title="Cancel pending updates?" confirmLabel="Cancel them" message="Websites that haven't picked it up yet won't get it. Updates already installing are not interrupted (that would not be safe); they finish and report back." />
            <ConfirmModal
                open={confirm?.type === 'rollback'}
                onClose={() => setConfirm(null)}
                onConfirm={() => router.post(`/targets/${confirm.target.id}/rollback`, {}, { onFinish: () => setConfirm(null), preserveScroll: true })}
                title={`Roll back ${confirm?.target?.installation.name}?`}
                confirmLabel="Roll back"
                message={`The website restores ${confirm?.target?.from_version ? `version ${confirm.target.from_version}` : 'its previous state'} from the backup it made before updating${release.database_rollback ? ', including undoing this release\'s database changes' : ' (files only; database changes stay)'}.`}
            />
        </CentralLayout>
    );
}
