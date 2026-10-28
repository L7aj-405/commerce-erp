import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { formatDate, formatQuantity } from '@/utils/format';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type PlanLine = {
    line_id: number;
    article: string;
    state: 'covered_by_stock' | 'procurement_required' | 'procurement_ordered' | 'procurement_partially_received' | 'procurement_received' | 'unresolved' | 'not_applicable';
    procurement_quantity: string;
    ordered_quantity: string;
    procurement_ordered: string;
    procurement_received: string;
    procurement_remaining: string;
    remaining_to_order: string;
    unresolved_reason: 'no_supplier_confirmation' | 'supplier_not_confirmed' | 'supplier_unavailable' | null;
    supplier: { id: number; name: string } | null;
    availability: { status: string; source: 'quotation' | 'manual' | 'procurement' } | null;
    purchase_orders: {
        id: number;
        number: string | null;
        status: string | null;
        quantity: string;
        received_quantity: string;
        remaining_to_receive: string;
        expected_delivery_date: string | null;
    }[];
    source_quotation: { id: number; number: string | null } | null;
    derivable: boolean;
};
export type ProcurementPlan = {
    summary: {
        state: 'covered' | 'awaiting_procurement' | 'unresolved' | 'ordered' | 'partially_received' | 'received' | 'not_applicable';
        ready_for_ordering: boolean;
        unresolved_count: number;
        to_order_line_count: number;
        pending_derivation_count: number;
        confirmation_waits_for_receipt: boolean;
        confirmation_ready: boolean;
        suppliers: { supplier: { id: number; name: string }; requirement_ids: number[]; line_count: number }[];
    };
    lines: PlanLine[];
    can: { createPurchaseOrder: boolean; confirm: boolean };
};

const fill = (text: string, params: Record<string, string | number>) =>
    Object.entries(params).reduce((out, [key, value]) => out.split(`{${key}}`).join(String(value)), text);

const STATE_CLASS: Record<string, string> = {
    covered_by_stock: 'bg-success-soft text-success',
    procurement_required: 'bg-warning-soft text-warning',
    procurement_ordered: 'bg-primary/10 text-primary',
    procurement_partially_received: 'bg-warning-soft text-warning',
    procurement_received: 'bg-success-soft text-success',
    unresolved: 'bg-danger-soft text-danger',
};

/**
 * "Approvisionnement": what the order still needs from suppliers, kept apart from
 * its commercial status. Creating a purchase order is always an explicit click.
 */
