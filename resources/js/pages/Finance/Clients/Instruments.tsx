import { ParentTabs, SectionTabs, UNAVAILABLE, useSection } from '@/components/finance/FinanceClientsKit';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import { SelectField, TextAreaField, TextField } from '@/components/ui/form';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatBusinessDate, formatMoney } from '@/utils/format';
import { useBusinessToday } from '@/utils/businessDate';
import { newOperationId } from '@/utils/uuid';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

type Instrument = {
    id: number; type: string; instrument_number: string; customer_reference: string | null; issuing_bank: string | null; amount: string; currency_code: string; received_date: string;
    expected_collection_date: string | null; maturity_date: string | null; state: string; customer_id: number; sales_order_id: number | null; cleared_payment_id: number | null; customer: string;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    instruments: { data: Instrument[]; links: PageLink[]; from?: number | null; to?: number | null; total?: number };
    filters: { state?: string; search?: string; from?: string; to?: string };
    totals: { state: string; currency_code: string; total: string; count: number }[];
    events: { instrument_id: number; from_state: string | null; to_state: string; event_date: string; note: string | null }[];
    customers: { id: number; display_name: string }[];
    accounts: { id: number; name: string; currency_code: string }[];
    can: { manage: boolean; export?: boolean };
};

const SECTIONS = ['portfolio', 'deposits', 'cashed', 'rejected', 'dues'] as const;
const LABEL: Record<(typeof SECTIONS)[number], TranslationKey> = { portfolio: 'fc.inst.s.portfolio', deposits: 'fc.inst.s.deposits', cashed: 'fc.inst.s.cashed', rejected: 'fc.inst.s.rejected', dues: 'fc.inst.s.dues' };
const NEXT: Record<string, string[]> = { received: ['in_portfolio', 'submitted', 'cancelled'], in_portfolio: ['submitted', 'cancelled'], submitted: ['cleared', 'rejected', 'cancelled'], cleared: ['rejected'] };
const TONE: Record<string, 'neutral' | 'info' | 'warning' | 'positive' | 'danger'> = { received: 'info', in_portfolio: 'info', submitted: 'warning', cleared: 'positive', rejected: 'danger', cancelled: 'neutral' };

