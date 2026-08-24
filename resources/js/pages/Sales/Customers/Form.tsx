import SalesLayout from '@/layouts/SalesLayout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Customer = { id: number; type: string; display_name: string; company_name: string | null; email: string | null; phone: string | null; tax_identifier: string | null; billing_address: string | null; notes: string | null; status: string };

export default function CustomerForm({ customer }: { customer: Customer | null }) {
    const form = useForm({ type: customer?.type ?? 'individual', display_name: customer?.display_name ?? '', company_name: customer?.company_name ?? '', email: customer?.email ?? '', phone: customer?.phone ?? '', tax_identifier: customer?.tax_identifier ?? '', billing_address: customer?.billing_address ?? '', notes: customer?.notes ?? '', status: customer?.status ?? 'active' });
    const submit = (event: FormEvent) => { event.preventDefault(); customer ? form.patch(`/sales/customers/${customer.id}`) : form.post('/sales/customers'); };
    return <SalesLayout><Head title={customer ? `Edit ${customer.display_name}` : 'Create customer'} /><form onSubmit={submit} className="mx-auto max-w-3xl space-y-4"><h2 className="text-xl font-semibold">{customer ? 'Edit customer' : 'Create customer'}</h2><div className="grid gap-4 rounded-lg border p-5 md:grid-cols-2">
        <label>Type<select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className="mt-1 w-full rounded border px-3 py-2"><option value="individual">Individual</option><option value="company">Company</option></select></label>
        <label>Status<select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className="mt-1 w-full rounded border px-3 py-2"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <label>Display name<input required value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></label><label>Company<input value={form.data.company_name} onChange={(e) => form.setData('company_name', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></label>
        <label>Email<input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></label><label>Phone<input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></label>
        <label>Tax identifier<input value={form.data.tax_identifier} onChange={(e) => form.setData('tax_identifier', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></label><label>Billing address<textarea value={form.data.billing_address} onChange={(e) => form.setData('billing_address', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></label>
        <label className="md:col-span-2">Notes<textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></label></div>
        {Object.values(form.errors).map((error, index) => <p key={index} className="text-sm text-red-600">{error}</p>)}<button disabled={form.processing} className="rounded bg-slate-900 px-4 py-2 text-white">Save customer</button></form></SalesLayout>;
}
