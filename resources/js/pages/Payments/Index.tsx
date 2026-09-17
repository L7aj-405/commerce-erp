import Pagination from '@/components/ui/Pagination';
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
        {payments.data.length === 0 ? (
            <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">No payments match these filters.</div>
        ) : (
            <>
                {/* Desktop/tablet: table */}
                <div className="hidden overflow-x-auto rounded-lg border md:block">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-50">
                            <tr><th className="p-3">Payment</th><th className="p-3">Date</th><th className="p-3">Order / customer</th><th className="p-3">Method / account</th><th className="p-3">Received by</th><th className="p-3">Status</th><th className="p-3 text-right">Amount</th></tr>
                        </thead>
                        <tbody>
                            {payments.data.map((payment) => {
                                const order = payment.allocations[0]?.sales_order;
                                return (
                                    <tr key={payment.id} className="border-t">
                                        <td className="p-3"><Link href={`/payments/${payment.id}`} className="font-medium underline">{payment.payment_number}</Link><br /><span className="text-slate-500">{payment.reference}</span></td>
                                        <td className="p-3">{formatDate(payment.payment_date)}</td>
                                        <td className="p-3">{order && <><Link href={`/sales/orders/${order.id}`} className="underline">{order.order_number}</Link><br /><span className="text-slate-500">{order.customer_name ?? order.customer_company ?? 'Walk-in'}</span></>}</td>
                                        <td className="p-3 capitalize">{payment.method.replaceAll('_', ' ')}<br /><span className="text-slate-500">{payment.financial_account?.name}</span></td>
                                        <td className="p-3">{payment.received_by?.name}</td>
                                        <td className="p-3 capitalize">{payment.status}</td>
                                        <td className="p-3 text-right font-medium">{formatMoney(payment.amount, payment.currency_code)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {/* Mobile: stacked cards */}
                <ul className="space-y-3 md:hidden">
                    {payments.data.map((payment) => {
                        const order = payment.allocations[0]?.sales_order;
                        return (
                            <li key={payment.id} className="rounded-lg border p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/payments/${payment.id}`} className="block truncate font-medium underline">{payment.payment_number}</Link>
                                        {payment.reference && <p className="truncate text-[13px] text-slate-500">{payment.reference}</p>}
                                    </div>
                                    <p className="shrink-0 text-sm font-semibold">{formatMoney(payment.amount, payment.currency_code)}</p>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Date</dt>
                                        <dd className="text-slate-600">{formatDate(payment.payment_date)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Statut</dt>
                                        <dd className="capitalize text-slate-600">{payment.status}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Commande / client</dt>
                                        <dd className="truncate">
                                            {order ? (
                                                <>
                                                    <Link href={`/sales/orders/${order.id}`} className="underline">{order.order_number}</Link>
                                                    <span className="block text-slate-500">{order.customer_name ?? order.customer_company ?? 'Walk-in'}</span>
                                                </>
                                            ) : '—'}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Mode / compte</dt>
                                        <dd className="truncate capitalize text-slate-600">
                                            {payment.method.replaceAll('_', ' ')}
                                            <span className="block normal-case text-slate-500">{payment.financial_account?.name}</span>
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Reçu par</dt>
                                        <dd className="truncate text-slate-600">{payment.received_by?.name ?? '—'}</dd>
                                    </div>
                                </dl>
                            </li>
                        );
                    })}
                </ul>
            </>
        )}
        <Pagination links={payments.links} />
    </PaymentsLayout>;
}
