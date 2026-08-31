import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Order = { id: number; order_number: string; sale_date: string; customer_name: string | null; status: string; fulfillment_status: string; payment_status: string; total_incl_tax: string; source: string; currency_code: string; store: { name: string } };
type LinkData = { url: string | null; label: string; active: boolean };
type Shared = { tenant: { permissions: string[] }; [key: string]: unknown };
type Props = { orders: { data: Order[]; links: LinkData[] }; filters: { search?: string; status?: string; sale_date?: string } };

export default function OrderIndex({ orders, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? ''); const permissions = usePage<Shared>().props.tenant.permissions;
    const submit = (event: FormEvent) => { event.preventDefault(); router.get('/sales/orders', { ...filters, search: search || undefined }, { preserveState: true }); };
    return <SalesLayout><Head title="Sales Orders" /><div className="mb-5 flex items-center justify-between"><h2 className="text-xl font-semibold">Sales Orders</h2>{permissions.includes('sales_orders.create') && <Link href="/sales/orders/create" className="rounded bg-slate-900 px-4 py-2 text-white">Create order</Link>}</div>
        <form onSubmit={submit} className="mb-6 flex flex-wrap gap-3 rounded-lg bg-slate-50 p-4"><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Order number or customer" className="rounded border px-3 py-2" /><select value={filters.status ?? ''} onChange={(e) => router.get('/sales/orders', { ...filters, status: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2"><option value="">All statuses</option><option value="draft">Draft</option><option value="confirmed">Confirmed</option><option value="cancelled">Cancelled</option></select><input type="date" value={filters.sale_date ?? ''} onChange={(e) => router.get('/sales/orders', { ...filters, sale_date: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2" /><button className="rounded border px-4 py-2">Search</button></form>
        <div className="overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">Order #</th><th className="p-3">Date</th><th className="p-3">Customer</th><th className="p-3">Store</th><th className="p-3">Status</th><th className="p-3">Fulfillment</th><th className="p-3">Payment</th><th className="p-3 text-right">Total</th><th className="p-3">Source</th></tr></thead><tbody>{orders.data.map((order) => <tr key={order.id} className="border-t"><td className="p-3 font-medium"><Link href={`/sales/orders/${order.id}`}>{order.order_number}</Link></td><td className="p-3">{formatDate(order.sale_date)}</td><td className="p-3">{order.customer_name ?? 'Walk-in'}</td><td className="p-3">{order.store.name}</td><td className="p-3 capitalize">{order.status}</td><td className="p-3">{order.fulfillment_status.replaceAll('_', ' ')}</td><td className="p-3">{order.payment_status.replaceAll('_', ' ')}</td><td className="p-3 text-right tabular-nums">{formatMoney(order.total_incl_tax, order.currency_code)}</td><td className="p-3 capitalize">{order.source}</td></tr>)}</tbody></table></div>
        <div className="mt-5 flex gap-2">{orders.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded border px-3 py-1 ${link.active ? 'bg-slate-900 text-white' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div>
    </SalesLayout>;
}
