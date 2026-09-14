import { Button } from '@/components/ui/Button';
import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Customer = {
    id: number;
    type: string;
    display_name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    tax_identifier: string | null;
    billing_address: string | null;
    notes: string | null;
    status: string;
};

const labelClass = 'block text-[13px] font-medium text-ink';
const fieldClass = 'mt-1.5 h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

export default function CustomerForm({ customer }: { customer: Customer | null }) {
    const form = useForm({
        type: customer?.type ?? 'individual',
        display_name: customer?.display_name ?? '',
        company_name: customer?.company_name ?? '',
        email: customer?.email ?? '',
        phone: customer?.phone ?? '',
        tax_identifier: customer?.tax_identifier ?? '',
        billing_address: customer?.billing_address ?? '',
        notes: customer?.notes ?? '',
        status: customer?.status ?? 'active',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        customer ? form.patch(`/sales/customers/${customer.id}`) : form.post('/sales/customers');
    };

    return (
        <SalesLayout>
            <Head title={customer ? `Modifier ${customer.display_name}` : 'Nouveau client'} />
            <form onSubmit={submit} className="mx-auto max-w-3xl">
                <div className="mb-6">
                    {customer && (
                        <Link href={`/sales/customers/${customer.id}`} className="text-sm text-ink-muted">
                            ← {customer.display_name}
                        </Link>
                    )}
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink">{customer ? 'Modifier le client' : 'Nouveau client'}</h1>
                </div>

                <div className="grid gap-4 rounded-card border border-line bg-surface p-5 md:grid-cols-2">
                    <label className={labelClass}>
                        Type de client
                        <select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className={fieldClass}>
                            <option value="individual">Particulier</option>
                            <option value="company">Entreprise</option>
                        </select>
                    </label>
                    <label className={labelClass}>
                        Statut
                        <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={fieldClass}>
                            <option value="active">Actif</option>
                            <option value="inactive">Inactif</option>
                        </select>
                    </label>
                    <label className={labelClass}>
                        Nom complet
                        <input required value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} className={fieldClass} />
                        {form.errors.display_name && <p className="mt-1 text-[13px] text-danger">{form.errors.display_name}</p>}
                    </label>
                    <label className={labelClass}>
                        Société
                        <input value={form.data.company_name} onChange={(e) => form.setData('company_name', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        Email
                        <input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        Téléphone
                        <input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        ICE / identifiant fiscal
                        <input value={form.data.tax_identifier} onChange={(e) => form.setData('tax_identifier', e.target.value)} className={fieldClass} />
                    </label>
                    <label className={labelClass}>
                        Adresse de facturation
                        <textarea rows={2} value={form.data.billing_address} onChange={(e) => form.setData('billing_address', e.target.value)} className={`${fieldClass} h-auto py-2`} />
                    </label>
                    <label className={`${labelClass} md:col-span-2`}>
                        Notes
                        <textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={`${fieldClass} h-auto py-2`} />
                    </label>
                </div>

                {Object.values(form.errors).length > 0 && (
                    <div className="mt-3 space-y-1">
                        {Object.values(form.errors).map((error, index) => (
                            <p key={index} className="text-[13px] text-danger">{error}</p>
                        ))}
                    </div>
                )}

                <div className="mt-5 flex gap-2">
                    <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                        Enregistrer
                    </Button>
                    {customer && (
                        <Link href={`/sales/customers/${customer.id}`} className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised">
                            Annuler
                        </Link>
                    )}
                </div>
            </form>
        </SalesLayout>
    );
}