/** FC-07 — incoming customer cheques and effects. Receiving or holding one moves no money; only clearance posts the (single) payment. */
export default function FinanceClientsInstruments({ organization, instruments, filters, totals, events, customers, accounts, can }: Props) {
    const { t } = useI18n();
    const today = useBusinessToday();
    const section = useSection(SECTIONS, 'portfolio');
    const errors = usePage<{ errors?: Record<string, string> }>().props.errors ?? {};
    const [key, setKey] = useState(newOperationId);
    const [form, setForm] = useState({ customer_id: '', type: 'cheque', instrument_number: '', issuing_bank: '', customer_reference: '', amount: '', received_date: today, expected_collection_date: '', maturity_date: '', financial_account_id: '', notes: '' });
    const [busy, setBusy] = useState(false);
    const [showForm, setShowForm] = useState(false);
    // Server-side: the register, its totals, its pagination and its export all use the same filtered dataset.
    const rows = instruments.data;
    const exportQuery = new URLSearchParams({ section, ...(filters.search ? { search: filters.search } : {}), ...(filters.from ? { from: filters.from } : {}), ...(filters.to ? { to: filters.to } : {}) }).toString();
    const [q, setQ] = useState({ search: filters.search ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    const applyFilters = (e: FormEvent) => {
        e.preventDefault();
        router.get('/finance/clients/instruments', { section, ...Object.fromEntries(Object.entries(q).filter(([, v]) => v !== '')) }, { preserveState: true, preserveScroll: true });
    };
    const state = (s: string) => t(`fc4.inst.state.${s}` as TranslationKey);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        router.post('/finance/clients/instruments', { ...form, idempotency_key: key }, { preserveScroll: true, onSuccess: () => { setKey(newOperationId()); setShowForm(false); setForm({ ...form, instrument_number: '', amount: '', customer_reference: '', notes: '' }); }, onFinish: () => setBusy(false) });
    };
    const move = (i: Instrument, to: string) => {
        const note = to === 'rejected' || to === 'cancelled' ? window.prompt(t('fc4.inst.reasonPrompt')) : null;
        if ((to === 'rejected' || to === 'cancelled') && !note) return;
        router.post(`/finance/clients/instruments/${i.id}/etat`, { to, date: today, note }, { preserveScroll: true });
    };

    return (
        <ApplicationShell wide>
            <Head title={`${t('fc.inst.title')} — Finance`} />
            <PageHeader title={t('fc.inst.title')} description={`${organization.name} — ${t('fc.inst.description')}`} actions={<div className="flex flex-wrap gap-2">{can.export && <><ButtonLink href={`/finance/clients/instruments/export/csv?${exportQuery}`} variant="outline" size="sm">{t('p3.adv.exportCsv')}</ButtonLink><ButtonLink href={`/finance/clients/instruments/export/xlsx?${exportQuery}`} variant="outline" size="sm">{t('p3.adv.exportXlsx')}</ButtonLink></>}{can.manage && <Button size="sm" onClick={() => setShowForm((v) => !v)} aria-expanded={showForm}>{t('p3.inst.new')}</Button>}</div>} />
            <ParentTabs group="collections" active="instruments" />
            <div className="mb-4"><Alert tone="info">{t('fc.inst.notice')}</Alert></div>
            <div className="mb-4 flex flex-wrap gap-2 text-sm text-ink-muted">
                {totals.map((x) => <span key={`${x.state}${x.currency_code}`} className="surface-card px-3 py-1.5">{state(x.state)} : <strong dir="ltr">{formatMoney(x.total, x.currency_code)}</strong> ({x.count})</span>)}
            </div>
            {can.manage && showForm && (
                <form onSubmit={submit} className="surface-card mb-5 p-4" aria-label={t('fc4.inst.new')}>
                    <h3 className="mb-3 text-base font-semibold text-ink">{t('fc4.inst.new')}</h3>
                    <div className="grid gap-3 md:grid-cols-4">
                        <SelectField label={t('common.customer')} value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value })} error={errors.customer_id} required><option value="">—</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.display_name}</option>)}</SelectField>
                        <SelectField label={t('fc2.col.type')} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}><option value="cheque">{t('fc2.inst.cheque')}</option><option value="effet">{t('fc2.inst.effet')}</option></SelectField>
                        <TextField label={t('fc4.inst.number')} value={form.instrument_number} onChange={(e) => setForm({ ...form, instrument_number: e.target.value })} error={errors.instrument_number} required />
                        <TextField label={t('fc4.inst.bank')} value={form.issuing_bank} onChange={(e) => setForm({ ...form, issuing_bank: e.target.value })} />
                        <TextField label={t('common.amount')} inputMode="decimal" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} error={errors.amount} required />
                        <TextField type="date" label={t('fc4.inst.received')} value={form.received_date} onChange={(e) => setForm({ ...form, received_date: e.target.value })} error={errors.received_date} required />
                        <TextField type="date" label={t('fc3.inst.expected')} value={form.expected_collection_date} onChange={(e) => setForm({ ...form, expected_collection_date: e.target.value })} />
                        <TextField type="date" label={t('fc4.inst.maturity')} value={form.maturity_date} onChange={(e) => setForm({ ...form, maturity_date: e.target.value })} />
                        <SelectField label={t('fc2.settle.account')} value={form.financial_account_id} onChange={(e) => setForm({ ...form, financial_account_id: e.target.value })} error={errors.financial_account_id} required><option value="">—</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name} ({a.currency_code})</option>)}</SelectField>
                        <TextField label={t('fc4.inst.customerRef')} value={form.customer_reference} onChange={(e) => setForm({ ...form, customer_reference: e.target.value })} />
                        <TextAreaField label={t('fc2.settle.notes')} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} rows={1} className="md:col-span-2" />
                    </div>
                    <div className="mt-3 flex gap-2"><Button type="submit" loading={busy}>{t('fc4.inst.submit')}</Button><Button type="button" variant="secondary" onClick={() => setShowForm(false)}>{t('common.cancel')}</Button></div>
                </form>
            )}
            <SectionTabs sections={SECTIONS.map((k) => ({ key: k, label: t(LABEL[k]) }))} active={section} label={t('fc.inst.title')} />
            <form onSubmit={applyFilters} className="surface-card mb-3 flex flex-wrap items-end gap-3 p-3" role="search">
                <label className="flex min-w-[12rem] flex-1 flex-col gap-1 text-[13px] text-ink-muted">{t('common.search')}<input className="control-input h-9 px-2" value={q.search} onChange={(e) => setQ({ ...q, search: e.target.value })} /></label>
                <label className="flex flex-col gap-1 text-[13px] text-ink-muted">{t('p3.adv.from')}<input type="date" className="control-input h-9 px-2" value={q.from} onChange={(e) => setQ({ ...q, from: e.target.value })} /></label>
                <label className="flex flex-col gap-1 text-[13px] text-ink-muted">{t('p3.adv.to')}<input type="date" className="control-input h-9 px-2" value={q.to} onChange={(e) => setQ({ ...q, to: e.target.value })} /></label>
                <Button type="submit" size="sm">{t('p3.adv.apply')}</Button>
            </form>
            <TableShell label={t(LABEL[section])} state={rows.length === 0 ? 'empty' : 'ready'} emptyTitle={t('fc4.inst.empty')} footer={<TablePager links={instruments.links} from={instruments.from} to={instruments.to} total={instruments.total} />}>
                <table className="erp-table min-w-[1000px] text-sm">
                    <thead><tr><th className="px-4 py-2.5">{t('common.customer')}</th><th className="px-4 py-2.5">{t('fc2.col.type')}</th><th className="px-4 py-2.5">{t('fc4.inst.number')}</th><th className="px-4 py-2.5">{t('fc4.inst.bank')}</th>
                        <th className="px-4 py-2.5">{t('fc4.inst.maturity')}</th><th className="px-4 py-2.5">{t('fc3.inst.expected')}</th><th className="num px-4 py-2.5">{t('common.amount')}</th><th className="px-4 py-2.5">{t('common.status')}</th><th className="px-4 py-2.5" /></tr></thead>
                    <tbody>
                        {rows.map((i) => (
                            <tr key={i.id}>
                                <td className="px-4 py-2.5"><Link href={`/finance/clients/comptes/${i.customer_id}`}>{i.customer}</Link></td>
                                <td className="px-4 py-2.5">{i.type === 'cheque' ? t('fc2.inst.cheque') : t('fc2.inst.effet')}</td>
                                <td className="px-4 py-2.5 font-medium">{i.instrument_number}</td><td className="px-4 py-2.5 text-ink-muted">{i.issuing_bank ?? UNAVAILABLE}</td>
                                <td className="px-4 py-2.5">{formatBusinessDate(i.maturity_date, UNAVAILABLE)}</td><td className="px-4 py-2.5">{formatBusinessDate(i.expected_collection_date, UNAVAILABLE)}</td>
                                <td className="num px-4 py-2.5" dir="ltr">{formatMoney(i.amount, i.currency_code)}</td>
                                <td className="px-4 py-2.5"><DocBadge tone={TONE[i.state] ?? 'neutral'}>{state(i.state)}</DocBadge>
                                    <ul className="mt-1 text-[12px] text-ink-muted">{events.filter((e) => e.instrument_id === i.id).map((e, n) => <li key={n}>{formatBusinessDate(e.event_date)} → {state(e.to_state)}{e.note ? ` (${e.note})` : ''}</li>)}</ul></td>
                                <td className="px-4 py-2.5 text-end">{can.manage && (NEXT[i.state] ?? []).map((to) => <Button key={to} size="sm" variant="outline" className="ms-1" onClick={() => move(i, to)}>{state(to)}</Button>)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
        </ApplicationShell>
    );
}
