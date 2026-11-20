import { useForm } from '@inertiajs/react';
import { Button, Card, FormActions, PageHeader, Select, Textarea, TextInput, Toggle } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

export default function InstallationForm({ installation, clients, client_id }) {
    const form = useForm({
        client_id: installation?.client_id ?? client_id ?? '',
        name: installation?.name ?? '',
        domain: installation?.domain ?? '',
        environment: installation?.environment ?? 'production',
        is_test: installation?.is_test ?? false,
        check_interval: installation?.check_interval ?? 15,
        notes: installation?.notes ?? '',
    });
    const field = (name) => ({ value: form.data[name], onChange: (e) => form.setData(name, e.target.value), error: form.errors[name] });

    return (
        <CentralLayout title={installation ? `Edit ${installation.name}` : 'Add installation'}>
            <PageHeader
                back={{ href: installation ? `/installations/${installation.id}` : '/installations', label: installation ? installation.name : 'Installations' }}
                title={installation ? 'Edit installation' : 'Add installation'}
                description={installation ? null : 'You get a one-time connection code to enter in that website\'s admin (Updates → Update server).'}
            />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    installation ? form.put(`/installations/${installation.id}`) : form.post('/installations');
                }}
                className="max-w-3xl"
            >
                <Card>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Select label="Client" options={clients.map((c) => ({ value: c.id, label: c.name }))} placeholder="Choose…" {...field('client_id')} />
                        <TextInput label="Name" placeholder="e.g. Main shop" {...field('name')} />
                        <TextInput label="Website domain" placeholder="shop.example.com" help="Used to warn you if the code is used on a different site." {...field('domain')} />
                        <Select
                            label="Environment"
                            options={[
                                { value: 'production', label: 'Production (live)' },
                                { value: 'staging', label: 'Staging' },
                                { value: 'test', label: 'Test' },
                            ]}
                            {...field('environment')}
                        />
                        <TextInput label="Check-in interval (minutes)" type="number" min={5} max={1440} help="How often the website checks for instructions." {...field('check_interval')} />
                    </div>
                    <div className="mt-5">
                        <Toggle label="Use as a test installation" description="Can be chosen to receive updates first; the rest only follow if it succeeds." checked={form.data.is_test} onChange={(v) => form.setData('is_test', v)} />
                    </div>
                    <Textarea label="Notes" rows={3} optional className="mt-5" {...field('notes')} />
                    <FormActions dirty={form.isDirty}>
                        <Button loading={form.processing}>{installation ? 'Save' : 'Add and get connection code'}</Button>
                    </FormActions>
                </Card>
            </form>
        </CentralLayout>
    );
}
