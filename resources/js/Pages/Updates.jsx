import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, Circle, Download, GitBranch, GitCommitHorizontal, History, Loader2, PowerOff, RefreshCw, RotateCcw, ShieldCheck, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, Card, ConfirmModal, FormActions, Modal, PageHeader, TextInput, Toggle } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

const STEP_LABELS = {
    fetch: 'Getting the latest version from GitHub',
    backup: 'Backing up the database',
    pull: 'Updating the files',
    dependencies: 'Installing PHP packages (Composer, if needed)',
    finish: 'Running migrations and finishing',
};

/** POST one step; turns host timeouts and errors into plain messages. */
async function post(url, data = {}) {
    try {
        return (await axios.post(url, data, { headers: { Accept: 'application/json' } })).data;
    } catch (e) {
        const res = e.response;
        if (!res) throw new Error('Lost the connection to the server. Check your internet connection, then press Try again.');
        if (res.data?.message) throw new Error(res.data.message);
        if (res.status === 419) throw new Error('Your session expired. Reload this page and try again.');
        if ([502, 503, 504, 524].includes(res.status)) throw new Error('This step took longer than your host allows for one request. Press Try again: it continues where it stopped.');
        throw new Error(`The server returned an error (${res.status}). Press Try again.`);
    }
}

const date = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');
const nextStep = (steps, done) => (done ? steps[steps.indexOf(done) + 1] : steps[0]);

