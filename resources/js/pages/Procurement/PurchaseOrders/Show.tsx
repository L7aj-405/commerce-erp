import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Receipt = {
    id: number;
    quantity: string;
    received_on: string | null;
    warehouse: { id: number; name: string; code: string | null } | null;
    supplier_delivery_reference: string | null;
    notes: string | null;
    received_by: string | null;
};
type Line = {
    id: number;
    article: string;
    reference: string | null;
    quantity: string;
    received_quantity: string;
    remaining_quantity: string;
    unit_price: string | null;
    sales_order: { id: number; order_number: string } | null;
    sales_order_line: { id: number; quantity: string } | null;
    requirement: { id: number; number: string; status: string; received_quantity: string } | null;
    source_quotation: { id: number; quotation_number: string | null } | null;
    supplier_evidence: { supplier: string | null; status: string; confirmed_quantity: string | null; confirmed_at: string | null } | null;
    receipts: Receipt[];
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
type Warehouse = { id: number; name: string; code: string | null };
type Props = { purchaseOrder: PurchaseOrder; warehouses: Warehouse[]; today: string; can: { cancel: boolean; receive: boolean } };

const fill = (text: string, params: Record<string, string | number>) =>
    Object.entries(params).reduce((out, [key, value]) => out.split(`{${key}}`).join(String(value)), text);

export default function PurchaseOrderShow({ purchaseOrder: po, warehouses, today, can }: Props) {
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
                        <PurchaseOrderLineItem key={line.id} po={po} line={line} warehouses={warehouses} today={today} canReceive={can.receive} />
                    ))}
                </ul>
                <p className="border-t border-line px-5 py-3 text-xs text-ink-faint">{t('purchaseOrders.receiveHint')}</p>
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

