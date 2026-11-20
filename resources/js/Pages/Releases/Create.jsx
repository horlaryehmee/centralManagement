import { useForm } from '@inertiajs/react';
import { FileArchive } from 'lucide-react';
import { Button, Card, FormActions, PageHeader, Select, Textarea, TextInput, Toggle } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function ReleaseCreate({ clients, max_mb }) {
    const form = useForm({ package: null, client_id: '', notes: '', requires: { core: '', php: '' }, upgrade_from_min: '', upgrade_from_max: '', database_rollback: false });
    const tooBig = form.data.package && form.data.package.size > max_mb * 1048576;

    return (
        <CentralLayout title="New release">
            <PageHeader back={{ href: '/releases', label: 'Releases' }} title="New release" description="Type, name and version are read from the package. It is saved as a draft until you publish it." />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/releases', { forceFormData: true });
                }}
                className="max-w-3xl space-y-6"
            >
                <Card title="Package">
                    <label className={`flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center ${form.errors.package || tooBig ? 'border-red-300 bg-red-50' : 'border-zinc-200 bg-zinc-50 hover:bg-zinc-100'}`}>
                        <FileArchive size={28} className="text-zinc-400" />
                        <span className="font-medium text-zinc-800">{form.data.package ? form.data.package.name : 'Choose a .zip package'}</span>
                        <span className="text-xs text-zinc-500">
                            {form.data.package ? `${(form.data.package.size / 1048576).toFixed(1)} MB` : `Core: built by the release workflow. Module/extension: a folder with module.json. Up to ${max_mb} MB here; larger files: php artisan central:import-release.`}
                        </span>
                        <input type="file" accept=".zip,application/zip" className="sr-only" onChange={(e) => form.setData('package', e.target.files?.[0] || null)} />
                    </label>
                    {form.errors.package && <p className="mt-2 text-sm font-medium text-red-600">{form.errors.package}</p>}
                    {tooBig && <p className="mt-2 text-sm font-medium text-red-600">This file is larger than the server accepts for uploads ({max_mb} MB). Use the import command instead.</p>}
                    {form.progress && (
                        <div className="mt-3 h-2 overflow-hidden rounded-full bg-zinc-100">
                            <div className="h-full bg-sky-500 transition-all" style={{ width: `${form.progress.percentage}%` }} />
                        </div>
                    )}
                </Card>

                <Card title="Details">
                    <div className="space-y-5">
                        <Select label="Client (extensions only)" placeholder="Not a client extension" options={clients.map((c) => ({ value: c.id, label: c.name }))} value={form.data.client_id} onChange={(e) => form.setData('client_id', e.target.value)} error={form.errors.client_id} help="A client extension can only ever be deployed to this client's websites." />
                        <Textarea label="Release notes" rows={5} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} error={form.errors.notes} optional />
                        <div className="grid gap-5 sm:grid-cols-2">
                            <TextInput label="Requires core version" placeholder="e.g. >=1.2 <2.0 or ^1.2" value={form.data.requires.core} onChange={(e) => form.setData('requires', { ...form.data.requires, core: e.target.value })} error={form.errors['requires.core']} help="Modules/extensions: defaults to module.json." optional />
                            <TextInput label="Requires PHP" placeholder="e.g. >=8.2" value={form.data.requires.php} onChange={(e) => form.setData('requires', { ...form.data.requires, php: e.target.value })} error={form.errors['requires.php']} optional />
                            <TextInput label="Upgrade from (minimum)" placeholder="e.g. 1.2.0" value={form.data.upgrade_from_min} onChange={(e) => form.setData('upgrade_from_min', e.target.value)} error={form.errors.upgrade_from_min} help="Older installations must take an earlier release first." optional />
                            <TextInput label="Upgrade from (maximum)" placeholder="e.g. 1.9.9" value={form.data.upgrade_from_max} onChange={(e) => form.setData('upgrade_from_max', e.target.value)} error={form.errors.upgrade_from_max} optional />
                        </div>
                        <Toggle label="Database changes can be rolled back" description="Turn on only if every new migration has a working down() method. Otherwise rollbacks restore files only." checked={form.data.database_rollback} onChange={(v) => form.setData('database_rollback', v)} />
                    </div>
                    <FormActions dirty={!!form.data.package}>
                        <Button loading={form.processing} disabled={!form.data.package || tooBig}>
                            Upload as draft
                        </Button>
                    </FormActions>
                </Card>
            </form>
        </CentralLayout>
    );
}
