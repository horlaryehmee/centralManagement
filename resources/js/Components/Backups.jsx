import { router } from '@inertiajs/react';
import { DatabaseBackup, Download, Loader2, Lock, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ago, dateTime } from '@/Components/central';
import { Badge, Button, Card, ConfirmModal, Modal, Toggle } from '@/Components/ui';

const COLOR = { requested: 'blue', delivered: 'blue', uploading: 'blue', completed: 'green', failed: 'red', expired: 'amber' };

/**
 * Website backups: ask the website for one, then download or delete it.
 * The website creates it at its next check-in, so this page refreshes itself while one is on its way.
 */
export default function Backups({ installation, backups }) {
    const [asking, setAsking] = useState(false);
    const [includeFiles, setIncludeFiles] = useState(true);
    const [busy, setBusy] = useState(false);
    const [remove, setRemove] = useState(null);
    const active = backups.items.some((b) => b.active);

    // Refresh while a backup is on its way (the website does the work, not this page).
    useEffect(() => {
        if (!active) return;
        const t = setInterval(() => router.reload({ only: ['backups', 'events'] }), 15000);
        return () => clearInterval(t);
    }, [active]);

    const request = () =>
        router.post(`/installations/${installation.id}/backups`, { include_files: includeFiles }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => (setBusy(false), setAsking(false)) });

    return (
        <Card
            title="Backups"
            description={backups.supported ? `Created by the website and stored here, encrypted. The newest ${backups.keep} are kept.` : null}
            actions={
                backups.supported &&
                ['active', 'suspended'].includes(installation.status) && (
                    <Button type="button" onClick={() => setAsking(true)} disabled={active} className="!h-9 shrink-0 text-sm">
                        <DatabaseBackup size={15} /> Create backup
                    </Button>
                )
            }
            padded={false}
        >
            {!backups.supported ? (
                <p className="px-5 py-6 text-sm text-zinc-500">This website’s version can’t make backups from here yet. Deploy the latest core version to it, then this button appears.</p>
            ) : backups.items.length === 0 ? (
                <p className="px-5 py-6 text-center text-sm text-zinc-400">No backups yet.</p>
            ) : (
                <ul className="divide-y divide-zinc-100">
                    {backups.items.map((b) => (
                        <li key={b.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 text-sm sm:px-5">
                            <span className="min-w-0 flex-1">
                                <span className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium text-zinc-900">{b.completed_at ? dateTime(b.completed_at) : `Requested ${ago(b.created_at)}`}</span>
                                    <Badge color={COLOR[b.status] || 'gray'}>
                                        {b.active && <Loader2 size={11} className="mr-1 inline animate-spin" />}
                                        {b.status_label}
                                        {b.progress ? ` · ${b.progress}` : ''}
                                    </Badge>
                                </span>
                                <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-zinc-500">
                                    <span>{b.include_files ? 'Database and files' : 'Database only'}</span>
                                    {b.size && <span>· {b.size}</span>}
                                    {b.contents?.files > 0 && <span>· {b.contents.files.toLocaleString()} files</span>}
                                    {b.contents?.version && <span>· version {b.contents.version}</span>}
                                    {b.encrypted && (
                                        <span className="inline-flex items-center gap-0.5">
                                            · <Lock size={11} /> encrypted
                                        </span>
                                    )}
                                    {b.requested_by && <span>· by {b.requested_by}</span>}
                                </span>
                                {b.status === 'requested' && <span className="mt-0.5 block text-xs text-zinc-500">The website creates it at its next check-in (every {installation.check_interval} minutes).</span>}
                                {b.error && <span className="mt-0.5 block text-xs text-red-600">{b.error}</span>}
                            </span>
                            <span className="flex shrink-0 gap-1.5">
                                {b.download_url && (
                                    <a href={b.download_url} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-zinc-300 px-3 text-sm font-medium text-zinc-800 hover:bg-zinc-50">
                                        <Download size={15} /> Download
                                    </a>
                                )}
                                <button type="button" onClick={() => setRemove(b)} className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 hover:bg-red-50 hover:text-red-600" aria-label={b.active ? 'Cancel backup' : 'Delete backup'}>
                                    <Trash2 size={16} />
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <Modal
                open={asking}
                onClose={() => setAsking(false)}
                title="Create a backup of this website?"
                footer={
                    <>
                        <Button type="button" variant="secondary" onClick={() => setAsking(false)}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={request} loading={busy}>
                            Create backup
                        </Button>
                    </>
                }
            >
                <div className="space-y-4 text-sm text-zinc-600">
                    <p>The website makes the backup at its next check-in and sends it here. The shop keeps working while it’s made.</p>
                    <Toggle label="Include uploaded files" description="Product photos, banners, logos and payment receipts. Turn off for a smaller, database-only backup." checked={includeFiles} onChange={setIncludeFiles} />
                    <p className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900">Backups contain the shop’s customers and orders. They are stored encrypted, and only signed-in central admins can download them.</p>
                </div>
            </Modal>

            <ConfirmModal
                open={!!remove}
                onClose={() => setRemove(null)}
                onConfirm={() => router.delete(`/backups/${remove.id}`, { preserveScroll: true, onFinish: () => setRemove(null) })}
                title={remove?.active ? 'Cancel this backup?' : 'Delete this backup?'}
                confirmLabel={remove?.active ? 'Cancel backup' : 'Delete'}
                message={remove?.active ? 'The website stops sending it.' : 'The backup file is deleted from the update server. This can’t be undone.'}
            />
        </Card>
    );
}
