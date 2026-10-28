import AttentionList from '@/components/attention/AttentionList';
import type { AttentionItem, AttentionOwner } from '@/components/attention/types';
import WorkNav from '@/components/followups/WorkNav';
import type { FollowUpContext } from '@/components/followups/types';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, Link, router } from '@inertiajs/react';

type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    filters: { view?: string; type?: string; severity?: string; customer_id?: number; quotation_id?: number; focus?: number; assigned?: string; escalated?: string | boolean; domain?: string };
    items: { data: AttentionItem[]; links: LinkData[]; total: number };
    context: FollowUpContext;
    /** Managers only: valid owners with the stores they can see. */
    owners: AttentionOwner[];
};

const fieldClass = 'h-10 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary';

/** W4.1 Attention: detected quotation situations; every action is a human decision. */
export default function WorkAttention({ filters, items, context, owners }: Props) {
    const { t } = useI18n();
    const visit = (next: Record<string, unknown>) => router.get('/work/attention', { ...filters, ...next, focus: undefined, page: undefined }, { preserveState: true, replace: true });

    const views = [['open', t('attention.viewOpen')], ['snoozed', t('attention.viewSnoozed')], ['dismissed', t('attention.viewDismissed')], ['resolved', t('attention.viewResolved')], ['all', t('attention.viewAll')]] as const;

    return (
        <ApplicationShell>
            <Head title={t('attention.title')} />
            <PageHeader title={t('attention.title')} description={t('attention.description')} />
            <WorkNav current="attention" canManageAll={context.can_manage_all} />

            <nav className="mb-4 flex gap-1 overflow-x-auto border-b border-line" aria-label={t('attention.title')}>
                {views.map(([key, label]) => (
                    <button key={key} type="button" onClick={() => visit({ view: key })} aria-current={(filters.view ?? 'open') === key ? 'page' : undefined}
                        className={`-mb-px shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-soft ${(filters.view ?? 'open') === key ? 'border-primary text-ink' : 'border-transparent text-ink-muted hover:text-ink'}`}>
                        {label}
                    </button>
                ))}
            </nav>

            {filters.focus && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-field border border-line bg-raised px-3 py-2 text-sm text-ink-muted">
                    <span>{t('attention.focusTitle')}</span>
                    <Link href="/work/attention" className="font-medium text-primary hover:underline">{t('attention.focusShowAll')}</Link>
                </div>
            )}

            <div className="mb-4 flex flex-wrap gap-3 rounded-card border border-line bg-raised p-4">
                <select value={filters.type ?? ''} onChange={(e) => visit({ type: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('attention.allTypes')}>
                    <option value="">{t('attention.allTypes')}</option>
                    <option value="quotation_sent_undecided">{t('attention.typeSent')}</option>
                    <option value="quotation_expiring_soon">{t('attention.typeExpiring')}</option>
                    <option value="quotation_expired_undecided">{t('attention.typeExpired')}</option>
                    <option value="quotation_followup_backlog">{t('attention.typeBacklog')}</option>
                    <option value="sales_order_payment_due_soon">{t('attention.typeOrderDueSoon')}</option>
                    <option value="sales_order_unpaid_overdue">{t('attention.typeOrderOverdue')}</option>
                    <option value="sales_order_partial_overdue">{t('attention.typeOrderPartial')}</option>
                    <option value="sales_order_payment_followup_backlog">{t('attention.typeOrderBacklog')}</option>
                    <option value="sales_order_paid_not_invoiced">{t('attention.typeOrderPaidNotInvoiced')}</option>
                    <option value="sales_order_procurement_required">{t('attention.typeOrderProcurementRequired')}</option>
                    <option value="sales_order_procurement_unresolved">{t('attention.typeOrderProcurementUnresolved')}</option>
                    <option value="invoice_due_soon">{t('attention.typeInvoiceDueSoon')}</option>
                    <option value="invoice_overdue_unpaid">{t('attention.typeInvoiceOverdue')}</option>
                    <option value="invoice_overdue_partial">{t('attention.typeInvoicePartial')}</option>
                    <option value="invoice_payment_followup_backlog">{t('attention.typeInvoiceBacklog')}</option>
                </select>
                <select value={filters.domain ?? ''} onChange={(e) => visit({ domain: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('attention.domain')}>
                    <option value="">{t('attention.domainAll')}</option>
                    <option value="quotation">{t('attention.domainQuotations')}</option>
                    <option value="sales_order">{t('attention.domainOrders')}</option>
                    <option value="invoice">{t('attention.domainInvoices')}</option>
                </select>
                <select value={filters.assigned ?? ''} onChange={(e) => visit({ assigned: e.target.value })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('attention.owner')}>
                    <option value="mine_or_unassigned">{t('attention.ownerMineOrUnassigned')}</option>
                    <option value="mine">{t('attention.ownerMine')}</option>
                    <option value="unassigned">{t('attention.ownerUnassigned')}</option>
                    {context.can_manage_all && <option value="all">{t('attention.ownerTeam')}</option>}
                    {context.can_manage_all && owners.filter((owner) => owner.id !== context.current_user_id).map((owner) => <option key={owner.id} value={String(owner.id)}>{owner.name}</option>)}
                </select>
                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    <input type="checkbox" checked={Boolean(filters.escalated) && filters.escalated !== '0'} onChange={(e) => visit({ escalated: e.target.checked ? 1 : undefined })} />
                    {t('attention.escalatedOnly')}
                </label>
                <select value={filters.severity ?? ''} onChange={(e) => visit({ severity: e.target.value || undefined })} className={`${fieldClass} flex-1 sm:flex-none`} aria-label={t('attention.allSeverities')}>
                    <option value="">{t('attention.allSeverities')}</option>
                    <option value="critical">{t('attention.severityCritical')}</option>
                    <option value="warning">{t('attention.severityWarning')}</option>
                </select>
            </div>

            {items.data.length === 0 ? (
                <EmptyState title={filters.focus ? t('attention.focusUnavailable') : t('attention.empty')} description={t('attention.emptyHelp')} />
            ) : (
                <AttentionList items={items.data} context={context} owners={owners} focusId={filters.focus ? Number(filters.focus) : undefined} />
            )}
            <Pagination links={items.links} />
        </ApplicationShell>
    );
}
