import { Badge } from '@/Components/ui';

/** "3 min ago", "2 h ago", "4 Nov". */
export function ago(iso) {
    if (!iso) return 'never';
    const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (s < -60) return dateTime(iso);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    if (s < 86400 * 7) return `${Math.floor(s / 86400)} d ago`;
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

export const dateTime = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export const bytes = (n) => (n == null ? '—' : n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

const INSTALLATION = {
    active: ['green', 'Active'],
    pending: ['amber', 'Waiting to connect'],
    suspended: ['gray', 'Suspended'],
    revoked: ['red', 'Revoked'],
};

export function InstallationStatus({ status, online }) {
    if (status === 'active' && online === false) return <Badge color="amber">Offline</Badge>;
    const [color, label] = INSTALLATION[status] || ['gray', status];
    return <Badge color={color}>{label}</Badge>;
}

export const TARGET = {
    waiting_canary: ['gray', 'Waiting for test site'],
    queued: ['blue', 'Queued'],
    delivered: ['blue', 'Picked up'],
    running: ['blue', 'Installing'],
    retry_wait: ['amber', 'Will retry'],
    succeeded: ['green', 'Succeeded'],
    failed: ['red', 'Failed'],
    cancelled: ['gray', 'Cancelled'],
    no_response: ['red', 'No response'],
    rollback_requested: ['amber', 'Rollback requested'],
    rolling_back: ['amber', 'Rolling back'],
    rolled_back: ['gray', 'Rolled back'],
    rollback_failed: ['red', 'Rollback failed'],
};

export function TargetStatus({ status }) {
    const [color, label] = TARGET[status] || ['gray', status];
    return <Badge color={color}>{label}</Badge>;
}

const DEPLOYMENT = {
    scheduled: ['blue', 'Scheduled'],
    running: ['blue', 'In progress'],
    completed: ['green', 'Completed'],
    completed_with_failures: ['amber', 'Completed with failures'],
    failed: ['red', 'Failed'],
    cancelled: ['gray', 'Cancelled'],
};

export function DeploymentStatus({ status }) {
    const [color, label] = DEPLOYMENT[status] || ['gray', status];
    return <Badge color={color}>{label}</Badge>;
}

const RELEASE = { draft: ['amber', 'Draft'], published: ['green', 'Published'], withdrawn: ['gray', 'Withdrawn'] };

export function ReleaseStatus({ status }) {
    const [color, label] = RELEASE[status] || ['gray', status];
    return <Badge color={color}>{label}</Badge>;
}

export function TypeBadge({ type }) {
    const map = { core: ['blue', 'Core'], module: ['gray', 'Module'], extension: ['amber', 'Extension'] };
    const [color, label] = map[type] || ['gray', type];
    return <Badge color={color}>{label}</Badge>;
}

const LEVEL_DOT = { info: 'bg-zinc-300', success: 'bg-emerald-500', warning: 'bg-amber-500', error: 'bg-red-500' };

/** Event log list. */
export function EventList({ events, showInstallation = true, empty = 'Nothing yet.' }) {
    if (!events?.length) return <p className="py-6 text-center text-sm text-zinc-400">{empty}</p>;
    return (
        <ol className="space-y-3">
            {events.map((e) => (
                <li key={e.id} className="flex gap-3 text-sm">
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${LEVEL_DOT[e.level] || LEVEL_DOT.info}`} aria-hidden />
                    <span className="min-w-0 flex-1">
                        <span className="block text-zinc-800">
                            {showInstallation && e.installation && <strong className="font-semibold">{e.installation.name}: </strong>}
                            {e.message}
                        </span>
                        <span className="block text-xs text-zinc-400" title={dateTime(e.created_at)}>
                            {ago(e.created_at)}
                            {e.context?.log?.length ? ` · ${e.context.log.length} log lines` : ''}
                        </span>
                        {e.context?.log?.length > 0 && (
                            <details className="mt-1">
                                <summary className="cursor-pointer text-xs font-medium text-zinc-500">Show log</summary>
                                <pre className="mt-1 max-h-60 overflow-auto rounded-lg bg-zinc-900 p-3 text-xs whitespace-pre-wrap text-zinc-100">{e.context.log.join('\n')}</pre>
                            </details>
                        )}
                    </span>
                </li>
            ))}
        </ol>
    );
}

export function Stat({ label, value, tone = 'text-zinc-900', hint }) {
    return (
        <div className="rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-xs">
            <p className="text-[13px] font-medium text-zinc-500">{label}</p>
            <p className={`mt-1.5 text-2xl font-bold tracking-tight tabular-nums ${tone}`}>{value}</p>
            {hint && <p className="mt-0.5 text-xs text-zinc-400">{hint}</p>}
        </div>
    );
}

/** Definition rows. */
export function Details({ rows }) {
    return (
        <dl className="divide-y divide-zinc-100">
            {rows.filter(Boolean).map(([label, value]) => (
                <div key={label} className="flex flex-col gap-0.5 py-2.5 text-sm sm:flex-row sm:gap-4">
                    <dt className="shrink-0 text-zinc-500 sm:w-40">{label}</dt>
                    <dd className="min-w-0 text-zinc-900">{value ?? '—'}</dd>
                </div>
            ))}
        </dl>
    );
}
