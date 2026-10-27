import CommissionTabs from '@/components/finance/CommissionTabs';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import { useCommissionLabels } from '@/components/finance/CommissionFilters';
import { Button } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Entry = {
    id: number; occurred_at: string; sale_date: string; salesperson_name_snapshot: string | null; product_name_snapshot: string | null;
    entry_type: string; status: string; margin_amount_snapshot: string; margin_percent: string | null; commission_rate_snapshot: string; commission_amount: string;
    sales_order: { id: number; order_number: string }; customer_return?: { id: number; return_number: string } | null;
};
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
type Filters = { from: string; to: string; store_id: number | null; salesperson_id: number | null; status: string | null; entry_type: string | null; order: string | null };
type Props = {
    organization: { id: number; name: string }; entries: Page<Entry>; filters: Filters;
    stores: { id: number; name: string; code: string }[]; salespeople: { id: number; name: string }[];
    summary: { gross_earned: string; reversals: string; net: string; pending: string; approved: string; paid: string; entry_count: number };
    reconciliation: { counts: Record<string, number>; rows: { sales_order_id: number; order_number: string; sale_date: string; product: string; salesperson: string | null; reason: string }[] } | null;
    can: { approve: boolean; markPaid: boolean; reconcile: boolean };
};

export default function CommissionLedger({ organization, entries, filters, stores, salespeople, summary, reconciliation, can }: Props) {
    const { t } = useI18n();
    const { submit: submitProtected } = useFreshAuth();
    const { statuses, types } = useCommissionLabels();
    const reasons: Record<string, string> = {
        eligible_missing: t('commissions.reasonEligibleMissing'), missing_salesperson: t('commissions.reasonMissingSalesperson'), missing_cost: t('commissions.reasonMissingCost'),
        margin_unavailable: t('commissions.reasonMarginUnavailable'), no_rule_set: t('commissions.reasonNoRule'), no_matching_tier: t('commissions.reasonNoTier'),
        missing_return_reversal: t('commissions.reasonMissingReturn'), over_reversed_quantity: t('commissions.reasonOverReversed'),
    };
    const [local, setLocal] = useState({ ...filters, store_id: filters.store_id?.toString() ?? '', salesperson_id: filters.salesperson_id?.toString() ?? '', status: filters.status ?? '', entry_type: filters.entry_type ?? '', order: filters.order ?? '' });
    const [selected, setSelected] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const apply = () => router.get('/finance/commissions/ledger', local, { preserveState: true, replace: true });
    const post = (path: string, data: any) => {
        setProcessing(true);
        if (path.endsWith('/mark-paid') || path.endsWith('/reconcile')) {
            void submitProtected({ method: 'post', url: path, data, preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: () => setSelected([]) });
            return;
        }
        router.post(path, data, { preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: () => setSelected([]) });
    };
    // 0% tier entries are never approvable or payable, so they are never selectable.
    const selectable = entries.data.filter((entry) => entry.status !== 'no_commission');
    const allVisible = selectable.length > 0 && selectable.every((entry) => selected.includes(entry.id));
    const allSelectedHaveStatus = (status: string) => selected.length > 0 && selected.every((id) => entries.data.find((entry) => entry.id === id)?.status === status);

    return <ApplicationShell wide>
        <Head title={t('commissions.ledgerTitle')} />
        <PageHeader title={t('commissions.ledgerTitle')} description={`${organization.name} · ${t('commissions.ledgerHelp')}`} />
        <CommissionTabs active="ledger" />

        <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <Metric label={t('commissions.grossEarned')} value={summary.gross_earned} /><Metric label={t('commissions.reversalShort')} value={summary.reversals} negative />
            <Metric label={t('commissions.net')} value={summary.net} /><Metric label={t('commissions.pending')} value={summary.pending} />
            <Metric label={t('commissions.approved')} value={summary.approved} /><Metric label={t('commissions.paid')} value={summary.paid} />
        </section>

        <section className="mb-5 rounded-card border border-line bg-surface p-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-7">
                <Field label={t('activity.from')}><input type="date" className={input} value={local.from} onChange={(e) => setLocal({ ...local, from: e.target.value })} /></Field>
                <Field label={t('activity.to')}><input type="date" className={input} value={local.to} onChange={(e) => setLocal({ ...local, to: e.target.value })} /></Field>
                <Field label={t('commissions.salesperson')}><select className="control-select" value={local.salesperson_id} onChange={(e) => setLocal({ ...local, salesperson_id: e.target.value })}><option value="">{t('commissions.all')}</option>{salespeople.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                <Field label={t('commissions.store')}><select className="control-select" value={local.store_id} onChange={(e) => setLocal({ ...local, store_id: e.target.value })}><option value="">{t('commissions.all')}</option>{stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                <Field label={t('commissions.status')}><select className="control-select" value={local.status} onChange={(e) => setLocal({ ...local, status: e.target.value })}><option value="">{t('commissions.all')}</option>{Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>
                <Field label={t('commissions.type')}><select className="control-select" value={local.entry_type} onChange={(e) => setLocal({ ...local, entry_type: e.target.value })}><option value="">{t('commissions.all')}</option>{Object.entries(types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>
                <Field label={t('commissions.order')}><input className={input} value={local.order} placeholder="SO-…" onChange={(e) => setLocal({ ...local, order: e.target.value })} /></Field>
            </div><div className="mt-3 flex justify-end"><Button size="sm" variant="secondary" onClick={apply}>{t('commissions.apply')}</Button></div>
        </section>

        {(can.approve || can.markPaid) && <div className="mb-3 flex flex-wrap gap-2">
            {can.approve && <Button size="sm" disabled={!allSelectedHaveStatus('pending')} loading={processing} onClick={() => post('/finance/commissions/ledger/approve', { entry_ids: selected })}>{t('commissions.approveSelection')}</Button>}
            {can.markPaid && <Button size="sm" variant="secondary" disabled={!allSelectedHaveStatus('approved')} loading={processing} onClick={() => post('/finance/commissions/ledger/mark-paid', { entry_ids: selected })}>{t('commissions.markPaid')}</Button>}
        </div>}

        <section className="overflow-x-auto rounded-card border border-line bg-surface">
            <table className="erp-table w-full min-w-[1060px] text-start text-sm"><thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint"><tr>
                <th className="px-3 py-2"><input aria-label={t('commissions.selectAll')} type="checkbox" checked={allVisible} onChange={() => setSelected(allVisible ? selected.filter((id) => !selectable.some((entry) => entry.id === id)) : [...new Set([...selected, ...selectable.map((entry) => entry.id)])])} /></th>
                <th className="px-3 py-2">{t('common.date')}</th><th className="px-3 py-2">{t('commissions.salesperson')}</th><th className="px-3 py-2">{t('commissions.order')}</th><th className="px-3 py-2">{t('commissions.productLine')}</th><th className="px-3 py-2">{t('commissions.type')}</th><th className="px-3 py-2 text-end">{t('commissions.margin')}</th><th className="px-3 py-2 text-end">{t('commissions.marginRate')}</th><th className="px-3 py-2 text-end">{t('commissions.commissionRate')}</th><th className="px-3 py-2 text-end">{t('commissions.commission')}</th><th className="px-3 py-2">{t('commissions.status')}</th>
            </tr></thead><tbody>{entries.data.map((entry) => <tr key={entry.id} className="border-t border-line">
                <td className="px-3 py-2"><input aria-label={`${t('commissions.selectEntry')} ${entry.id}`} type="checkbox" checked={selected.includes(entry.id)} disabled={entry.status === 'no_commission'} onChange={() => setSelected(selected.includes(entry.id) ? selected.filter((id) => id !== entry.id) : [...selected, entry.id])} /></td>
                <td className="px-3 py-2 text-ink-muted">{formatDate(entry.occurred_at)}</td><td className="px-3 py-2">{entry.salesperson_name_snapshot ?? t('commissions.unattributed')}</td>
                <td className="px-3 py-2"><Link className="font-medium text-primary" href={`/sales/orders/${entry.sales_order.id}`}><bdi>{entry.sales_order.order_number}</bdi></Link></td>
                <td className="px-3 py-2"><Link href={`/finance/commissions/ledger/${entry.id}`} className="font-medium text-ink hover:text-primary">{entry.product_name_snapshot ?? `${t('commissions.line')} #${entry.id}`}</Link></td>
                <td className="px-3 py-2">{types[entry.entry_type] ?? entry.entry_type}</td><td className="px-3 py-2 text-end">{formatMoney(entry.margin_amount_snapshot)}</td><td className="px-3 py-2 text-end">{percent(entry.margin_percent)}</td><td className="px-3 py-2 text-end">{percent(entry.commission_rate_snapshot)}</td>
                <td className={`px-3 py-2 text-end font-semibold ${Number(entry.commission_amount) < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(entry.commission_amount)}</td><td className="px-3 py-2"><span className="rounded-full bg-raised px-2 py-1 text-xs font-medium text-ink-muted">{statuses[entry.status] ?? entry.status}</span></td>
            </tr>)}</tbody></table>
            {entries.data.length === 0 && <p className="p-8 text-center text-sm text-ink-faint">{t('commissions.emptyFilters')}</p>}
        </section><Pagination links={entries.links} />

        {can.reconcile && reconciliation && <section className="mt-6 rounded-card border border-line bg-surface p-5">
            <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold text-ink">{t('commissions.reconciliation')}</h2><p className="mt-1 text-xs text-ink-muted">{t('commissions.reconciliationHelp')}</p></div><Button variant="secondary" loading={processing} disabled={(reconciliation.counts.eligible_missing ?? 0) === 0} onClick={() => post('/finance/commissions/ledger/reconcile', { from: filters.from, to: filters.to, store_id: filters.store_id, salesperson_id: filters.salesperson_id })}>{t('commissions.generateMissing')}</Button></div>
            <div className="mt-4 flex flex-wrap gap-2">{Object.entries(reconciliation.counts).map(([reason, count]) => <span key={reason} className="rounded-full bg-raised px-3 py-1 text-xs text-ink-muted">{reasons[reason] ?? reason} : {count}</span>)}</div>
            {reconciliation.rows.length > 0 && <div className="mt-4 overflow-x-auto"><table className="erp-table w-full text-sm"><thead><tr className="border-b border-line text-start text-xs text-ink-muted"><th className="py-2">{t('commissions.order')}</th><th>{t('commissions.line')}</th><th>{t('commissions.salesperson')}</th><th>{t('commissions.groupReason')}</th></tr></thead><tbody>{reconciliation.rows.map((row, index) => <tr key={`${row.sales_order_id}-${index}`} className="border-b border-line"><td className="py-2"><Link href={`/sales/orders/${row.sales_order_id}`}><bdi>{row.order_number}</bdi></Link></td><td>{row.product}</td><td>{row.salesperson ?? '—'}</td><td>{reasons[row.reason] ?? row.reason}</td></tr>)}</tbody></table></div>}
        </section>}
    </ApplicationShell>;
}

function Metric({ label, value, negative = false }: { label: string; value: string; negative?: boolean }) { return <div className="rounded-card border border-line bg-surface p-4"><p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p><p className={`mt-1 text-lg font-semibold ${negative && Number(value) < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(value)}</p></div>; }
function Field({ label, children }: { label: string; children: ReactNode }) { return <label className="text-xs font-medium text-ink-muted">{label}<div className="mt-1">{children}</div></label>; }
const percent = (value: string | null) => value == null ? 'n/d' : `${Number(value).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`;
const input = 'w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none';
