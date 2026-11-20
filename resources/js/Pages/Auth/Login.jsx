import { Head, useForm, usePage } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { Button, TextInput } from '@/Components/ui';

export default function Login() {
    const { app_name } = usePage().props;
    const form = useForm({ email: '', password: '', remember: false });

    return (
        <div className="flex min-h-screen items-center justify-center bg-zinc-50 px-4">
            <Head title="Sign in" />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/login', { onFinish: () => form.reset('password') });
                }}
                className="w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"
            >
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-900 text-white">
                    <Rocket size={18} />
                </span>
                <h1 className="mt-4 text-xl font-bold tracking-tight">{app_name}</h1>
                <p className="mt-1 text-sm text-zinc-500">Sign in to manage installations and updates.</p>
                <div className="mt-6 space-y-4">
                    <TextInput label="Email" type="email" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} autoFocus required />
                    <TextInput label="Password" type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} required />
                    <label className="flex items-center gap-2 text-sm text-zinc-600">
                        <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} className="rounded border-zinc-300" /> Keep me signed in
                    </label>
                    <Button loading={form.processing} className="w-full">
                        Sign in
                    </Button>
                </div>
            </form>
        </div>
    );
}
