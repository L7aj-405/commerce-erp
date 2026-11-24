import { AdvanceAllocator, type OpenOrder, type OpeningTarget } from '@/components/finance/CustomerAccountForms';
import { ParentTabs } from '@/components/finance/FinanceClientsKit';
import { Badge, type StatusVariant } from '@/components/ui/StatusBadge';
import { Button, ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatBusinessDate as formatDate, formatMoney } from '@/utils/format';
import { label, paymentMethodLabel } from '@/utils/labels';
import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { FormEvent } from 'react';

type State = 'available' | 'partial' | 'allocated' | 'reversed';
type Advance = {
    id: number; payment_number: string; payment_date: string; method: string; amount: string; allocated: string; opening_settled: string; remaining: string; currency_code: string; state: State;
    customer_id: number; customer_name: string; account_name: string; store_id: number; store_name: string;
    allocations: { order_id: number; order_number: string; amount: string; effective_date: string }[];
    releases: { order_number: string; amount: string; effective_date: string; released_date: string; reason: string }[];
    openings: { id: number; reference: string | null; amount: string; effective_date: string; released_date: string | null; reason: string | null }[];
};
type Filters = { q: string; customer_id: number | null; store_id: number | null; from: string | null; to: string | null; status: string | null };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    advances: { data: Advance[]; links: PageLink[]; total: number; from: number | null; to: number | null };
    helpers: Record<string, { orders: OpenOrder[]; openings: OpeningTarget[] }>;
    filters: Filters;
    statuses: State[];
    stores: { id: number; name: string; code: string }[];
    activeStoreId: number | null;
    can: { allocate: boolean; accounts: boolean; export: boolean; payments: boolean };
};

const TONE: Record<State, StatusVariant> = { available: 'success', partial: 'info', allocated: 'neutral', reversed: 'danger' };

