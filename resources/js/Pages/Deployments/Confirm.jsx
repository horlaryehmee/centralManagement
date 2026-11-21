import { useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';
import { Badge, Button, Card, PageHeader, Select, Textarea, TextInput, Toggle } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function DeploymentConfirm({ release, rows, selection }) {
    const ok = rows.filter((r) => r.ok);
    const blocked = rows.filter((r) => !r.ok);
    const defaultCanary = ok.length > 1 ? ok.find((r) => r.is_test)?.id ?? '' : '';
    const form = useForm({
        release_id: release.id,
        installations: ok.map((r) => r.id),
        scheduled_at: '',
        canary_installation_id: defaultCanary ? String(defaultCanary) : '',
        max_attempts: 3,
        allow_modified_files: false,
        notes: '',
        selection,
    });

    return (
        <CentralLayout title="Confirm deployment">
            <PageHeader back={{ href: `/deployments/create?release=${release.id}`, label: 'Change selection' }} title={`Deploy ${release.label}?`} description={`${ok.length} website(s) will receive it${blocked.length ? `, ${blocked.length} can't` : ''}.`} />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    // transform() returns nothing in Inertia 2, so it can't be chained with .post().
                    form.transform((d) => ({ ...d, scheduled_at: d.scheduled_at ? new Date(d.scheduled_at).toISOString() : null, canary_installation_id: d.canary_installation_id || null }));
                    form.post('/deployments');
                }}
                className="grid gap-6 lg:grid-cols-[1fr_360px]"
            >
                <div className="space-y-6">
                    <Card title={`Will be updated (${ok.length})`} padded={false}>
                        {ok.length ? (
                            <ul className="divide-y divide-zinc-100">
                                {ok.map((r) => (
                                    <li key={r.id} className="flex items-center gap-3 px-4 py-3 text-sm sm:px-5">
                                        <CheckCircle2 size={18} className="shrink-0 text-emerald-600" />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2 font-medium">
                                                {r.name} {r.is_test && <Badge color="blue">Test site</Badge>} {!r.online && <Badge color="amber">Offline: will update when it checks in</Badge>}
                                            </span>
                                            <span className="block truncate text-xs text-zinc-500">
                                                {r.client} · {r.domain}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs text-zinc-600 tabular-nums">{r.action === 'install' ? 'New install' : `${r.current} → ${release.version}`}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-6 text-center text-sm text-zinc-500">None of the selected websites can take this release.</p>
                        )}
                    </Card>
                    {blocked.length > 0 && (
                        <Card title={`Skipped (${blocked.length})`} description="Not compatible right now; nothing will be sent to these." padded={false}>
                            <ul className="divide-y divide-zinc-100">
                                {blocked.map((r) => (
                                    <li key={r.id} className="flex gap-3 px-4 py-3 text-sm sm:px-5">
                                        <XCircle size={18} className="mt-0.5 shrink-0 text-red-500" />
                                        <span className="min-w-0">
                                            <span className="block font-medium">{r.name}</span>
                                            {r.reasons.map((reason) => (
                                                <span key={reason} className="block text-xs text-red-700">
                                                    {reason}
                                                </span>
                                            ))}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card title="Options">
                        <div className="space-y-5">
                            <TextInput label="Schedule for" type="datetime-local" value={form.data.scheduled_at} onChange={(e) => form.setData('scheduled_at', e.target.value)} error={form.errors.scheduled_at} help="Leave empty to start now. Websites pick it up at their next check-in." optional />
                            {ok.length > 1 && (
                                <Select
                                    label="Test on one website first"
                                    placeholder="No, send to all at once"
                                    value={form.data.canary_installation_id}
                                    onChange={(e) => form.setData('canary_installation_id', e.target.value)}
                                    error={form.errors.canary_installation_id}
                                    options={ok.map((r) => ({ value: String(r.id), label: `${r.name}${r.is_test ? ' (test site)' : ''}` }))}
                                    help="The others only receive it after this one reports success."
                                />
                            )}
                            <Select label="Automatic retries" value={form.data.max_attempts} onChange={(e) => form.setData('max_attempts', e.target.value)} options={[1, 2, 3, 5].map((n) => ({ value: n, label: n === 1 ? 'No retries' : `Up to ${n} attempts` }))} help="For temporary problems such as a download failure. Errors like an incompatible module are not retried." />
                            {release.type === 'core' && (
                                <Toggle label="Overwrite locally modified core files" description="Off (recommended): a website whose core files were edited by hand refuses the update and lists the changed files, so customisations are never lost." checked={form.data.allow_modified_files} onChange={(v) => form.setData('allow_modified_files', v)} />
                            )}
                            <Textarea label="Notes" rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} optional />
                        </div>
                    </Card>
                    {release.has_migrations && (
                        <p className="flex gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                            <AlertTriangle size={16} className="mt-0.5 shrink-0" /> Includes database changes. Each website backs up its database first{release.database_rollback ? '; they can be rolled back.' : '; a rollback restores files only.'}
                        </p>
                    )}
                    <Button loading={form.processing} disabled={!ok.length} className="w-full">
                        {form.data.scheduled_at ? 'Schedule deployment' : `Deploy to ${ok.length} website${ok.length === 1 ? '' : 's'}`}
                    </Button>
                </div>
            </form>
        </CentralLayout>
    );
}
