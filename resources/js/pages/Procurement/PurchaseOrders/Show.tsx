import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Line = {
    id: number;
    article: string;
    reference: string | null;
    quantity: string;
    unit_price: string | null;
    sales_order: { id: number; order_number: string } | null;
    requirement: { number: string; status: string } | null;
};
type PurchaseOrder = {
    id: number;
    purchase_order_number: string;
    status: string;
    supplier: { id: number; name: string; phone: string | null; email: string | null } | null;
    store: { id: number; name: string } | null;
    currency_code: string;
    order_date: string | null;
    expected_delivery_date: string | null;
    notes: string | null;
    ordered_at: string | null;
    created_by: string | null;
    cancelled_at: string | null;
    cancelled_by: string | null;
    cancellation_reason: string | null;
    lines: Line[];
};
type Props = { purchaseOrder: PurchaseOrder; can: { cancel: boolean } };

export default function PurchaseOrderShow({ purchaseOrder: po, can }: Props) {
    const { t } = useI18n();
    const [cancelling, setCancelling] = useState(false);
    const form = useForm({ reason: '' });

    const fact = (label: string, value: string | null | undefined) => (
        <div className="min-w-0">
            <dt className="text-xs text-ink-faint">{label}</dt>
            <dd className="truncate text-sm text-ink">{value || '—'}</dd>
        </div>
    );

    return (
        <ProcurementLayout>
            <Head title={po.purchase_order_number} />
            <div className="mb-3 text-sm">
                <Link href="/procurement/purchase-orders" className="text-ink-muted hover:text-ink">
                    ← {t('purchaseOrders.title')}
                </Link>
            </div>
            <PageHeader
                title={po.purchase_order_number}
                description={po.supplier?.name ?? undefined}
                actions={<StatusBadge status={po.status}>{t(`purchaseOrders.status.${po.status}` as TranslationKey)}</StatusBadge>}
            />

            <section className="mb-6 rounded-card border border-line bg-surface p-5">
                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {fact(t('purchaseOrders.supplier'), po.supplier?.name)}
                    {fact(t('purchaseOrders.store'), po.store?.name)}
                    {fact(t('purchaseOrders.orderDate'), po.order_date ? formatDate(po.order_date) : null)}
                    {fact(t('purchaseOrders.expectedDelivery'), po.expected_delivery_date ? formatDate(po.expected_delivery_date) : null)}
                    {fact(t('purchaseOrders.createdBy'), po.created_by ? `${po.created_by}${po.ordered_at ? ` · ${formatDateTime(po.ordered_at)}` : ''}` : null)}
                    {po.supplier?.phone ? fact('Tél.', po.supplier.phone) : null}
                    {po.supplier?.email ? fact('E-mail', po.supplier.email) : null}
                </dl>
                {po.notes && (
                    <p className="mt-4 whitespace-pre-line rounded-field bg-raised px-3 py-2 text-sm text-ink-muted">
                        <span className="font-medium text-ink">{t('purchaseOrders.notes')} : </span>
                        {po.notes}
                    </p>
                )}
                {po.status === 'cancelled' && (
                    <p className="mt-4 rounded-field bg-danger-soft px-3 py-2 text-sm text-danger">
                        {t('purchaseOrders.cancelledOn')} {po.cancelled_at ? formatDateTime(po.cancelled_at) : '—'}
                        {po.cancelled_by ? ` · ${po.cancelled_by}` : ''}
                        {po.cancellation_reason ? ` — ${po.cancellation_reason}` : ''}
                    </p>
                )}
            </section>

            <section className="mb-6 rounded-card border border-line bg-surface">
                <h2 className="border-b border-line px-5 py-3 text-xs font-semibold uppercase tracking-wide text-ink-muted">{t('purchaseOrders.lines')}</h2>
                <ul className="divide-y divide-line">
                    {po.lines.map((line) => (
                        <li key={line.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm">
                            <div className="min-w-0 flex-1">
                                <p className="font-medium text-ink">{line.article || '—'}</p>
                                <p className="text-xs text-ink-muted">
                                    {line.reference ? <bdi>{line.reference}</bdi> : null}
                                    {line.sales_order && (
                                        <>
                                            {line.reference ? ' · ' : ''}
                                            {t('purchaseOrders.salesOrder')}{' '}
                                            <Link href={`/sales/orders/${line.sales_order.id}`} className="underline">
                                                <bdi>{line.sales_order.order_number}</bdi>
                                            </Link>
                                        </>
                                    )}
                                    {line.requirement ? (
                                        <>
                                            {' · '}
                                            {t('purchaseOrders.requirement')} <bdi>{line.requirement.number}</bdi>
                                        </>
                                    ) : null}
                                </p>
                            </div>
                            <div className="text-end">
                                <p className="font-semibold tabular-nums text-ink">× {formatQuantity(line.quantity)}</p>
                                {line.unit_price !== null && (
                                    <p className="text-xs text-ink-muted">
                                        {t('purchaseOrders.unitPrice')} {formatMoney(line.unit_price, po.currency_code)}
                                    </p>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
                <p className="border-t border-line px-5 py-3 text-xs text-ink-faint">{t('purchaseOrders.receiptNote')}</p>
            </section>

            {can.cancel &&
                (cancelling ? (
                    <form
                        className="rounded-card border border-line bg-surface p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/procurement/purchase-orders/${po.id}/cancel`, { preserveScroll: true, onSuccess: () => setCancelling(false) });
                        }}
                    >
                        <label className="block text-sm font-medium text-ink" htmlFor="po-cancel-reason">
                            {t('purchaseOrders.cancelReason')}
                        </label>
                        <textarea
                            id="po-cancel-reason"
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            rows={2}
                            required
                            className="control-input mt-1 w-full"
                        />
                        {form.errors.reason && <p className="mt-1 text-xs text-danger">{form.errors.reason}</p>}
                        {(form.errors as Record<string, string>).purchase_order && (
                            <p className="mt-1 text-xs text-danger">{(form.errors as Record<string, string>).purchase_order}</p>
                        )}
                        <p className="mt-2 text-xs text-ink-muted">{t('purchaseOrders.cancelHint')}</p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <button type="submit" disabled={form.processing} className="rounded-field bg-danger px-3 py-1.5 text-sm font-medium text-white disabled:opacity-60">
                                {t('purchaseOrders.confirmCancel')}
                            </button>
                            <button type="button" onClick={() => setCancelling(false)} className="rounded-field border border-line px-3 py-1.5 text-sm text-ink">
                                {t('common.cancel')}
                            </button>
                        </div>
                    </form>
                ) : (
                    <button type="button" onClick={() => setCancelling(true)} className="rounded-field border border-line px-3 py-1.5 text-sm text-danger hover:bg-raised">
                        {t('purchaseOrders.cancel')}
                    </button>
                ))}
        </ProcurementLayout>
    );
}