/** Customer advances register: derived, read-only figures; allocation reuses the account's allocator (ManageCustomerAdvanceAction). */
export default function FinanceClientsAdvances({ organization, advances, helpers, filters, statuses, stores, activeStoreId, can }: Props) {
    const { t } = useI18n();
    const [open, setOpen] = useState<number | null>(null);
    const [form, setForm] = useState({ q: filters.q ?? '', status: filters.status ?? '', store_id: filters.store_id ? String(filters.store_id) : '', from: filters.from ?? '', to: filters.to ?? '', customer_id: filters.customer_id ? String(filters.customer_id) : '' });
    const apply = (event: FormEvent) => {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
        router.get('/finance/clients/avances', query, { preserveState: true, preserveScroll: true });
    };
    const exportQuery = new URLSearchParams(Object.entries(form).filter(([, v]) => v !== '') as [string, string][]).toString();
    const stateLabel = (state: State) => t(`p3.adv.state.${state}` as TranslationKey);

    return (
        <ApplicationShell wide>
            <Head title={`${t('p3.adv.title')} — Finance`} />
            <PageHeader
                title={t('p3.adv.title')}
                description={`${organization.name} · ${advances.total}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.export && (
                            <>
                                <ButtonLink href={`/finance/clients/avances/export/csv${exportQuery ? `?${exportQuery}` : ''}`} variant="outline" size="sm">{t('p3.adv.exportCsv')}</ButtonLink>
                                <ButtonLink href={`/finance/clients/avances/export/xlsx${exportQuery ? `?${exportQuery}` : ''}`} variant="outline" size="sm">{t('p3.adv.exportXlsx')}</ButtonLink>
                            </>
                        )}
                    </div>
                }
            />
            <ParentTabs group="collections" active="advances" />
            <p className="mb-3 text-sm text-ink-muted">{t('p3.adv.desc')}</p>

            <form onSubmit={apply} className="surface-card mb-4 flex flex-wrap items-end gap-3 p-3" role="search">
                <label className="flex min-w-[12rem] flex-1 flex-col gap-1 text-[13px] text-ink-muted">{t('common.search')}
                    <input className="control-input h-9 px-2" value={form.q} placeholder={t('p3.adv.search')} onChange={(e) => setForm({ ...form, q: e.target.value })} />
                </label>
                <label className="flex flex-col gap-1 text-[13px] text-ink-muted">{t('common.status')}
                    <select className="control-input h-9 px-2" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                        <option value="">{t('p3.adv.allStatuses')}</option>
                        {statuses.map((s) => <option key={s} value={s}>{stateLabel(s)}</option>)}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-[13px] text-ink-muted">{t('common.store')}
                    <select className="control-input h-9 px-2" value={form.store_id} onChange={(e) => setForm({ ...form, store_id: e.target.value })}>
                        <option value="">{t('p3.adv.allStores')}</option>
                        {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-[13px] text-ink-muted">{t('p3.adv.from')}
                    <input type="date" className="control-input h-9 px-2" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                </label>
                <label className="flex flex-col gap-1 text-[13px] text-ink-muted">{t('p3.adv.to')}
                    <input type="date" className="control-input h-9 px-2" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                </label>
                <Button type="submit" size="sm">{t('p3.adv.apply')}</Button>
            </form>

            <TableShell
                label={t('p3.adv.title')}
                state={advances.data.length === 0 ? 'empty' : 'ready'}
                emptyTitle={t('p3.adv.empty')}
                footer={advances.data.length === 0 ? undefined : <TablePager links={advances.links} from={advances.from} to={advances.to} total={advances.total} />}
            >
                <table className="erp-table min-w-[1020px]">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('p3.adv.col.reference')}</th>
                            <th className="px-4 py-2.5">{t('p3.adv.col.customer')}</th>
                            <th className="px-4 py-2.5">{t('p3.adv.col.received')}</th>
                            <th className="px-4 py-2.5">{t('p3.adv.col.account')}</th>
                            <th className="num px-4 py-2.5">{t('p3.adv.col.amount')}</th>
                            <th className="num px-4 py-2.5">{t('p3.adv.col.allocated')}</th>
                            <th className="num px-4 py-2.5">{t('p3.adv.col.remaining')}</th>
                            <th className="px-4 py-2.5">{t('p3.adv.col.store')}</th>
                            <th className="px-4 py-2.5">{t('p3.adv.col.status')}</th>
                            <th className="px-4 py-2.5"><span className="sr-only">{t('common.actions')}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {advances.data.map((a) => {
                            const expanded = open === a.id;
                            const helper = helpers[String(a.customer_id)];
                            const allocated = Number(a.allocated) + Number(a.opening_settled);
                            return (
                                <Fragment key={a.id}>
                                    <tr>
                                        <td className="bidi-isolate px-4 py-2.5 font-medium text-ink" dir="ltr">{a.payment_number}</td>
                                        <td className="px-4 py-2.5">{can.accounts ? <Link href={`/finance/clients/comptes/${a.customer_id}?section=payments`}>{a.customer_name}</Link> : a.customer_name}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{formatDate(a.payment_date)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{a.account_name} · {label(paymentMethodLabel, a.method)}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(a.amount, a.currency_code)}</td>
                                        <td className="num px-4 py-2.5" dir="ltr">{formatMoney(String(allocated), a.currency_code)}</td>
                                        <td className="num px-4 py-2.5 font-medium" dir="ltr">{formatMoney(a.remaining, a.currency_code)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{a.store_name}</td>
                                        <td className="px-4 py-2.5"><Badge variant={TONE[a.state]}>{stateLabel(a.state)}</Badge></td>
                                        <td className="px-4 py-2.5 text-end">
                                            <Button size="sm" variant="secondary" aria-expanded={expanded} onClick={() => setOpen(expanded ? null : a.id)}>{expanded ? t('p3.adv.hide') : t('p3.adv.details')}</Button>
                                        </td>
                                    </tr>
                                    {expanded && (
                                        <tr>
                                            <td colSpan={10} className="bg-sage/40 px-4 py-3">
                                                <div className="grid gap-4 md:grid-cols-3">
                                                    <HistoryBlock title={t('p3.adv.allocations')} empty={t('p3.adv.noHistory')} rows={a.allocations.map((x) => `${x.order_number} · ${formatMoney(x.amount, a.currency_code)} · ${t('p3.adv.effective')} ${formatDate(x.effective_date)}`)} />
                                                    <HistoryBlock title={t('p3.adv.openings')} empty={t('p3.adv.noHistory')} rows={a.openings.map((x) => `${x.reference ?? '—'} · ${formatMoney(x.amount, a.currency_code)} · ${t('p3.adv.effective')} ${formatDate(x.effective_date)}${x.released_date ? ` · ${t('p3.adv.releasedOn')} ${formatDate(x.released_date)}` : ''}`)} />
                                                    <HistoryBlock title={t('p3.adv.releases')} empty={t('p3.adv.noHistory')} rows={a.releases.map((x) => `${x.order_number} · ${formatMoney(x.amount, a.currency_code)} · ${formatDate(x.effective_date)} → ${formatDate(x.released_date)} · ${x.reason}`)} />
                                                </div>
                                                <div className="mt-3 flex flex-wrap items-start gap-3">
                                                    {can.payments && <ButtonLink href={`/payments/${a.id}`} variant="outline" size="sm">{t('p3.adv.originPayment')}</ButtonLink>}
                                                    {can.accounts && <ButtonLink href={`/finance/clients/comptes/${a.customer_id}?section=payments`} variant="outline" size="sm">{t('p3.adv.customerAccount')}</ButtonLink>}
                                                    {(a.state === 'available' || a.state === 'partial') && (can.allocate && helper && a.store_id === activeStoreId
                                                        ? <AdvanceAllocator customerId={a.customer_id} paymentId={a.id} left={a.remaining} orders={helper.orders} currency={a.currency_code} openings={helper.openings} />
                                                        : <p className="text-[13px] text-ink-muted">{t('p3.adv.cannotAllocate')}</p>)}
                                                </div>
                                                {(a.allocations.length > 0 || a.openings.some((x) => !x.released_date)) && <p className="mt-2 text-[13px] text-ink-muted">{t('p3.adv.manageOnAccount')}</p>}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>
            <p className="mt-3 text-[13px] text-ink-muted">{t('p3.adv.note')}</p>
        </ApplicationShell>
    );
}

function HistoryBlock({ title, rows, empty }: { title: string; rows: string[]; empty: string }) {
    return (
        <section>
            <h3 className="mb-1 text-[13px] font-semibold text-ink">{title}</h3>
            {rows.length === 0 ? <p className="text-[13px] text-ink-muted">{empty}</p> : <ul className="space-y-1 text-[13px] text-ink-muted">{rows.map((r, i) => <li key={i} dir="auto">{r}</li>)}</ul>}
        </section>
    );
}
