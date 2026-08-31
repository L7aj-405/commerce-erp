import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Invoice = { id: number; invoice_number: string | null; invoice_date: string; customer_name: string | null; customer_company: string | null; total_incl_tax: string; currency_code: string; status: string; store: { name: string }; sales_order: { id: number; order_number: string } };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { invoices: { data: Invoice[]; links: PageLink[] }; filters: Record<string, string | undefined> };

export default function InvoiceIndex({ invoices, filters }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get('/invoices', Object.fromEntries(new FormData(event.currentTarget).entries()), { preserveState: true, replace: true });
    };
    return <SalesLayout><Head title="Invoices" />
        <div className="mb-5"><h2 className="text-xl font-semibold">Invoices</h2><p className="text-sm text-slate-500">Independent commercial documents for the active store.</p></div>
        <form onSubmit={submit} className="mb-6 grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-5"><input name="search" defaultValue={filters.search ?? ''} placeholder="Invoice, order, customer" className="rounded border px-3 py-2 md:col-span-2" /><input name="invoice_date" type="date" defaultValue={filters.invoice_date ?? ''} className="rounded border px-3 py-2" /><select name="status" defaultValue={filters.status ?? ''} className="rounded border px-3 py-2"><option value="">All statuses</option><option value="draft">Draft</option><option value="issued">Issued</option><option value="cancelled">Cancelled</option></select><button className="rounded bg-slate-900 px-4 py-2 text-white">Filter</button></form>
        <div className="overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">Invoice #</th><th className="p-3">Invoice date</th><th className="p-3">Order #</th><th className="p-3">Customer</th><th className="p-3">Store</th><th className="p-3">Status</th><th className="p-3 text-right">Total</th></tr></thead><tbody>{invoices.data.map((invoice) => <tr key={invoice.id} className="border-t"><td className="p-3"><Link href={`/invoices/${invoice.id}`} className="font-medium underline">{invoice.invoice_number ?? `Draft #${invoice.id}`}</Link></td><td className="p-3">{formatDate(invoice.invoice_date)}</td><td className="p-3"><Link href={`/sales/orders/${invoice.sales_order.id}`} className="underline">{invoice.sales_order.order_number}</Link></td><td className="p-3">{invoice.customer_company ?? invoice.customer_name ?? 'Walk-in'}</td><td className="p-3">{invoice.store.name}</td><td className="p-3 capitalize">{invoice.status}</td><td className="p-3 text-right">{formatMoney(invoice.total_incl_tax, invoice.currency_code)}</td></tr>)}{invoices.data.length === 0 && <tr><td colSpan={7} className="p-8 text-center text-slate-500">No invoices match these filters.</td></tr>}</tbody></table></div>
        <nav className="mt-5 flex flex-wrap gap-2">{invoices.links.map((link) => <Link key={link.label} href={link.url ?? '#'} preserveState className={`rounded border px-3 py-2 text-sm ${link.active ? 'bg-slate-900 text-white' : ''} ${!link.url ? 'pointer-events-none opacity-50' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
    </SalesLayout>;
}
