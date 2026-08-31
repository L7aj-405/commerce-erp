import { formatMoney } from '@/utils/format';
import { useMemo, useState } from 'react';

export type PosFinancialAccount = { id: number; name: string; code: string; type: string; currency_code: string };
export type PosPaymentEntry = { method: string; financial_account_id: number | ''; amount: string; cash_received: string; reference: string };

type Props = {
    total: string;
    currencyCode: string;
    accounts: PosFinancialAccount[];
    accountTypes: Record<string, string[]>;
    errors: string[];
    processing: boolean;
    onClose: () => void;
    onConfirm: (payments: PosPaymentEntry[]) => void;
};

const labels: Record<string, string> = { cash: 'Cash', card: 'TPE / Card', bank_transfer: 'Bank Transfer', cheque: 'Cheque' };

export default function PosCheckoutModal({ total, currencyCode, accounts, accountTypes, errors, processing, onClose, onConfirm }: Props) {
    const compatible = (method: string) => accounts.filter((account) => accountTypes[method]?.includes(account.type));
    const accountFor = (method: string) => compatible(method).length === 1 ? compatible(method)[0].id : '';
    const row = (method = 'cash', amount = ''): PosPaymentEntry => ({ method, financial_account_id: accountFor(method), amount, cash_received: method === 'cash' ? amount : '', reference: '' });
    const [payments, setPayments] = useState<PosPaymentEntry[]>(() => [row('cash', total)]);
    const entered = useMemo(() => payments.reduce((sum, payment) => sum + (Number(payment.amount) || 0), 0), [payments]);
    const due = Number(total) || 0;
    const remaining = due - entered;
    const cashValid = payments.every((payment) => payment.method !== 'cash' || (Number(payment.cash_received) || 0) >= (Number(payment.amount) || 0));
    const valid = Math.abs(remaining) < 0.00005 && entered > 0 && cashValid && payments.every((payment) => payment.financial_account_id !== '' && (Number(payment.amount) || 0) > 0);
    const update = (index: number, values: Partial<PosPaymentEntry>) => setPayments((current) => current.map((payment, position) => position === index ? { ...payment, ...values } : payment));
    const chooseMethod = (index: number, method: string) => update(index, { method, financial_account_id: accountFor(method), cash_received: method === 'cash' ? payments[index].amount : '' });
    const quick = (method: string) => setPayments([row(method, total)]);

    return <div role="dialog" aria-modal="true" className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4"><div className="max-h-[95vh] w-full max-w-3xl space-y-5 overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
        <div><h2 className="text-xl font-semibold">Payment checkout</h2><p className="text-sm text-slate-500">Full payment is required before this POS sale is fulfilled.</p></div>
        <dl className="grid grid-cols-3 gap-3 rounded-lg bg-slate-50 p-4 text-sm"><div><dt>Order total</dt><dd className="font-semibold">{formatMoney(due, currencyCode)}</dd></div><div><dt>Payments entered</dt><dd className="font-semibold">{formatMoney(entered, currencyCode)}</dd></div><div><dt>{remaining < 0 ? 'Overpayment' : 'Remaining'}</dt><dd className={`font-semibold ${remaining === 0 ? 'text-emerald-700' : 'text-amber-700'}`}>{formatMoney(Math.abs(remaining), currencyCode)}</dd></div></dl>
        <div className="flex flex-wrap gap-2">{Object.entries(labels).map(([method, label]) => <button key={method} type="button" onClick={() => quick(method)} className="rounded border px-3 py-2 text-sm">{label} · full amount</button>)}</div>
        <div className="space-y-3">{payments.map((payment, index) => <section key={index} className="grid gap-3 rounded-lg border p-4 md:grid-cols-6"><label className="text-sm">Method<select autoFocus={index === 0} value={payment.method} onChange={(event) => chooseMethod(index, event.target.value)} className="mt-1 w-full rounded border px-3 py-2">{Object.entries(labels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label className="text-sm md:col-span-2">Account<select required value={payment.financial_account_id} onChange={(event) => update(index, { financial_account_id: event.target.value ? Number(event.target.value) : '' })} className="mt-1 w-full rounded border px-3 py-2"><option value="">Select compatible account</option>{compatible(payment.method).map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name} ({account.type.replaceAll('_', ' ')})</option>)}</select></label><label className="text-sm">Payment amount<input required inputMode="decimal" value={payment.amount} onChange={(event) => update(index, { amount: event.target.value })} className="mt-1 w-full rounded border px-3 py-2" /></label>{payment.method === 'cash' ? <label className="text-sm">Cash received<input required inputMode="decimal" value={payment.cash_received} onChange={(event) => update(index, { cash_received: event.target.value })} className="mt-1 w-full rounded border px-3 py-2" /><span className="mt-1 block text-xs text-slate-500">Change: {formatMoney(Math.max(0, (Number(payment.cash_received) || 0) - (Number(payment.amount) || 0)), currencyCode)}</span></label> : <label className="text-sm md:col-span-2">Reference<input maxLength={255} value={payment.reference} onChange={(event) => update(index, { reference: event.target.value })} placeholder="Optional" className="mt-1 w-full rounded border px-3 py-2" /></label>}{payments.length > 1 && <button type="button" onClick={() => setPayments((current) => current.filter((_, position) => position !== index))} className="self-end text-left text-sm text-red-700">Remove</button>}</section>)}</div>
        {!cashValid && <p className="rounded bg-red-50 p-3 text-sm text-red-700">Cash received cannot be lower than the Cash Payment amount.</p>}
        {errors.map((error, index) => <p key={index} className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>)}
        <div className="flex flex-wrap justify-between gap-2"><button type="button" onClick={() => setPayments((current) => [...current, row()])} className="rounded border px-4 py-2">+ Add split payment</button><div className="flex gap-2"><button type="button" onClick={onClose} className="rounded border px-4 py-2">Back</button><button type="button" disabled={!valid || processing} onClick={() => onConfirm(payments)} className="rounded bg-emerald-700 px-5 py-2 font-medium text-white disabled:opacity-40">{processing ? 'Completing…' : 'Confirm Payment & Sale'}</button></div></div>
    </div></div>;
}
