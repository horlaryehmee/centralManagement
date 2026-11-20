import { useForm } from '@inertiajs/react';
import { Button, Card, FormActions, PageHeader, Textarea, TextInput } from '@/Components/ui';
import CentralLayout from '@/Layouts/CentralLayout';

const FIELDS = ['name', 'contact_name', 'email', 'phone', 'website', 'hosting_provider', 'hosting_notes', 'notes'];

export default function ClientForm({ client }) {
    const form = useForm(Object.fromEntries(FIELDS.map((f) => [f, client?.[f] ?? ''])));
    const field = (name) => ({ value: form.data[name], onChange: (e) => form.setData(name, e.target.value), error: form.errors[name] });

    return (
        <CentralLayout title={client ? `Edit ${client.name}` : 'Register client'}>
            <PageHeader back={{ href: client ? `/clients/${client.id}` : '/clients', label: client ? client.name : 'Clients' }} title={client ? 'Edit client' : 'Register client'} />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    client ? form.put(`/clients/${client.id}`) : form.post('/clients');
                }}
                className="max-w-3xl"
            >
                <Card title="Business">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <TextInput label="Business name" className="sm:col-span-2" required {...field('name')} />
                        <TextInput label="Contact person" optional {...field('contact_name')} />
                        <TextInput label="Email" type="email" optional {...field('email')} />
                        <TextInput label="Phone" optional {...field('phone')} />
                        <TextInput label="Main website" placeholder="https://…" optional {...field('website')} />
                    </div>
                    <div className="mt-6 grid gap-5 border-t border-zinc-100 pt-5 sm:grid-cols-2">
                        <TextInput label="Hosting provider" placeholder="e.g. Namecheap cPanel" optional {...field('hosting_provider')} />
                        <div className="hidden sm:block" />
                        <Textarea label="Hosting notes" rows={3} help="Plan, PHP version, special setup. Never store passwords here." optional className="sm:col-span-2" {...field('hosting_notes')} />
                        <Textarea label="Notes" rows={3} optional className="sm:col-span-2" {...field('notes')} />
                    </div>
                    <FormActions dirty={form.isDirty}>
                        <Button loading={form.processing}>{client ? 'Save' : 'Register client'}</Button>
                    </FormActions>
                </Card>
            </form>
        </CentralLayout>
    );
}
