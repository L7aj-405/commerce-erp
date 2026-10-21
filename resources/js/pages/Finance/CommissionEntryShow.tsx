import { Button, ButtonLink } from '@/components/ui/Button';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Entry = any;
export default function CommissionEntryShow({ entry, can }: { entry: Entry; can: { approve: boolean; markPaid: boolean } }) {
    const { t } = useI18n();
    const [processing, setProcessing] = useState(false);
    const action = (path: string) => { setProcessing(true); router.post(path, { entry_ids: [entry.id] }, { preserveScroll: true, onFinish: () => setProcessing(false) }); };
    return <ApplicationShell>
        <Head title={`${t('commissions.commission')} #${entry.id}`} />
        <PageHeader title={<>{t('commissions.entryTitle')} <bdi>#{entry.id}</bdi></>} description={`${entry.salesperson_name_snapshot ?? t('commissions.unattributedSalesperson')} · ${formatDateTime(entry.occurred_at)}`} actions={<><ButtonLink href="/finance/commissions/ledger" variant="secondary">{t('commissions.backLedger')}</ButtonLink>{entry.status === 'pending' && can.approve && <Button loading={processing} onClick={() => action('/finance/commissions/ledger/approve')}>{t('commissions.approve')}</Button>}{entry.status === 'approved' && can.markPaid && <Button loading={processing} onClick={() => action('/finance/commissions/ledger/mark-paid')}>{t('commissions.markPaid')}</Button>}</>} />
        <div className="grid gap-5 lg:grid-cols-2">
            <Card title={t('commissions.historicalCalculation')}><Row label={t('commissions.revenue')} value={formatMoney(entry.revenue_ht_snapshot)} /><Row label={t('commissions.unitPurchaseCost')} value={entry.purchase_cost_snapshot === null ? t('common.unavailable') : formatMoney(entry.purchase_cost_snapshot)} /><Row label={t('commissions.totalCost')} value={formatMoney(entry.cost_total_snapshot)} /><Row label={t('commissions.margin')} value={formatMoney(entry.margin_amount_snapshot)} /><Row label={`${t('commissions.margin')} %`} value={`${entry.margin_rate_snapshot}%`} /><Row label={`${t('commissions.commission')} %`} value={`${entry.commission_rate_snapshot}%`} strong /><Row label={t('commissions.commission')} value={formatMoney(entry.commission_amount)} strong /></Card>
            <Card title={t('commissions.ruleOrigin')}><Row label={t('commissions.rule')} value={entry.commission_rule_set_name_snapshot} /><Row label={t('commissions.tier')} value={`${entry.rule_min_margin_snapshot ?? '−∞'} ≤ ${t('commissions.margin').toLocaleLowerCase()} < ${entry.rule_max_margin_snapshot ?? '+∞'}`} /><Row label={t('commissions.saleDate')} value={formatDate(entry.sale_date)} /><Row label={t('commissions.order')} value={entry.sales_order?.order_number ?? `#${entry.sales_order_id}`} /><Row label={t('commissions.productLine')} value={entry.product_name_snapshot ?? '—'} /><Row label={t('commissions.reference')} value={entry.line_reference_snapshot ?? '—'} /><Row label={t('commissions.type')} value={entry.entry_type} /><Row label={t('commissions.status')} value={entry.status} /></Card>
            {entry.source_entry && <Card title={t('commissions.sourceEntry')}><Row label={t('commissions.entry')} value={`#${entry.source_entry.id}`} /><Row label={t('commissions.originalCommission')} value={formatMoney(entry.source_entry.commission_amount)} /><Row label={t('commissions.returnedQuantity')} value={String(entry.quantity_snapshot).replace(/^-/, '')} /><Row label={t('commissions.sourceReturn')} value={entry.customer_return?.return_number ?? '—'} /><Row label={t('commissions.reason')} value={entry.reversal_reason ?? '—'} /></Card>}
            <Card title={t('commissions.processingCycle')}><Row label={t('commissions.approvedBy')} value={entry.approved_by?.name ?? '—'} /><Row label={t('commissions.approvedOn')} value={entry.approved_at ? formatDateTime(entry.approved_at) : '—'} /><Row label={t('commissions.paidBy')} value={entry.paid_by?.name ?? '—'} /><Row label={t('commissions.paidOn')} value={entry.paid_at ? formatDateTime(entry.paid_at) : '—'} /></Card>
        </div>
    </ApplicationShell>;
}
function Card({ title, children }: { title: string; children: ReactNode }) { return <section className="rounded-card border border-line bg-surface p-5"><h2 className="mb-4 font-semibold text-ink">{title}</h2><dl>{children}</dl></section>; }
function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) { return <div className="flex items-start justify-between gap-5 border-b border-line py-2.5 last:border-0"><dt className="text-sm text-ink-muted">{label}</dt><dd dir="auto" className={`text-end text-sm ${strong ? 'font-semibold text-ink' : 'text-ink'}`}>{value}</dd></div>; }
