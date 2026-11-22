import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Circle, Download, ExternalLink, History, Loader2, PowerOff, RefreshCw, RotateCcw, ShieldCheck, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, Card, ConfirmModal, FormActions, Modal, PageHeader, TextInput, Toggle } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';
import { xsrfCookieName } from '@/lib/csrf';

const STEP_LABELS = {
    download: 'Downloading the update',
    backup: 'Backing up your database',
    extract: 'Unpacking and checking the files',
    install: 'Installing the new version',
    finish: 'Updating the database and finishing',
};

function xsrf() {
    // Letters, digits and dashes only, so safe in a pattern as is.
    const name = xsrfCookieName();
    const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]+)`));
    return m ? decodeURIComponent(m[1]) : '';
}

async function post(url) {
    let res;
    try {
        res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' } });
    } catch {
        throw new Error('Lost the connection to the server. Check your internet connection, then press Try again.');
    }
    let data = {};
    try {
        data = await res.json();
    } catch {
        /* not JSON (e.g. a host timeout page) */
    }
    if (!res.ok) {
        if (data.message) throw new Error(data.message);
        if (res.status === 419) throw new Error('Your session expired. Reload this page and try again.');
        if ([502, 503, 504, 524].includes(res.status)) throw new Error('This step took longer than your host allows for one request. Press Try again: it continues where it stopped.');
        throw new Error(`The server returned an error (${res.status}). Press Try again.`);
    }
    return data;
}

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '');
const nextStep = (steps, done) => (done ? steps[steps.indexOf(done) + 1] : steps[0]);

/** Live progress of a running update. */
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
        <Card title="Server readiness" description={failing.length ? 'Fix these before updating.' : 'Your hosting is ready for one-click updates.'}>
            <ul className="space-y-2.5">
                {checks.map((c) => (
                    <li key={c.label} className="flex items-start gap-2.5 text-sm">
                        {c.ok ? <CheckCircle2 size={18} className="mt-px shrink-0 text-emerald-600" /> : <XCircle size={18} className="mt-px shrink-0 text-red-600" />}
                        <span className="min-w-0">
                            <span className="block text-zinc-800">{c.label}</span>
                            {!c.ok && <span className="block text-xs text-zinc-500">{c.detail}</span>}
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function Source({ source }) {
    const form = useForm({ repository: source.repository || '', token: '', remove_token: false });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put('/updates/source', { preserveScroll: true, onSuccess: () => form.setData((d) => ({ ...d, token: '', remove_token: false })) });
            }}
        >
            <Card title="Update source" description={'The GitHub repository of Central Management. Each release there carries a ready-to-install zip built by GitHub Actions.'}>
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
                    <TextInput
                        label="Access token"
                        type="password"
                        autoComplete="new-password"
                        placeholder={source.has_token ? '•••••••• (saved)' : source.env_token ? '(set in server configuration)' : 'github_pat_…'}
                        value={form.data.token}
                        onChange={(e) => form.setData('token', e.target.value)}
                        error={form.errors.token}
                        help="Needed for private repositories. A fine-grained token with read-only access to the repository's Contents is enough. Stored encrypted."
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

function Backups({ backups, onRestore }) {
    const [confirm, setConfirm] = useState(null);
    if (!backups.length) return null;
    const label = (b) => (b.kind && b.kind !== 'core' ? `${b.slug} ${b.version === '0.0.0' ? '(not installed)' : b.version}` : `version ${b.version}`);
    // Rolling back to an older entry also undoes every later update of the same item.
    const undone = (b) => backups.filter((o) => o.kind === b.kind && o.slug === b.slug && o.id >= b.id).map((o) => o.replaced_by).filter(Boolean);
    return (
        <Card title="Previous versions" description="The last versions are kept automatically after each update, so you can roll back with one click. Older ones are removed." padded={false}>
            <ul className="divide-y divide-zinc-100">
                {backups.map((b) => (
                    <li key={b.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 sm:px-5">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500">
                            <History size={17} />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block text-sm font-medium text-zinc-900">
                                {b.kind && b.kind !== 'core' ? `${b.slug} ${b.version === '0.0.0' ? '(not installed)' : b.version}` : `Version ${b.version}`}
                            </span>
                            <span className="block text-xs text-zinc-500">
                                {b.replaced_by ? `Replaced by ${b.replaced_by}` : 'Backup'}
                                {b.created_at ? ` · ${formatDate(b.created_at)}` : ''}
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
                    onRestore(confirm);
                    setConfirm(null);
                }}
                title={confirm ? `Roll back to ${label(confirm)}?` : ''}
                confirmLabel="Roll back"
                message={
                    confirm
                        ? `This undoes the update${undone(confirm).length > 1 ? 's' : ''} to ${undone(confirm).join(' and ')}: the app's files go back to ${label(confirm)} and the database changes those updates made are undone. Central Management is offline for a few seconds. Clients, websites, releases and backups stay; anything stored only in features added by ${undone(confirm).length > 1 ? 'those updates' : 'that update'} is removed. The database backup can still be downloaded first.`
                        : ''
                }
            />
        </Card>
    );
}

