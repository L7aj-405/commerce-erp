import type { OpenOrder } from '@/components/finance/CustomerAccountForms';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { SelectField, TextField } from '@/components/ui/form';
import { TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { formatBusinessDate, formatMoney } from '@/utils/format';
import { useBusinessToday } from '@/utils/businessDate';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

export type PlanInstallment = { order_id: number; order_number: string; sequence: number; due_date: string; amount: string; outstanding: string; currency_code: string; is_overdue: boolean };
export type Promise_ = { id: number; sales_order_id: number | null; promised_date: string; amount: string; currency_code: string; status: string; note: string | null };

/** Replace the payment schedule of one open order: the server requires the installments to add up exactly to the remaining balance. */
export function ScheduleEditor({ customerId, order, currency }: { customerId: number; order: OpenOrder; currency: string }) {
    const { t } = useI18n();
    const today = useBusinessToday();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState([{ due_date: order.due_date ?? today, amount: order.remaining }]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(`/finance/clients/comptes/${customerId}/echeancier/${order.id}`, { installments: rows }, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };
    if (!open) return <Button size="sm" variant="outline" onClick={() => setOpen(true)}>{t('fc3.schedule.edit')}</Button>;
    return (
        <form onSubmit={submit} className="mt-2 space-y-2 rounded-card border border-line p-3">
            <p className="text-[13px] text-ink-muted">{t('fc3.schedule.help')} <strong dir="ltr">{formatMoney(order.remaining, currency)}</strong></p>
            {errors.installments && <Alert tone="danger">{errors.installments}</Alert>}
            {rows.map((row, i) => (
                <div key={i} className="flex flex-wrap items-center gap-2">
                    <input type="date" className="control-input h-9 px-2" aria-label={t('fc2.due')} value={row.due_date} onChange={(e) => setRows(rows.map((r, n) => (n === i ? { ...r, due_date: e.target.value } : r)))} />
                    <input className="control-input h-9 w-32 px-2 text-end" inputMode="decimal" dir="ltr" aria-label={t('common.amount')} value={row.amount} onChange={(e) => setRows(rows.map((r, n) => (n === i ? { ...r, amount: e.target.value } : r)))} />
                    {rows.length > 1 && <Button type="button" size="sm" variant="secondary" onClick={() => setRows(rows.filter((_, n) => n !== i))}>×</Button>}
                </div>
            ))}
            <div className="flex gap-2">
                <Button type="button" size="sm" variant="secondary" onClick={() => setRows([...rows, { due_date: today, amount: '' }])}>{t('fc3.schedule.add')}</Button>
                <Button type="submit" size="sm">{t('common.save')}</Button>
                <Button type="button" size="sm" variant="secondary" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
            </div>
        </form>
    );
}

export function InstallmentsTable({ rows }: { rows: PlanInstallment[] }) {
    const { t } = useI18n();
    return (
        <TableShell label={t('fc3.schedule.installments')} state={rows.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc3.schedule.none')}>
            <table className="erp-table min-w-[620px]">
                <thead><tr><th className="px-4 py-2.5">{t('fc2.settle.order')}</th><th className="px-4 py-2.5">#</th><th className="px-4 py-2.5">{t('fc2.due')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="num px-4 py-2.5">{t('fc2.settle.remaining')}</th></tr></thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={`${r.order_id}-${r.sequence}`}>
                            <td className="px-4 py-2.5 font-medium">{r.order_number}</td><td className="px-4 py-2.5">{r.sequence}</td>
                            <td className={`px-4 py-2.5 ${r.is_overdue ? 'text-danger' : 'text-ink-muted'}`}>{formatBusinessDate(r.due_date)}{r.is_overdue && ` · ${t('fc2.col.overdue')}`}</td>
                            <td className="num px-4 py-2.5" dir="ltr">{formatMoney(r.amount, r.currency_code)}</td><td className="num px-4 py-2.5 font-medium" dir="ltr">{formatMoney(r.outstanding, r.currency_code)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </TableShell>
    );
}

export function PromisesPanel({ customerId, promises, orders, canManage, currency }: { customerId: number; promises: Promise_[]; orders: OpenOrder[]; canManage: boolean; currency: string }) {
    const { t } = useI18n();
    const today = useBusinessToday();
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [v, setV] = useState({ sales_order_id: '', promised_date: today, amount: '', note: '' });
    const submit = (event: FormEvent) => { event.preventDefault(); router.post(`/finance/clients/comptes/${customerId}/promesses`, v, { preserveScroll: true, onSuccess: () => setV({ ...v, amount: '', note: '' }) }); };
    const mark = (id: number, status: string) => router.post(`/finance/clients/comptes/${customerId}/promesses/${id}`, { status }, { preserveScroll: true });
    return (
        <section className="mt-6" aria-labelledby="promises-title">
            <h3 id="promises-title" className="mb-2 text-base font-semibold text-ink">{t('fc3.reminders.promises')}</h3>
            {canManage && (
                <form onSubmit={submit} className="surface-card mb-3 grid gap-3 p-4 md:grid-cols-5">
                    <SelectField label={t('fc2.settle.order')} value={v.sales_order_id} onChange={(e) => setV({ ...v, sales_order_id: e.target.value })} error={errors.sales_order_id}><option value="">—</option>{orders.map((o) => <option key={o.id} value={o.id}>{o.order_number}</option>)}</SelectField>
                    <TextField type="date" label={t('fc3.promise.date')} value={v.promised_date} onChange={(e) => setV({ ...v, promised_date: e.target.value })} error={errors.promised_date} required />
                    <TextField label={t('common.amount')} inputMode="decimal" value={v.amount} onChange={(e) => setV({ ...v, amount: e.target.value })} error={errors.amount} required />
                    <TextField label={t('fc2.settle.notes')} value={v.note} onChange={(e) => setV({ ...v, note: e.target.value })} />
                    <div className="flex items-end"><Button type="submit">{t('fc3.promise.add')}</Button></div>
                </form>
            )}
            <TableShell label={t('fc3.reminders.promises')} state={promises.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc3.reminders.noPromises')}>
                <table className="erp-table min-w-[620px]">
                    <thead><tr><th className="px-4 py-2.5">{t('fc3.promise.date')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="px-4 py-2.5">{t('common.status')}</th><th className="px-4 py-2.5">{t('fc2.settle.notes')}</th><th /></tr></thead>
                    <tbody>{promises.map((p) => (
                        <tr key={p.id}><td className="px-4 py-2.5">{formatBusinessDate(p.promised_date)}</td><td className="num px-4 py-2.5" dir="ltr">{formatMoney(p.amount, p.currency_code || currency)}</td><td className="px-4 py-2.5">{t(`fc3.promise.status.${p.status}` as TranslationKey)}</td><td className="px-4 py-2.5 text-ink-muted">{p.note ?? '—'}</td>
                            <td className="px-4 py-2.5 text-end">{canManage && p.status === 'open' && (['kept', 'broken', 'cancelled'] as const).map((s) => <Button key={s} size="sm" variant="outline" className="ms-1" onClick={() => mark(p.id, s)}>{t(`fc3.promise.status.${s}` as TranslationKey)}</Button>)}</td></tr>
                    ))}</tbody>
                </table>
            </TableShell>
        </section>
    );
}