export default function ProcurementReadinessCard({ orderId, plan }: { orderId: number; plan: ProcurementPlan }) {
    const { t } = useI18n();
    const k = (key: string) => t(`orders.show.procurementCard.${key}` as TranslationKey);
    const { summary } = plan;
    const relevant = plan.lines.filter((line) => line.state !== 'not_applicable');
    if (summary.state === 'not_applicable' || relevant.length === 0) return null;

    if (summary.state === 'covered') {
        return (
            <section className="mb-6 rounded-card border border-line bg-surface p-5">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{k('title')}</h2>
                <p className="mt-2 text-sm text-success">{k('covered')}</p>
            </section>
        );
    }

    const procured = relevant.filter((line) => line.state !== 'covered_by_stock');
    const headline =
        summary.state === 'awaiting_procurement'
            ? fill(k('required'), { count: summary.to_order_line_count })
            : summary.state === 'unresolved'
              ? fill(k('unresolved'), { count: summary.unresolved_count })
              : summary.state === 'ordered'
                ? k('ordered')
                : summary.state === 'partially_received'
                  ? k('partiallyReceived')
                  : k('fullyReceived');
    const settled = summary.state === 'ordered' || summary.state === 'partially_received' || summary.state === 'received';
    const tone = settled ? 'border-line' : 'border-warning/40';
    const purchaseOrders = [...new Map(procured.flatMap((line) => line.purchase_orders).map((po) => [po.id, po])).values()];

    return (
        <section className={`mb-6 rounded-card border bg-surface p-5 ${tone}`}>
            <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{k('title')}</h2>
            <p className={`mt-2 text-sm font-medium ${summary.state === 'received' ? 'text-success' : settled ? 'text-ink' : 'text-warning'}`}>{headline}</p>

            <ul className="mt-3 space-y-2">
                {procured.map((line) => (
                    <li key={line.line_id} className="rounded-field border border-line bg-raised p-3 text-sm">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <span className="min-w-0 font-medium text-ink">
                                {line.state === 'procurement_required'
                                    ? fill(k('toOrder'), { quantity: formatQuantity(line.remaining_to_order), article: line.article })
                                    : `${formatQuantity(line.procurement_quantity)} × ${line.article}`}
                            </span>
                            <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${STATE_CLASS[line.state] ?? ''}`}>{k(`state.${line.state}`)}</span>
                        </div>
                        <p className="mt-1 text-xs text-ink-muted">
                            {supplierText(line, k)}
                            {line.source_quotation?.number ? ` · ${fill(k('fromQuotation'), { number: line.source_quotation.number })}` : ''}
                        </p>
                        {Number(line.ordered_quantity) > 0 && line.state === 'procurement_required' && (
                            <p className="mt-1 text-xs text-ink-muted">
                                {fill(k('orderedQty'), { ordered: formatQuantity(line.ordered_quantity), required: formatQuantity(line.procurement_quantity) })}
                            </p>
                        )}
                        {['procurement_ordered', 'procurement_partially_received', 'procurement_received'].includes(line.state) && (
                            <p className="mt-1 text-xs font-medium tabular-nums text-ink">
                                {fill(k('receiptFigures'), {
                                    ordered: formatQuantity(line.procurement_ordered),
                                    received: formatQuantity(line.procurement_received),
                                    remaining: formatQuantity(line.procurement_remaining),
                                })}
                            </p>
                        )}
                        {line.derivable && <p className="mt-1 text-xs text-ink-faint">{k('derivedAtConfirmation')}</p>}
                    </li>
                ))}
            </ul>

            {purchaseOrders.length > 0 && (
                <ul className="mt-3 space-y-1.5">
                    {purchaseOrders.map((po) => (
                        <li key={po.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span className="text-ink">
                                <bdi className="font-medium">{po.number}</bdi>
                                {po.status ? <span className="text-ink-muted"> · {t(`purchaseOrders.status.${po.status}` as TranslationKey)}</span> : null}
                                {po.expected_delivery_date && Number(po.remaining_to_receive) > 0 ? (
                                    <span className="text-ink-muted"> · {fill(k('expected'), { date: formatDate(po.expected_delivery_date) })}</span>
                                ) : null}
                            </span>
                            <Link href={`/procurement/purchase-orders/${po.id}`} className="text-xs font-medium text-primary underline">
                                {k('viewPo')}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {summary.to_order_line_count > 0 && (
                <div className="mt-4 space-y-2">
                    {!plan.can.createPurchaseOrder ? (
                        <p className="text-xs text-ink-faint">{k('noPermission')}</p>
                    ) : (
                        summary.suppliers.map((group) => (
                            <CreatePurchaseOrder key={group.supplier.id} orderId={orderId} group={group} showSupplier={summary.suppliers.length > 1} k={k} />
                        ))
                    )}
                </div>
            )}

            {summary.confirmation_waits_for_receipt && <p className="mt-3 text-xs text-ink-muted">{k('waitsForReceipt')}</p>}

            {summary.confirmation_ready && summary.state === 'received' && (
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <p className="text-sm text-success">{k('readyToConfirm')}</p>
                    {plan.can.confirm && (
                        // Never automatic: the user confirms the order explicitly.
                        <button
                            type="button"
                            onClick={() => router.post(`/sales/orders/${orderId}/confirm`, {}, { preserveScroll: true })}
                            className="rounded-field bg-primary px-3 py-1.5 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                        >
                            {k('confirmOrder')}
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}

function supplierText(line: PlanLine, k: (key: string) => string): string {
    if (line.state === 'unresolved') {
        if (line.unresolved_reason === 'supplier_not_confirmed') return `${line.supplier?.name ?? ''} — ${k('supplierPending')}`;
        if (line.unresolved_reason === 'supplier_unavailable') return k('supplierUnavailable');
        return k('supplierToDefine');
    }
    if (!line.supplier) return k('supplierToDefine');
    if (line.availability?.source === 'manual') return fill(k('confirmedManually'), { supplier: line.supplier.name });
    if (line.availability?.status === 'confirmed_available') return fill(k('confirmedBy'), { supplier: line.supplier.name });

    return line.supplier.name;
}

function CreatePurchaseOrder({
    orderId,
    group,
    showSupplier,
    k,
}: {
    orderId: number;
    group: ProcurementPlan['summary']['suppliers'][number];
    showSupplier: boolean;
    k: (key: string) => string;
}) {
    const [open, setOpen] = useState(false);
    // One id per opened form: a double submit returns the same purchase order. The
    // server orders every open requirement of this supplier on the order.
    const form = useForm({
        supplier_id: group.supplier.id,
        expected_delivery_date: '',
        notes: '',
        client_operation_id: crypto.randomUUID(),
    });
    const label = showSupplier ? `${k('createPo')} — ${group.supplier.name}` : k('createPo');

    if (!open) {
        return (
            <button type="button" onClick={() => setOpen(true)} className="rounded-field bg-primary px-3 py-1.5 text-sm font-medium text-primary-fg hover:bg-primary-hover">
                {label}
            </button>
        );
    }

    const errors = form.errors as Record<string, string>;

    return (
        <form
            className="rounded-field border border-line p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/sales/orders/${orderId}/purchase-orders`, { preserveScroll: true });
            }}
        >
            <p className="text-sm font-medium text-ink">{label}</p>
            <p className="mt-1 text-xs text-ink-muted">{fill(k('commitmentHint'), { count: group.line_count, supplier: group.supplier.name })}</p>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <label className="block text-xs text-ink-muted">
                    {k('expectedDelivery')}
                    <input type="date" value={form.data.expected_delivery_date} onChange={(e) => form.setData('expected_delivery_date', e.target.value)} className="control-input mt-1 w-full" />
                </label>
                <label className="block text-xs text-ink-muted">
                    {k('notes')}
                    <input type="text" maxLength={2000} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className="control-input mt-1 w-full" />
                </label>
            </div>
            {Object.values(errors).length > 0 && <p className="mt-2 text-xs text-danger">{Object.values(errors)[0]}</p>}
            <div className="mt-3 flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className="rounded-field bg-primary px-3 py-1.5 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-60">
                    {k('submit')}
                </button>
                <button type="button" onClick={() => setOpen(false)} className="rounded-field border border-line px-3 py-1.5 text-sm text-ink">
                    ×
                </button>
            </div>
        </form>
    );
}
