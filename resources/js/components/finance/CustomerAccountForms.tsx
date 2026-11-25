import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { SelectField, TextAreaField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { formatBusinessDate, formatMoney } from '@/utils/format';
import { useBusinessToday } from '@/utils/businessDate';
import { newOperationId } from '@/utils/uuid';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import type { FormEvent } from 'react';

export type OpenOrder = { id: number; order_number: string; sale_date: string; due_date: string | null; total_incl_tax: string; remaining: string; is_overdue: boolean };
export type FinAccount = { id: number; name: string; code: string; currency_code: string; accepted_methods: string[] };
const METHODS = ['cash', 'card', 'bank_transfer', 'cheque'] as const;
const METHOD_LABEL: Record<string, string> = { cash: 'Espèces', card: 'TPE', bank_transfer: 'Virement', cheque: 'Chèque' };

const addDecimal = (a: string, b: string) => (Math.round((Number(a || 0) + Number(b || 0)) * 10000) / 10000).toFixed(4);

/**
 * Customer-centric settlement form: one payment, allocated across the customer's open orders of the active store
 * (manual or "oldest due first" preview). Totals shown here are only a hint: the server re-validates everything
 * (amounts, remaining per order, customer, store, account, period) and is the only authority.
 */
export function SettlementForm({ customerId, accounts, orders }: { customerId: number; accounts: FinAccount[]; orders: OpenOrder[] }) {
    const { t } = useI18n();
    const today = useBusinessToday();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [operationId, setOperationId] = useState(newOperationId);
    const [account, setAccount] = useState(accounts[0]?.id ? String(accounts[0].id) : '');
    const [method, setMethod] = useState<string>('bank_transfer');
    const [amount, setAmount] = useState('');
    const [date, setDate] = useState(today);
    const [reference, setReference] = useState('');
    const [notes, setNotes] = useState('');
    const [alloc, setAlloc] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState(false);
    const base = `/finance/clients/comptes/${customerId}`;
    const allocated = Object.values(alloc).reduce((sum, v) => addDecimal(sum, v || '0'), '0.0000');
    const advance = amount ? (Math.round((Number(amount) - Number(allocated)) * 10000) / 10000).toFixed(4) : '0.0000';
    const chosen = accounts.find((a) => String(a.id) === account);
    const compatible = accounts.filter((a) => a.accepted_methods.includes(method));
    // FCUX-15: an account that does not accept the chosen method is never kept selected (the server still validates the pair).
    const changeMethod = (next: string) => {
        setMethod(next);
        const stillValid = accounts.find((a) => String(a.id) === account)?.accepted_methods.includes(next);
        if (!stillValid) setAccount(String(accounts.find((a) => a.accepted_methods.includes(next))?.id ?? ''));
    };

    const preview = async () => {
        if (!amount) return;
        const { data } = await axios.post(`${base}/reglement/apercu`, { amount });
        const next: Record<number, string> = {};
        (data.allocations as { sales_order_id: number; amount: string }[]).forEach((row) => (next[row.sales_order_id] = row.amount));
        setAlloc(next);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        router.post(
            `${base}/reglement`,
            {
                client_operation_id: operationId, financial_account_id: account, method, amount, payment_date: date, reference, notes,
                allocations: Object.entries(alloc).filter(([, v]) => v && Number(v) > 0).map(([id, v]) => ({ sales_order_id: Number(id), amount: v })),
            },
            { preserveScroll: true, onSuccess: () => { setOperationId(newOperationId()); setAmount(''); setAlloc({}); }, onFinish: () => setBusy(false) },
        );
    };

    return (
        <form onSubmit={submit} className="surface-card mb-6 p-4" aria-label={t('fc2.settle.title')}>
            <h3 className="mb-3 text-base font-semibold text-ink">{t('fc2.settle.title')}</h3>
            <div className="grid gap-3 md:grid-cols-4">
                <SelectField label={t('fc2.settle.method')} value={method} onChange={(e) => changeMethod(e.target.value)}>
                    {METHODS.map((m) => <option key={m} value={m}>{METHOD_LABEL[m]}</option>)}
                </SelectField>
                <SelectField label={t('fc2.settle.account')} value={account} onChange={(e) => setAccount(e.target.value)} error={errors.financial_account_id ?? (compatible.length === 0 ? t('p3.settle.noCompatibleAccount') : undefined)}>
                    {compatible.length === 0 && <option value="">—</option>}
                    {compatible.map((a) => <option key={a.id} value={a.id}>{a.name} ({a.currency_code})</option>)}
                </SelectField>
                <TextField label={t('common.amount')} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} error={errors.amount} required />
                <TextField type="date" label={t('common.date')} value={date} onChange={(e) => setDate(e.target.value)} error={errors.payment_date} required />
                <TextField label={t('fc2.settle.reference')} value={reference} onChange={(e) => setReference(e.target.value)} />
                <TextAreaField label={t('fc2.settle.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={1} className="md:col-span-3" />
            </div>
            {errors.allocations && <div className="mt-3"><Alert tone="danger">{errors.allocations}</Alert></div>}
            {errors.client_operation_id && <div className="mt-3"><Alert tone="danger">{errors.client_operation_id}</Alert></div>}
            <div className="mt-4 overflow-x-auto">
                <table className="erp-table min-w-[560px] text-sm">
                    <thead><tr><th className="px-3 py-2">{t('fc2.settle.order')}</th><th className="px-3 py-2">{t('fc2.due')}</th><th className="num px-3 py-2">{t('fc2.settle.remaining')}</th><th className="num px-3 py-2">{t('fc2.settle.allocate')}</th></tr></thead>
                    <tbody>
                        {orders.length === 0 && <tr><td colSpan={4} className="px-3 py-4 text-ink-muted">{t('fc2.settle.noOrders')}</td></tr>}
                        {orders.map((o) => (
                            <tr key={o.id}>
                                <td className="px-3 py-2 font-medium">{o.order_number}</td>
                                <td className={`px-3 py-2 ${o.is_overdue ? 'text-danger' : 'text-ink-muted'}`}>{formatBusinessDate(o.due_date)}</td>
                                <td className="num px-3 py-2" dir="ltr">{formatMoney(o.remaining, chosen?.currency_code ?? 'MAD')}</td>
                                <td className="num px-3 py-2"><input aria-label={`${t('fc2.settle.allocate')} ${o.order_number}`} className="control-input h-9 w-32 px-2 text-end" inputMode="decimal" dir="ltr" value={alloc[o.id] ?? ''} onChange={(e) => setAlloc({ ...alloc, [o.id]: e.target.value })} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-ink-muted" aria-live="polite">{t('fc2.settle.allocated')} <strong dir="ltr">{formatMoney(allocated, chosen?.currency_code ?? 'MAD')}</strong> · {t('fc2.settle.advanceLeft')} <strong dir="ltr">{formatMoney(Number(advance) < 0 ? '0' : advance, chosen?.currency_code ?? 'MAD')}</strong></p>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={preview} disabled={!amount}>{t('fc2.settle.oldestFirst')}</Button>
                    <Button type="submit" loading={busy} disabled={!amount || !account || !compatible.some((a) => String(a.id) === account)}>{t('fc2.settle.submit')}</Button>
                </div>
            </div>
        </form>
    );
}

/** Allocate what is left of one advance to open orders (the server enforces every limit). */
export type OpeningTarget = { id: number; reference: string; currency_code: string; outstanding: string };

export function AdvanceAllocator({ customerId, paymentId, left, orders, currency, openings = [] }: { customerId: number; paymentId: number; left: string; orders: OpenOrder[]; currency: string; openings?: OpeningTarget[] }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [alloc, setAlloc] = useState<Record<number, string>>({});
    const [effective, setEffective] = useState('');
    const [openingAmounts, setOpeningAmounts] = useState<Record<number, string>>({});
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const rows = Object.entries(alloc).filter(([, v]) => v && Number(v) > 0).map(([id, v]) => ({ sales_order_id: Number(id), amount: v }));
        const openingRows = Object.entries(openingAmounts).filter(([, v]) => v && Number(v) > 0);
        const done = { preserveScroll: true, onSuccess: () => { setOpen(false); setAlloc({}); setOpeningAmounts({}); } };
        // Opening-balance applications are separate requests (one per opening line); order allocations go in one.
        openingRows.forEach(([id, amount], index) => router.post(`/finance/clients/comptes/${customerId}/avances/${paymentId}/solde-ouverture/${id}`, { amount, effective_date: effective || undefined }, index === openingRows.length - 1 && rows.length === 0 ? done : { preserveScroll: true }));
        if (rows.length > 0) router.post(`/finance/clients/comptes/${customerId}/avances/${paymentId}/affecter`, { allocations: rows, effective_date: effective || undefined }, done);
    };
    if (!open) return <Button size="sm" variant="outline" onClick={() => setOpen(true)}>{t('fc2.advance.allocate')} ({formatMoney(left, currency)})</Button>;
    return (
        <form onSubmit={submit} className="mt-2 space-y-2 rounded-card border border-line p-3">
            {(errors.allocations || errors.effective_date || errors.amount || errors.opening_balance) && <Alert tone="danger">{errors.allocations ?? errors.effective_date ?? errors.amount ?? errors.opening_balance}</Alert>}
            <label className="flex items-center justify-between gap-3 text-sm">
                <span>{t('fc6.alloc.effectiveDate')}<span className="block text-[12px] text-ink-muted">{t('fc6.alloc.effectiveHelp')}</span></span>
                <input type="date" className="control-input h-9 px-2" value={effective} onChange={(e) => setEffective(e.target.value)} />
            </label>
            {openings.map((o) => (
                <label key={`o${o.id}`} className="flex items-center justify-between gap-3 text-sm">
                    <span>{t('fc6.alloc.opening')} {o.reference} <span className="text-ink-muted">({formatMoney(o.outstanding, o.currency_code)})</span></span>
                    <input className="control-input h-9 w-32 px-2 text-end" inputMode="decimal" dir="ltr" value={openingAmounts[o.id] ?? ''} onChange={(e) => setOpeningAmounts({ ...openingAmounts, [o.id]: e.target.value })} />
                </label>
            ))}
            {orders.map((o) => (
                <label key={o.id} className="flex items-center justify-between gap-3 text-sm">
                    <span>{o.order_number} <span className="text-ink-muted">({formatMoney(o.remaining, currency)})</span></span>
                    <input className="control-input h-9 w-32 px-2 text-end" inputMode="decimal" dir="ltr" value={alloc[o.id] ?? ''} onChange={(e) => setAlloc({ ...alloc, [o.id]: e.target.value })} />
                </label>
            ))}
            <div className="flex gap-2"><Button type="submit" size="sm">{t('fc2.advance.confirm')}</Button><Button type="button" size="sm" variant="secondary" onClick={() => setOpen(false)}>{t('common.cancel')}</Button></div>
        </form>
    );
}

export function TermsForm({ customerId, terms }: { customerId: number; terms: Record<string, string | number | boolean | null> | null }) {
    const { t } = useI18n();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [v, setV] = useState({
        credit_enabled: Boolean(terms?.credit_enabled), credit_limit: String(terms?.credit_limit ?? ''), payment_term_days: String(terms?.payment_term_days ?? ''),
        min_deposit_percent: String(terms?.min_deposit_percent ?? ''), overdue_tolerance_days: String(terms?.overdue_tolerance_days ?? '0'),
        policy: String(terms?.policy ?? 'warn'), financial_status: String(terms?.financial_status ?? 'normal'),
    });
    const submit = (event: FormEvent) => { event.preventDefault(); router.post(`/finance/clients/comptes/${customerId}/conditions`, v, { preserveScroll: true }); };
    return (
        <form onSubmit={submit} className="surface-card p-4" aria-label={t('fc2.terms.title')}>
            <h3 className="mb-3 text-base font-semibold text-ink">{t('fc2.terms.title')}</h3>
            <div className="grid gap-3 md:grid-cols-3">
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={v.credit_enabled} onChange={(e) => setV({ ...v, credit_enabled: e.target.checked })} />{t('fc2.terms.creditEnabled')}</label>
                <TextField label={t('fc2.terms.limit')} inputMode="decimal" value={v.credit_limit} onChange={(e) => setV({ ...v, credit_limit: e.target.value })} error={errors.credit_limit} />
                <TextField label={t('fc2.terms.termDays')} inputMode="numeric" value={v.payment_term_days} onChange={(e) => setV({ ...v, payment_term_days: e.target.value })} error={errors.payment_term_days} />
                <TextField label={t('fc2.terms.deposit')} inputMode="decimal" value={v.min_deposit_percent} onChange={(e) => setV({ ...v, min_deposit_percent: e.target.value })} error={errors.min_deposit_percent} />
                <TextField label={t('fc2.terms.tolerance')} inputMode="numeric" value={v.overdue_tolerance_days} onChange={(e) => setV({ ...v, overdue_tolerance_days: e.target.value })} />
                <SelectField label={t('fc2.terms.policy')} value={v.policy} onChange={(e) => setV({ ...v, policy: e.target.value })}>
                    <option value="warn">{t('fc2.terms.policy.warn')}</option><option value="block">{t('fc2.terms.policy.block')}</option>
                </SelectField>
                <SelectField label={t('fc2.col.financialStatus')} value={v.financial_status} onChange={(e) => setV({ ...v, financial_status: e.target.value })}>
                    <option value="normal">{t('fc2.status.normal')}</option><option value="watch">{t('fc2.status.watch')}</option><option value="blocked">{t('fc2.status.blocked')}</option>
                </SelectField>
            </div>
            <p className="mt-2 text-[13px] text-ink-muted">{t('fc2.terms.help')}</p>
            <div className="mt-3"><Button type="submit">{t('common.save')}</Button></div>
        </form>
    );
}

export function OpeningBalanceForm({ customerId, currency }: { customerId: number; currency: string }) {
    const { t } = useI18n();
    const today = useBusinessToday();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [v, setV] = useState({ effective_date: today, amount: '', currency_code: currency, reference: '', reason: '' });
    const submit = (event: FormEvent) => { event.preventDefault(); router.post(`/finance/clients/comptes/${customerId}/solde-ouverture`, v, { preserveScroll: true, onSuccess: () => setV({ ...v, amount: '', reference: '', reason: '' }) }); };
    return (
        <form onSubmit={submit} className="surface-card p-4" aria-label={t('fc2.opening.title')}>
            <h3 className="mb-1 text-base font-semibold text-ink">{t('fc2.opening.title')}</h3>
            <p className="mb-3 text-[13px] text-ink-muted">{t('fc2.opening.help')}</p>
            <div className="grid gap-3 md:grid-cols-4">
                <TextField type="date" label={t('fc2.opening.date')} value={v.effective_date} onChange={(e) => setV({ ...v, effective_date: e.target.value })} error={errors.effective_date} required />
                <TextField label={t('fc2.opening.amount')} inputMode="decimal" value={v.amount} onChange={(e) => setV({ ...v, amount: e.target.value })} error={errors.amount} required />
                <TextField label={t('fc2.opening.currency')} value={v.currency_code} maxLength={3} onChange={(e) => setV({ ...v, currency_code: e.target.value.toUpperCase() })} error={errors.currency_code} required />
                <TextField label={t('fc2.settle.reference')} value={v.reference} onChange={(e) => setV({ ...v, reference: e.target.value })} error={errors.reference} required />
                <TextAreaField label={t('fc2.opening.reason')} value={v.reason} onChange={(e) => setV({ ...v, reason: e.target.value })} error={errors.reason} rows={2} className="md:col-span-4" required />
            </div>
            <div className="mt-3"><Button type="submit">{t('fc2.opening.submit')}</Button></div>
        </form>
    );
}
