import { Link } from '@inertiajs/react';
import { Boxes, ChevronRight, GitBranch, Upload } from 'lucide-react';
import { ago, ReleaseStatus, TypeBadge } from '@/Components/central';
import { AdminEmpty, AdminPagination, Button, Card, FilterChips, PageHeader } from '@/Components/ui';
import { router } from '@inertiajs/react';
import CentralLayout from '@/Layouts/CentralLayout';

export default function ReleasesIndex({ releases, type, types }) {
    return (
        <CentralLayout title="Releases">
            <PageHeader
                title="Releases"
                description="Signed update packages for the core, modules and client extensions."
                actions={
                    <Button variant="secondary" href="/github">
                        <GitBranch size={16} /> From GitHub
                    </Button>
                }
                fab={{ href: '/releases/create', label: 'Upload', icon: Upload }}
            />
            <div className="mb-4">
                <FilterChips value={type || ''} label="Release type" options={[['', 'All'], ...Object.entries(types)]} onChange={(t) => router.get('/releases', { type: t || undefined }, { preserveState: true })} />
            </div>
            <Card padded={false}>
                {releases.data.length ? (
                    <ul className="divide-y divide-zinc-100">
                        {releases.data.map((r) => (
                            <li key={r.id}>
                                <Link href={`/releases/${r.id}`} className="flex items-center gap-3 px-4 py-3.5 hover:bg-zinc-50 sm:px-5">
                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-zinc-900">
                                                {r.type === 'core' ? 'Core' : r.slug} {r.version}
                                            </span>
                                            <TypeBadge type={r.type} />
                                            <ReleaseStatus status={r.status} />
                                        </span>
                                        <span className="block truncate text-xs text-zinc-500">
                                            {r.client ? `For ${r.client.name} · ` : ''}
                                            {r.deployments_count} deployment(s) · {ago(r.published_at || r.created_at)}
                                        </span>
                                    </span>
                                    <ChevronRight size={18} className="shrink-0 text-zinc-300" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <AdminEmpty icon={Boxes} title="No releases yet" message="Upload a package built by the release workflow (core) or a module/extension zip." />
                )}
                <AdminPagination paginator={releases} />
            </Card>
        </CentralLayout>
    );
}