function Progress({ steps, states, error }) {
    return (
        <ol className="space-y-3">
            {steps.map((s) => {
                const st = states[s] || 'waiting';
                return (
                    <li key={s} className="flex items-start gap-3">
                        <span className="mt-0.5 shrink-0">
                            {st === 'done' && <CheckCircle2 size={20} className="text-emerald-600" />}
                            {st === 'running' && <Loader2 size={20} className="animate-spin text-zinc-900" />}
                            {st === 'error' && <XCircle size={20} className="text-red-600" />}
                            {st === 'waiting' && <Circle size={20} className="text-zinc-300" />}
                        </span>
                        <span className="min-w-0">
                            <span className={`block text-[15px] sm:text-sm ${st === 'waiting' ? 'text-zinc-400' : 'font-medium text-zinc-900'}`}>{STEP_LABELS[s] || s}</span>
                            {st === 'done' && states[`${s}:msg`] && <span className="block text-xs text-zinc-500">{states[`${s}:msg`]}</span>}
                            {st === 'error' && error && <span className="mt-1 block text-sm text-red-700">{error}</span>}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

function Checks({ checks }) {
    const failing = checks.filter((c) => !c.ok);
    return (
        <Card title="Server readiness" description={failing.length ? 'Fix these before updating.' : 'Your server is ready for one-click updates from GitHub.'}>
            <ul className="space-y-2.5">
                {checks.map((c) => (
                    <li key={c.label} className="flex items-start gap-2.5 text-sm">
                        {c.ok ? <CheckCircle2 size={18} className="mt-px shrink-0 text-emerald-600" /> : <XCircle size={18} className="mt-px shrink-0 text-red-600" />}
                        <span className="min-w-0">
                            <span className="block break-all text-zinc-800">{c.label}</span>
                            {!c.ok && <span className="block text-xs text-zinc-500">{c.detail}</span>}
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function Source({ source }) {
    const form = useForm({ repository: source.repository || '', branch: source.branch || '', token: '', remove_token: false });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put('/updates/source', { preserveScroll: true, onSuccess: () => form.setData((d) => ({ ...d, token: '', remove_token: false })) });
            }}
        >
            <Card title="Update source" description="The GitHub repository and branch this app updates from.">
                <div className="space-y-5">
                    <TextInput
                        label="GitHub repository"
                        placeholder={source.env_repository || 'owner/repository'}
                        value={form.data.repository}
                        onChange={(e) => form.setData('repository', e.target.value)}
                        error={form.errors.repository}
                        help={source.env_repository && !form.data.repository ? `Leave blank to use ${source.env_repository} (from the server configuration).` : 'For example my-company/central-management, or the full github.com link.'}
                        optional={!!source.env_repository}
                    />
                    <TextInput label="Branch" placeholder={source.env_branch || 'main'} value={form.data.branch} onChange={(e) => form.setData('branch', e.target.value)} error={form.errors.branch} help="Leave blank for main." optional />
                    <TextInput
                        label="Access token"
                        type="password"
                        autoComplete="new-password"
                        placeholder={source.has_token ? '•••••••• (saved)' : source.env_token ? '(set in server configuration)' : 'github_pat_…'}
                        value={form.data.token}
                        onChange={(e) => form.setData('token', e.target.value)}
                        error={form.errors.token}
                        help="Needed for a private repository: a fine-grained token with read-only access to the repository's Contents. Stored encrypted and never written into Git's files."
                        optional
                    />
                    {source.has_token && <Toggle label="Remove the saved token" checked={form.data.remove_token} onChange={(v) => form.setData('remove_token', v)} />}
                </div>
                <FormActions dirty={form.isDirty}>
                    <Button loading={form.processing}>Save</Button>
                </FormActions>
            </Card>
        </form>
    );
}

function Backups({ backups, onRollback }) {
    const [confirm, setConfirm] = useState(null);
    if (!backups.length) return null;
    const later = (b) => backups.filter((o) => o.id >= b.id).length;
    return (
        <Card title="Previous versions" description="Saved automatically before each update, so you can go back with one click." padded={false}>
            <ul className="divide-y divide-zinc-100">
                {backups.map((b) => (
                    <li key={b.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 sm:px-5">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500">
                            <History size={17} />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-zinc-900">
                                <code className="font-mono">{b.short}</code> {b.subject}
                            </span>
                            <span className="block text-xs text-zinc-500">
                                {b.replaced_by ? `Replaced by ${b.replaced_by}` : 'Backup'}
                                {b.created_at ? ` · ${date(b.created_at)}` : ''}
                            </span>
                        </span>
                        <span className="flex w-full gap-2 sm:w-auto">
                            {b.database_size != null && (
                                <a href={`/updates/backups/${b.id}/database`} className="inline-flex h-10 flex-1 items-center justify-center gap-1.5 rounded-xl border border-zinc-300 px-3 text-sm font-medium text-zinc-800 hover:bg-zinc-50 sm:h-9 sm:flex-none sm:rounded-lg">
                                    <Download size={15} /> Database
                                </a>
                            )}
                            <button type="button" onClick={() => setConfirm(b)} className="inline-flex h-10 flex-1 items-center justify-center gap-1.5 rounded-xl border border-zinc-300 px-3 text-sm font-medium text-zinc-800 hover:bg-zinc-50 sm:h-9 sm:flex-none sm:rounded-lg">
                                <RotateCcw size={15} /> Roll back
                            </button>
                        </span>
                    </li>
                ))}
            </ul>
            <ConfirmModal
                open={!!confirm}
                onClose={() => setConfirm(null)}
                onConfirm={() => {
                    onRollback(confirm);
                    setConfirm(null);
                }}
                title={confirm ? `Roll back to ${confirm.short}?` : ''}
                confirmLabel="Roll back"
                message={
                    confirm
                        ? `This undoes ${later(confirm) > 1 ? `the last ${later(confirm)} updates` : 'the last update'}: their database changes are undone and the files go back to ${confirm.short}. Central Management is offline for a few seconds. Clients, websites, releases and backups stay. The database backup can still be downloaded first.`
                        : ''
                }
            />
        </Card>
    );
}

export default function Updates({ status, connected, justConnected, checks, pending, down, backups, steps, source }) {
    const ready = checks.every((c) => c.ok);
    const [states, setStates] = useState({});
    const [running, setRunning] = useState(false);
    const [error, setError] = useState(null);
    const [failed, setFailed] = useState(null);
    const [done, setDone] = useState(null);
    const [confirm, setConfirm] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [rollingBack, setRollingBack] = useState(null);
    const active = running || failed || done || Object.keys(states).length > 0;
    const changed = !justConnected ? status.changed_files || [] : [];

    const run = async (from) => {
        setRunning(true);
        setError(null);
        setFailed(null);
        const start = steps.indexOf(from);
        setStates((s) => {
            const next = { ...s };
            steps.forEach((st, i) => i < start && (next[st] = 'done'));
            return next;
        });
        for (const step of steps.slice(start)) {
            setStates((s) => ({ ...s, [step]: 'running' }));
            try {
                const res = await post(`/updates/step/${step}`, step === 'fetch' ? { discard } : {});
                setStates((s) => ({ ...s, [step]: 'done', [`${step}:msg`]: res.message }));
                if (step === 'finish') setDone(res);
            } catch (e) {
                setStates((s) => ({ ...s, [step]: 'error' }));
                setError(e.message);
                setFailed(step);
                setRunning(false);
                return;
            }
        }
        setRunning(false);
    };

    // After an error, ask where it got to (a step may have finished after a timeout) and carry on.
    const retry = () =>
        router.reload({
            only: ['pending', 'down'],
            onSuccess: (page) => {
                const p = page.props.pending;
                if (!p && ['finish', 'dependencies'].includes(failed)) return (window.location.href = '/updates');
                run(p ? nextStep(steps, p.step) : 'fetch');
            },
        });

    const rollback = async (b) => {
        setRollingBack(b.short);
        try {
            await post(`/updates/backups/${b.id}/rollback`);
            window.location.href = '/updates';
        } catch (e) {
            setRollingBack(null);
            setError(e.message);
        }
    };

    return (
        <CentralLayout title="Updates">
            <PageHeader
                title="Updates"
                description={status.current ? `Running ${status.current.short} · ${status.current.subject}` : 'Updates come straight from your GitHub repository.'}
                actions={
                    !active &&
                    connected && (
                        <Button variant="secondary" href="/updates?refresh=1" preserveScroll>
                            <RefreshCw size={16} /> Check for updates
                        </Button>
                    )
                }
            />

            <div className="grid max-w-3xl gap-6">
                {down && !running && (
                    <div className="flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center">
                        <PowerOff size={20} className="shrink-0 text-amber-700" />
                        <p className="min-w-0 flex-1 text-sm text-amber-900">Central Management is in maintenance mode: websites checking in are asked to try again later.</p>
                        <Button type="button" variant="secondary" onClick={() => router.post('/updates/online', {}, { preserveScroll: true })}>
                            Bring it back online
                        </Button>
                    </div>
                )}

                {pending && !active && (
                    <Card title="An update was not finished" description={`Update to ${pending.to} stopped after: ${STEP_LABELS[pending.step]?.toLowerCase() || pending.step}.`}>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button type="button" onClick={() => run(nextStep(steps, pending.step))}>
                                Continue the update
                            </Button>
                            {!['pull', 'dependencies'].includes(pending.step) && (
                                <Button type="button" variant="secondary" onClick={() => router.post('/updates/cancel', {}, { preserveScroll: true })}>
                                    Cancel it
                                </Button>
                            )}
                        </div>
                    </Card>
                )}

                {rollingBack && (
                    <Card>
                        <p className="flex items-center gap-3 text-sm font-medium text-zinc-900">
                            <Loader2 size={18} className="animate-spin" /> Rolling back to {rollingBack}…
                        </p>
                    </Card>
                )}

                {!connected ? (
                    <Card>
                        <div className="flex items-start gap-3">
                            <GitBranch size={22} className="mt-0.5 shrink-0 text-zinc-700" />
                            <div className="min-w-0">
                                <p className="font-semibold text-zinc-900">Connect this app to GitHub</p>
                                <p className="mt-1 text-sm text-zinc-600">
                                    This folder was installed from a zip, so it isn’t a Git copy yet. Connecting makes it one <strong>without changing any file</strong>; after that, “Update now” brings it to the latest version of{' '}
                                    <strong>{source.effective ? `${source.effective} (${source.effective_branch})` : 'your repository'}</strong>. Your .env and stored files are never touched.
                                </p>
                                <Button type="button" className="mt-4" disabled={!source.effective || !ready} onClick={() => router.post('/updates/connect', {}, { preserveScroll: true })}>
                                    <GitBranch size={16} /> Connect to GitHub
                                </Button>
                                {!source.effective && <p className="mt-2 text-xs text-zinc-500">Add the repository under “Update source” first.</p>}
                            </div>
                        </div>
                    </Card>
                ) : active ? (
                    <Card title={done ? 'Update complete' : 'Updating Central Management'} description={done ? null : running ? 'Keep this page open. Central Management is offline for a moment while the files change; websites simply check in again later.' : null}>
                        <Progress steps={steps} states={states} error={error} />
                        {failed && (
                            <div className="mt-5 flex flex-col gap-2 sm:flex-row">
                                <Button type="button" onClick={retry}>
                                    <RotateCcw size={16} /> Try again
                                </Button>
                                {['fetch', 'backup'].includes(failed) && (
                                    <Button type="button" variant="secondary" onClick={() => router.post('/updates/cancel', {}, { onSuccess: () => (window.location.href = '/updates') })}>
                                        Cancel update
                                    </Button>
                                )}
                            </div>
                        )}
                        {done && (
                            <div className="mt-5 rounded-xl bg-emerald-50 p-4">
                                <p className="flex items-center gap-2 font-semibold text-emerald-900">
                                    <ShieldCheck size={18} /> {done.message}
                                </p>
                                <p className="mt-1 text-sm text-emerald-800">Central Management is running the new version.</p>
                                <Button type="button" className="mt-3" onClick={() => (window.location.href = '/updates')}>
                                    Done
                                </Button>
                            </div>
                        )}
                    </Card>
                ) : status.error ? (
                    <Card>
                        <div className="flex items-start gap-3">
                            <AlertTriangle size={20} className="mt-0.5 shrink-0 text-amber-600" />
                            <div className="min-w-0">
                                <p className="font-semibold text-zinc-900">Couldn't check for updates</p>
                                <p className="mt-1 text-sm text-zinc-600">{status.error}</p>
                            </div>
                        </div>
                    </Card>
                ) : status.available ? (
                    <Card>
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <Badge color="blue">New version</Badge>
                                <h2 className="mt-2 text-xl font-bold tracking-tight text-zinc-900">
                                    {status.first_sync ? 'First update from GitHub' : `${status.behind} new ${status.behind === 1 ? 'change' : 'changes'} on ${status.branch}`}
                                </h2>
                                <p className="mt-0.5 text-sm text-zinc-500">
                                    Latest: <code className="font-mono">{status.latest?.short}</code> · {date(status.latest?.date)}
                                </p>
                            </div>
                            <Button type="button" onClick={() => setConfirm(true)} disabled={!ready || !!pending} className="shrink-0">
                                <Download size={16} /> Update now
                            </Button>
                        </div>
                        <div className={`mt-5 border-t border-zinc-100 pt-4 ${status.commits.length ? '' : 'hidden'}`}>
                            <h3 className="mb-2 text-sm font-semibold text-zinc-900">What's new</h3>
                            <ul className="space-y-2">
                                {status.commits.map((c) => (
                                    <li key={c.short} className="flex items-start gap-2.5 text-sm">
                                        <GitCommitHorizontal size={16} className="mt-0.5 shrink-0 text-zinc-400" />
                                        <span className="min-w-0">
                                            <span className="block text-zinc-800">{c.subject}</span>
                                            <span className="block text-xs text-zinc-500">
                                                <code className="font-mono">{c.short}</code> · {c.author} · {date(c.date)}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        {justConnected && <p className="mt-4 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900">Just connected: this first update brings every file to the repository’s latest version.</p>}
                    </Card>
                ) : (
                    <Card>
                        <div className="flex items-start gap-3">
                            <CheckCircle2 size={22} className="mt-0.5 shrink-0 text-emerald-600" />
                            <div className="min-w-0">
                                <p className="font-semibold text-zinc-900">Central Management is up to date</p>
                                <p className="mt-0.5 text-sm text-zinc-500">
                                    Same as {status.branch} on GitHub{status.checked_at ? ` · checked ${date(status.checked_at)}` : ''}
                                </p>
                            </div>
                        </div>
                    </Card>
                )}

                {error && !active && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}

                <Checks checks={checks} />
                <Backups backups={backups} onRollback={rollback} />
                <Source source={source} />
            </div>

            <Modal
                open={confirm}
                onClose={() => setConfirm(false)}
                title="Update Central Management now?"
                footer={
                    <>
                        <Button type="button" variant="secondary" onClick={() => setConfirm(false)}>
                            Not now
                        </Button>
                        <Button
                            type="button"
                            disabled={changed.length > 0 && !discard}
                            onClick={() => {
                                setConfirm(false);
                                run('fetch');
                            }}
                        >
                            Start update
                        </Button>
                    </>
                }
            >
                <ul className="list-disc space-y-1.5 pl-5">
                    <li>The latest code comes straight from GitHub ({status.branch}).</li>
                    <li>The database is backed up first, and the current version is kept so you can roll back.</li>
                    <li>Composer runs if the PHP packages changed; migrations and cache clearing always run. No terminal needed.</li>
                    <li>Central Management is offline for a moment. Websites keep selling; their check-ins wait.</li>
                </ul>
                {changed.length > 0 && (
                    <div className="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-950">
                        <p className="font-semibold">These files were changed on the server and will be replaced:</p>
                        <ul className="mt-1 max-h-32 overflow-auto font-mono text-xs">
                            {changed.map((f) => (
                                <li key={f}>{f}</li>
                            ))}
                        </ul>
                        <div className="mt-3">
                            <Toggle label="Replace changed files" description="Move these changes into the repository first if you want to keep them." checked={discard} onChange={setDiscard} />
                        </div>
                    </div>
                )}
            </Modal>
        </CentralLayout>
    );
}
