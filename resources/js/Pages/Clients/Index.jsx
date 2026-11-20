import { Link, router } from '@inertiajs/react';
import { Building2, ChevronRight, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { AdminEmpty, AdminPagination, Badge, Card, PageHeader } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function ClientsIndex({ clients, filters }) {
    const [search, setSearch] = useState(filters.search || '');

    return (
        <CentralLayout title="Clients">
            <PageHeader title="Clients" description="Businesses using your ecommerce software." fab={{ href: '/clients/create', label: 'Register client', icon: Plus }} />
            <form
                className="relative mb-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.get('/clients', { search: search || undefined }, { preserveState: true });
                }}
            >
                <Search size={16} className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-zinc-400" />
                <input className="input pl-10" placeholder="Search by name, email or website" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search clients" />
            </form>
            <Card padded={false}>
                {clients.data.length ? (
                    <ul className="divide-y divide-zinc-100">
                        {clients.data.map((c) => (
                            <li key={c.id}>
                                <Link href={`/clients/${c.id}`} className="flex items-center gap-3 px-4 py-3.5 hover:bg-zinc-50 sm:px-5">
                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 font-semibold text-zinc-600">{c.name.charAt(0).toUpperCase()}</span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium text-zinc-900">{c.name}</span>
                                            {c.status !== 'active' && <Badge color="red">Suspended</Badge>}
                                        </span>
                                        <span className="block truncate text-xs text-zinc-500">{[c.website, c.email].filter(Boolean).join(' · ') || 'No contact details'}</span>
                                    </span>
                                    <span className="hidden shrink-0 text-right text-xs text-zinc-500 sm:block">
                                        {c.active_installations_count} active / {c.installations_count} installations
                                    </span>
                                    <ChevronRight size={18} className="shrink-0 text-zinc-300" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <AdminEmpty icon={Building2} title="No clients yet" message="Register a business, then add its website as an installation." />
                )}
                <AdminPagination paginator={clients} />
            </Card>
        </CentralLayout>
    );
}
