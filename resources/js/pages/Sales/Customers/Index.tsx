import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Customer = { id: number; display_name: string; company_name: string | null; email: string | null; phone: string | null; type: string; status: string };
type LinkData = { url: string | null; label: string; active: boolean };
type Shared = { tenant: { permissions: string[] }; [key: string]: unknown };
type Props = { customers: { data: Customer[]; links: LinkData[] }; filters: { search?: string; status?: string } };

export default function CustomerIndex({ customers, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const permissions = usePage<Shared>().props.tenant.permissions;
    const submit = (event: FormEvent) => { event.preventDefault(); router.get('/sales/customers', { ...filters, search: search || undefined }, { preserveState: true, replace: true }); };
    return <SalesLayout><Head title="Customers" />
        <div className="mb-5 flex items-center justify-between"><h2 className="text-xl font-semibold">Customers</h2>{permissions.includes('customers.create') && <Link href="/sales/customers/create" className="rounded bg-slate-900 px-4 py-2 text-white">Create customer</Link>}</div>
        <form onSubmit={submit} className="mb-6 flex flex-wrap gap-3 rounded-lg bg-slate-50 p-4"><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, company, email or phone" className="min-w-72 rounded border px-3 py-2" /><select value={filters.status ?? ''} onChange={(e) => router.get('/sales/customers', { ...filters, status: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select><button className="rounded border px-4 py-2">Search</button></form>
        <div className="overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">Name</th><th className="p-3">Company</th><th className="p-3">Email</th><th className="p-3">Phone</th><th className="p-3">Type</th><th className="p-3">Status</th></tr></thead><tbody>{customers.data.map((customer) => <tr key={customer.id} className="border-t"><td className="p-3 font-medium"><Link href={`/sales/customers/${customer.id}/edit`}>{customer.display_name}</Link></td><td className="p-3">{customer.company_name ?? '—'}</td><td className="p-3">{customer.email ?? '—'}</td><td className="p-3">{customer.phone ?? '—'}</td><td className="p-3 capitalize">{customer.type}</td><td className="p-3 capitalize">{customer.status}</td></tr>)}</tbody></table></div>
        <div className="mt-5 flex gap-2">{customers.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded border px-3 py-1 ${link.active ? 'bg-slate-900 text-white' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div>
    </SalesLayout>;
}