export default function Updates({ status, checks, pending, down, backups, steps, source }) {
    const ready = checks.every((c) => c.ok);
    const [states, setStates] = useState({});
    const [running, setRunning] = useState(false);
    const [error, setError] = useState(null);
    const [failed, setFailed] = useState(null);
    const [done, setDone] = useState(null);
    const [confirm, setConfirm] = useState(false);
    const [restoring, setRestoring] = useState(null);
    const active = running || failed || done || Object.keys(states).length > 0;

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
                const res = await post(`/updates/step/${step}`);
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

    // After an error, ask the server where it got to (a step may have finished after a timeout) and carry on from there.
    const retry = () =>
        router.reload({
            only: ['pending', 'down'],
            onSuccess: (page) => {
                const p = page.props.pending;
                if (!p && failed === 'finish') return (window.location.href = '/updates');
                run(p ? nextStep(steps, p.step) : 'download');
            },
        });

    const restore = async (backup) => {
        setRestoring(backup.version);
        try {
            await post(`/updates/backups/${backup.id}/rollback`);
            window.location.href = '/updates';
        } catch (e) {
            setRestoring(null);
            setError(e.message);
        }
    };

    return (
        <CentralLayout title="Updates">
            <PageHeader
                title="Updates"
                description={`Central Management is on version ${status.current}.`}
                actions={
                    !active && (
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
                    <Card title="An update was not finished" description={`Version ${pending.to} stopped after: ${STEP_LABELS[pending.step]?.toLowerCase() || pending.step}.`}>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button type="button" onClick={() => run(nextStep(steps, pending.step))}>
                                Continue the update
                            </Button>
                            {pending.step !== 'install' && (
                                <Button type="button" variant="secondary" onClick={() => router.post('/updates/cancel', {}, { preserveScroll: true })}>
                                    Cancel it
                                </Button>
                            )}
                        </div>
                    </Card>
                )}

                {restoring && (
                    <Card>
                        <p className="flex items-center gap-3 text-sm font-medium text-zinc-900">
                            <Loader2 size={18} className="animate-spin" /> Rolling back to version {restoring}…
                        </p>
                    </Card>
                )}

                {active ? (
                    <Card title={done ? 'Update complete' : `Updating to version ${status.latest || pending?.to}`} description={done ? null : running ? 'Keep this page open. Central Management is offline for about a minute during the install step; websites simply check in again later.' : null}>
                        <Progress steps={steps} states={states} error={error} />
                        {failed && (
                            <div className="mt-5 flex flex-col gap-2 sm:flex-row">
                                <Button type="button" onClick={retry}>
                                    <RotateCcw size={16} /> Try again
                                </Button>
                                {failed !== 'finish' && failed !== 'install' && (
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
                                <p className="mt-1 text-sm text-emerald-800">Central Management is back online with the new version.</p>
                                <Button type="button" className="mt-3" onClick={() => (window.location.href = '/updates')}>
                                    Done
                                </Button>
                            </div>
                        )}
                    </Card>
                ) : status.available ? (
                    <Card>
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <Badge color="blue">New version</Badge>
                                <h2 className="mt-2 text-xl font-bold tracking-tight text-zinc-900">Version {status.latest} is available</h2>
                                <p className="mt-0.5 text-sm text-zinc-500">
                                    You have {status.current}
                                    {status.published_at ? ` · released ${formatDate(status.published_at)}` : ''}
                                </p>
                            </div>
                            <Button type="button" onClick={() => setConfirm(true)} disabled={!ready || !!status.error || !!pending} className="shrink-0">
                                <Download size={16} /> Update now
                            </Button>
                        </div>
                        {status.error && <p className="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{status.error}</p>}
                        {status.notes_html && (
                            <div className="mt-5 border-t border-zinc-100 pt-4">
                                <h3 className="mb-2 text-sm font-semibold text-zinc-900">What's new</h3>
                                <div className="prose prose-sm prose-zinc max-w-none" dangerouslySetInnerHTML={{ __html: status.notes_html }} />
                            </div>
                        )}
                        {status.url && (
                            <a href={status.url} target="_blank" rel="noopener noreferrer" className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-900">
                                Release page <ExternalLink size={14} />
                            </a>
                        )}
                    </Card>
                ) : (
                    <Card>
                        {status.error ? (
                            <div className="flex items-start gap-3">
                                <AlertTriangle size={20} className="mt-0.5 shrink-0 text-amber-600" />
                                <div className="min-w-0">
                                    <p className="font-semibold text-zinc-900">Couldn't check for updates</p>
                                    <p className="mt-1 text-sm text-zinc-600">{status.error}</p>
                                </div>
                            </div>
                        ) : (
                            <div className="flex items-start gap-3">
                                <CheckCircle2 size={22} className="mt-0.5 shrink-0 text-emerald-600" />
                                <div className="min-w-0">
                                    <p className="font-semibold text-zinc-900">Central Management is up to date</p>
                                    <p className="mt-0.5 text-sm text-zinc-500">
                                        Version {status.current}
                                        {status.checked_at ? ` · checked ${new Date(status.checked_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}` : ''}
                                    </p>
                                </div>
                            </div>
                        )}
                    </Card>
                )}

                {error && !active && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}

                <Checks checks={checks} />
                <Backups backups={backups} onRestore={restore} />
                <Source source={source} />
            </div>

            <Modal
                open={confirm}
                onClose={() => setConfirm(false)}
                title={`Update to version ${status.latest}?`}
                footer={
                    <>
                        <Button type="button" variant="secondary" onClick={() => setConfirm(false)}>
                            Not now
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                setConfirm(false);
                                run('download');
                            }}
                        >
                            Start update
                        </Button>
                    </>
                }
            >
                <ul className="list-disc space-y-1.5 pl-5">
                    <li>Your database is backed up first, and the current version is kept so you can go back.</li>
                    <li>Central Management is offline for about a minute while the new version installs. Websites keep selling; their check-ins wait.</li>
                    <li>Database migrations and cache clearing run automatically. No terminal or Composer needed.</li>
                    <li>Keep this page open until it says the update is complete.</li>
                </ul>
            </Modal>
        </CentralLayout>
    );
}