function PurchaseOrderLineItem({ po, line, warehouses, today, canReceive }: { po: PurchaseOrder; line: Line; warehouses: Warehouse[]; today: string; canReceive: boolean }) {
    const { t } = useI18n();
    const [receiving, setReceiving] = useState(false);
    const open = Number(line.remaining_quantity) > 0;

    return (
        <li className="px-5 py-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-ink">{line.article || '—'}</p>
                    <p className="mt-0.5 text-xs text-ink-muted">
                        {line.reference ? <bdi>{line.reference}</bdi> : null}
                        {line.sales_order && (
                            <>
                                {line.reference ? ' · ' : ''}
                                {t('purchaseOrders.salesOrder')}{' '}
                                <Link href={`/sales/orders/${line.sales_order.id}`} className="underline">
                                    <bdi>{line.sales_order.order_number}</bdi>
                                </Link>
                                {line.sales_order_line ? ` (${t('purchaseOrders.salesOrderLine')} #${line.sales_order_line.id})` : ''}
                            </>
                        )}
                        {line.requirement ? (
                            <>
                                {' · '}
                                {t('purchaseOrders.requirement')} <bdi>{line.requirement.number}</bdi>
                            </>
                        ) : null}
                        {line.source_quotation ? (
                            <>
                                {' · '}
                                {t('purchaseOrders.sourceQuotation')}{' '}
                                <Link href={`/quotations/${line.source_quotation.id}`} className="underline">
                                    <bdi>{line.source_quotation.quotation_number}</bdi>
                                </Link>
                            </>
                        ) : null}
                    </p>
                    {line.supplier_evidence && (
                        <p className="mt-0.5 text-xs text-ink-faint">
                            {fill(t('purchaseOrders.supplierEvidence'), {
                                status: line.supplier_evidence.status,
                                supplier: line.supplier_evidence.supplier ?? '—',
                                quantity: line.supplier_evidence.confirmed_quantity ? formatQuantity(line.supplier_evidence.confirmed_quantity) : '—',
                            })}
                        </p>
                    )}
                    {line.unit_price !== null && (
                        <p className="mt-0.5 text-xs text-ink-muted">
                            {t('purchaseOrders.unitPrice')} {formatMoney(line.unit_price, po.currency_code)}
                        </p>
                    )}
                </div>
                <dl className="grid grid-cols-3 gap-3 text-end tabular-nums">
                    <div>
                        <dt className="text-xs text-ink-faint">{t('purchaseOrders.ordered')}</dt>
                        <dd className="font-semibold text-ink">{formatQuantity(line.quantity)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-ink-faint">{t('purchaseOrders.received')}</dt>
                        <dd className="font-semibold text-ink">{formatQuantity(line.received_quantity)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-ink-faint">{t('purchaseOrders.remaining')}</dt>
                        <dd className={`font-semibold ${open ? 'text-warning' : 'text-success'}`}>{formatQuantity(line.remaining_quantity)}</dd>
                    </div>
                </dl>
            </div>

            {line.receipts.length > 0 && (
                <div className="mt-3">
                    <p className="text-xs font-medium text-ink-muted">{t('purchaseOrders.receipts')}</p>
                    <ul className="mt-1 space-y-1 text-xs text-ink-muted">
                        {line.receipts.map((receipt) => (
                            <li key={receipt.id}>
                                {receipt.received_on ? formatDate(receipt.received_on) : '—'} · {formatQuantity(receipt.quantity)} · {receipt.warehouse?.name ?? '—'}
                                {receipt.supplier_delivery_reference ? <> · <bdi>{receipt.supplier_delivery_reference}</bdi></> : null}
                                {receipt.received_by ? ` · ${receipt.received_by}` : ''}
                                {receipt.notes ? ` — ${receipt.notes}` : ''}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {canReceive && open && line.requirement && (
                <div className="mt-3">
                    {receiving ? (
                        <ReceiveForm po={po} line={line} warehouses={warehouses} today={today} onDone={() => setReceiving(false)} />
                    ) : (
                        <button type="button" onClick={() => setReceiving(true)} className="rounded-field bg-primary px-3 py-1.5 text-sm font-medium text-primary-fg hover:bg-primary-hover">
                            {t('purchaseOrders.receive')}
                        </button>
                    )}
                </div>
            )}
        </li>
    );
}

function ReceiveForm({ po, line, warehouses, today, onDone }: { po: PurchaseOrder; line: Line; warehouses: Warehouse[]; today: string; onDone: () => void }) {
    const { t } = useI18n();
    // One operation id per opened form (a double submit records one receipt) and the
    // figures it was prepared on (a receipt recorded meanwhile is rejected as stale).
    const form = useForm({
        quantity: String(Number(line.remaining_quantity)),
        warehouse_id: warehouses.length === 1 ? String(warehouses[0].id) : '',
        received_on: today,
        supplier_delivery_reference: '',
        notes: '',
        client_operation_id: crypto.randomUUID(),
        expected_received_quantity: line.requirement?.received_quantity ?? '0',
    });
    const errors = form.errors as Record<string, string>;

    return (
        <form
            className="rounded-field border border-line p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/procurement/purchase-orders/${po.id}/lines/${line.id}/receive`, { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <p className="text-sm font-medium text-ink">{t('purchaseOrders.receiveTitle')}</p>
            <div className="mt-3 grid gap-3 sm:grid-cols-3">
                <label className="block text-xs text-ink-muted">
                    {t('purchaseOrders.receiveQuantity')}
                    <input
                        type="number"
                        min="0"
                        step="any"
                        max={line.remaining_quantity}
                        required
                        value={form.data.quantity}
                        onChange={(e) => form.setData('quantity', e.target.value)}
                        className="control-input mt-1 w-full"
                    />
                </label>
                <label className="block text-xs text-ink-muted">
                    {t('purchaseOrders.receiveWarehouse')}
                    <select required value={form.data.warehouse_id} onChange={(e) => form.setData('warehouse_id', e.target.value)} className="control-select mt-1 w-full">
                        <option value="">{t('purchaseOrders.chooseWarehouse')}</option>
                        {warehouses.map((warehouse) => (
                            <option key={warehouse.id} value={warehouse.id}>
                                {warehouse.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block text-xs text-ink-muted">
                    {t('purchaseOrders.receiveDate')}
                    <input type="date" max={today} required value={form.data.received_on} onChange={(e) => form.setData('received_on', e.target.value)} className="control-input mt-1 w-full" />
                </label>
                <label className="block text-xs text-ink-muted sm:col-span-2">
                    {t('purchaseOrders.deliveryReference')}
                    <input
                        type="text"
                        maxLength={255}
                        value={form.data.supplier_delivery_reference}
                        onChange={(e) => form.setData('supplier_delivery_reference', e.target.value)}
                        className="control-input mt-1 w-full"
                    />
                </label>
                <label className="block text-xs text-ink-muted">
                    {t('purchaseOrders.receiveNote')}
                    <input type="text" maxLength={2000} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className="control-input mt-1 w-full" />
                </label>
            </div>
            {Object.values(errors).length > 0 && <p className="mt-2 text-xs text-danger">{Object.values(errors)[0]}</p>}
            <p className="mt-2 text-xs text-ink-muted">{t('purchaseOrders.receiveHint')}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className="rounded-field bg-primary px-3 py-1.5 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-60">
                    {t('purchaseOrders.receiveSubmit')}
                </button>
                <button type="button" onClick={onDone} className="rounded-field border border-line px-3 py-1.5 text-sm text-ink">
                    {t('common.cancel')}
                </button>
            </div>
        </form>
    );
}
