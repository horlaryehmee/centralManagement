import { Link } from '@inertiajs/react';
import { ChevronRight, Rocket } from 'lucide-react';
import { ago, DeploymentStatus } from '@/Components/central';
import { AdminEmpty, AdminPagination, Card, PageHeader } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function DeploymentsIndex({ deployments }) {
    return (
        <CentralLayout title="Deployments">
            <PageHeader title="Deployments" description="Releases sent to websites, with the result each one reported." fab={{ href: '/deployments/create', label: 'New deployment', icon: Rocket }} />
            <Card padded={false}>
                {deployments.data.length ? (
                    <ul className="divide-y divide-zinc-100">
                        {deployments.data.map((d) => (
                            <li key={d.id}>
                                <Link href={`/deployments/${d.id}`} className="flex items-center gap-3 px-4 py-3.5 hover:bg-zinc-50 sm:px-5">
                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold">
                                                {d.release.type === 'core' ? 'Core' : d.release.slug} {d.release.version}
                                            </span>
                                            <DeploymentStatus status={d.status} />
                                        </span>
                                        <span className="block truncate text-xs text-zinc-500">
                                            #{d.id} · {d.targets_count} website(s): {d.succeeded_count} succeeded, {d.failed_count} failed · {d.creator?.name || 'system'} · {ago(d.created_at)}
                                        </span>
                                    </span>
                                    <ChevronRight size={18} className="shrink-0 text-zinc-300" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <AdminEmpty icon={Rocket} title="No deployments yet" message="Publish a release, then deploy it to one, some or all websites." />
                )}
                <AdminPagination paginator={deployments} />
            </Card>
        </CentralLayout>
    );
}
