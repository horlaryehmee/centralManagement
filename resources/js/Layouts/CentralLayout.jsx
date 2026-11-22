import { Head, Link, router, usePage } from '@inertiajs/react';
import { Boxes, Building2, CheckCircle2, LayoutDashboard, LogOut, RefreshCw, Rocket, Server, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

const NAV = [
    { href: '/', label: 'Dashboard', short: 'Home', icon: LayoutDashboard, exact: true },
    { href: '/clients', label: 'Clients', icon: Building2 },
    { href: '/installations', label: 'Installations', short: 'Sites', icon: Server },
    { href: '/releases', label: 'Releases', icon: Boxes },
    { href: '/deployments', label: 'Deployments', short: 'Deploy', icon: Rocket },
];

const isActive = (url, item) => {
    const path = url.split('?')[0];
    return item.exact ? path === item.href : path === item.href || path.startsWith(item.href + '/');
};

function Flash() {
    const { flash, errors } = usePage().props;
    const [msg, setMsg] = useState(null);
    useEffect(() => {
        if (flash?.success) setMsg({ ok: true, text: flash.success });
        else if (flash?.error) setMsg({ ok: false, text: flash.error });
        else if (errors && Object.keys(errors).length) setMsg({ ok: false, text: Object.values(errors)[0] });
        else return;
        const t = setTimeout(() => setMsg(null), 5000);
        return () => clearTimeout(t);
    }, [flash, errors]);
    if (!msg) return null;
    return (
        <div className="fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-50 flex justify-center lg:inset-x-auto lg:right-6 lg:bottom-6" role="status" aria-live="polite">
            <div className={`flex w-full max-w-md items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-white shadow-xl ${msg.ok ? 'bg-zinc-900' : 'bg-red-600'}`}>
                {msg.ok ? <CheckCircle2 size={18} className="shrink-0 text-emerald-400" /> : <XCircle size={18} className="shrink-0" />}
                <span className="min-w-0 flex-1">{msg.text}</span>
                <button type="button" onClick={() => setMsg(null)} className="rounded-full p-1 opacity-70 hover:opacity-100" aria-label="Dismiss">
                    <X size={16} />
                </button>
            </div>
        </div>
    );
}

export default function CentralLayout({ title, children }) {
    const { url } = usePage();
    const { auth, app_name, update_available } = usePage().props;
    const onUpdates = isActive(url, { href: '/updates' });

    return (
        <div className="app-shell min-h-screen bg-zinc-50">
            <Head title={title} />
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-60 flex-col border-r border-zinc-200 bg-white lg:flex">
                <div className="flex h-16 items-center gap-2.5 border-b border-zinc-100 px-5">
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-zinc-900 text-white">
                        <Rocket size={16} />
                    </span>
                    <span className="truncate font-bold tracking-tight">{app_name}</span>
                </div>
                <nav className="flex-1 space-y-1 p-3" aria-label="Main">
                    {NAV.map((item) => (
                        <Link key={item.href} href={item.href} className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${isActive(url, item) ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900'}`}>
                            <item.icon size={18} /> {item.label}
                        </Link>
                    ))}
                    <Link href="/updates" className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${onUpdates ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900'}`}>
                        <RefreshCw size={18} /> Updates
                        {update_available && <span className={`ml-auto rounded-full px-2 py-0.5 text-xs font-semibold ${onUpdates ? 'bg-white/20 text-white' : 'bg-sky-100 text-sky-800'}`}>New</span>}
                    </Link>
                </nav>
                <div className="border-t border-zinc-100 p-3">
                    <p className="truncate px-3 pb-2 text-xs text-zinc-400">{auth.user?.email}</p>
                    <button type="button" onClick={() => router.post('/logout')} className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-zinc-600 hover:bg-red-50 hover:text-red-700">
                        <LogOut size={18} /> Log out
                    </button>
                </div>
            </aside>

            <div className="lg:pl-60">
                <header className="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-zinc-200/80 bg-white/90 px-4 backdrop-blur-xl lg:hidden">
                    <span className="flex items-center gap-2 font-bold tracking-tight">
                        <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-zinc-900 text-white">
                            <Rocket size={14} />
                        </span>
                        {app_name}
                    </span>
                    <span className="flex items-center gap-1">
                        <Link href="/updates" className={`relative rounded-full p-2 ${onUpdates ? 'bg-zinc-900 text-white' : 'text-zinc-500 hover:bg-zinc-100'}`} aria-label={update_available ? 'Updates (new version available)' : 'Updates'}>
                            <RefreshCw size={18} />
                            {update_available && <span className="absolute top-1 right-1 h-2.5 w-2.5 rounded-full bg-sky-500 ring-2 ring-white" aria-hidden />}
                        </Link>
                        <button type="button" onClick={() => router.post('/logout')} className="rounded-full p-2 text-zinc-500 hover:bg-zinc-100" aria-label="Log out">
                            <LogOut size={18} />
                        </button>
                    </span>
                </header>
                <main key={url.split('?')[0]} className="mx-auto max-w-6xl animate-page-in px-4 pt-4 pb-[calc(6rem+env(safe-area-inset-bottom))] sm:px-6 sm:pt-6 lg:px-8 lg:py-8">
                    {children}
                </main>
            </div>

            <nav aria-label="Sections" className="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-200/80 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl lg:hidden">
                <div className="mx-auto flex h-16 max-w-xl">
                    {NAV.map((item) => {
                        const on = isActive(url, item);
                        return (
                            <Link key={item.href} href={item.href} className="flex min-w-0 flex-1 flex-col items-center justify-center gap-1" aria-current={on ? 'page' : undefined}>
                                <span className={`flex h-8 w-12 items-center justify-center rounded-full ${on ? 'bg-zinc-900 text-white' : 'text-zinc-500'}`}>
                                    <item.icon size={19} />
                                </span>
                                <span className={`max-w-full truncate text-[11px] leading-none ${on ? 'font-semibold text-zinc-900' : 'font-medium text-zinc-500'}`}>{item.short || item.label}</span>
                            </Link>
                        );
                    })}
                </div>
            </nav>
            <Flash />
        </div>
    );
}
