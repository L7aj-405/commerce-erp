import PaymentsLayout from '@/layouts/PaymentsLayout';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Account = { id: number; name: string; code: string };
type Payment = { id: number; payment_number: string; method: string; status: string; amount: string; currency_code: string; payment_date: string; reference: string | null; financial_account: Account; allocations: { sales_order: { id: number; order_number: string; customer_name: string | null; customer_company: string | null } }[]; received_by: { name: string } };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { payments: { data: Payment[]; links: PageLink[] }; accounts: Account[]; filters: Record<string, string | number | undefined> };

export default function PaymentIndex({ payments, accounts, filters }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget).entries());
        router.get('/payments', data, { preserveState: true, replace: true });
    };

    return <PaymentsLayout><Head title="Payments" />
        <div className="mb-5"><h2 className="text-xl font-semibold">Payment register</h2><p className="text-sm text-slate-500">Posted and reversed payments for the active store.</p></div>
        <form onSubmit={submit} className="mb-6 grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-6">
            <input name="search" defaultValue={String(filters.search ?? '')} placeholder="Payment, order, customer" className="rounded border px-3 py-2 md:col-span-2" />
            <input name="date" type="date" defaultValue={String(filters.date ?? '')} className="rounded border px-3 py-2" />
            <select name="method" defaultValue={String(filters.method ?? '')} className="rounded border px-3 py-2"><option value="">All methods</option><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select>
            <select name="status" defaultValue={String(filters.status ?? '')} className="rounded border px-3 py-2"><option value="">All statuses</option><option value="posted">Posted</option><option value="reversed">Reversed</option></select>
            <select name="account" defaultValue={String(filters.account ?? '')} className="rounded border px-3 py-2"><option value="">All accounts</option>{accounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</select>
            <button className="rounded bg-slate-900 px-4 py-2 text-white">Filter</button>
        </form>
        <div className="overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">Payment</th><th className="p-3">Date</th><th className="p-3">Order / customer</th><th className="p-3">Method / account</th><th className="p-3">Received by</th><th className="p-3">Status</th><th className="p-3 text-right">Amount</th></tr></thead><tbody>{payments.data.map((payment) => { const order = payment.allocations[0]?.sales_order; return <tr key={payment.id} className="border-t"><td className="p-3"><Link href={`/payments/${payment.id}`} className="font-medium underline">{payment.payment_number}</Link><br /><span className="text-slate-500">{payment.reference}</span></td><td className="p-3">{formatDate(payment.payment_date)}</td><td className="p-3">{order && <><Link href={`/sales/orders/${order.id}`} className="underline">{order.order_number}</Link><br /><span className="text-slate-500">{order.customer_name ?? order.customer_company ?? 'Walk-in'}</span></>}</td><td className="p-3 capitalize">{payment.method.replaceAll('_', ' ')}<br /><span className="text-slate-500">{payment.financial_account?.name}</span></td><td className="p-3">{payment.received_by?.name}</td><td className="p-3 capitalize">{payment.status}</td><td className="p-3 text-right font-medium">{formatMoney(payment.amount, payment.currency_code)}</td></tr>; })}{payments.data.length === 0 && <tr><td colSpan={7} className="p-8 text-center text-slate-500">No payments match these filters.</td></tr>}</tbody></table></div>
        <nav className="mt-5 flex flex-wrap gap-2">{payments.links.map((link) => <Link key={link.label} href={link.url ?? '#'} preserveState className={`rounded border px-3 py-2 text-sm ${link.active ? 'bg-slate-900 text-white' : ''} ${!link.url ? 'pointer-events-none opacity-50' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
    </PaymentsLayout>;
}
