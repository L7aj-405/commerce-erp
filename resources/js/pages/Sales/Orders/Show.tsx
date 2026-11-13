import { Dialog } from '@/components/ui/Dialog';
import DocBadge from '@/components/ui/DocBadge';
import SandboxPaymentBadge from '@/components/SandboxPaymentBadge';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Popover } from '@/components/pos/primitives';
import ActivityTimeline from '@/components/timeline/ActivityTimeline';
import SalesLayout from '@/layouts/SalesLayout';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import FulfillmentCard, { type FulfillmentPayload } from './FulfillmentCard';
import ProcurementReadinessCard, { type ProcurementPlan } from './ProcurementReadinessCard';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import {
    deliveryNoteStatusLabel,
    fulfillmentLabel,
    fulfillmentTone,
    invoiceStatusLabel,
    invoiceStatusTone,
    label,
    orderStatusLabel,
    orderStatusTone,
    paymentEntryStatusLabel,
    paymentMethodLabel,
    paymentStatusLabel,
    paymentStatusTone,
} from '@/utils/labels';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Allocation = { quantity: string; warehouse: { name: string; code: string }; inventory_reservation: { status: string } | null };
type OutOfStockArticleRef = { id: number; status: string } | null;
type Line = {
    id: number;
    line_type: string;
    product_variant_id: number | null;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    unit_label: string | null;
    quantity: string;
    unit_price_excl_tax: string;
    discount_amount: string;
    tax_rate: string;
    tax_amount: string;
    total_incl_tax: string;
    purchase_price_snapshot?: string | null;
    cost_total_snapshot?: string | null;
    margin_amount_snapshot?: string | null;
    margin_rate_snapshot?: string | null;
    cost_status?: 'pending' | 'available' | 'missing' | 'unavailable' | null;
    allocations: Allocation[];
    out_of_stock_article: OutOfStockArticleRef;
    addendum: { id: number; sequence: number } | null;
};
type Customer = {
    id: number;
    type: string;
    display_name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    tax_identifier: string | null;
    billing_address: string | null;
} | null;
type Order = {
    id: number;
    sandbox_id?: number | null;
    order_number: string;
    source: string;
    customer_name: string | null;
    customer_company: string | null;
    customer_email: string | null;
    customer_phone: string | null;
    sale_date: string;
    currency_code: string;
    status: string;
    fulfillment_status: string;
    payment_status: string;
    subtotal_excl_tax: string;
    discount_total: string;
    tax_total: string;
    total_incl_tax: string;
    pos_shipping_fee: string;
    notes: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    cancelled_by: { name: string } | null;
    store: { name: string; code: string };
    customer: Customer;
    created_by: { name: string } | null;
    salesperson_id: number | null;
    salesperson_name_snapshot: string | null;
    salesperson: { id: number; name: string } | null;
    lines: Line[];
};
type Account = { id: number; name: string; code: string; type: string; currency_code: string; accepted_methods: string[] };
type Payment = {
    id: number;
    payment_number: string;
    method: string;
    status: string;
    amount: string;
    allocated_amount: string;
    payment_date: string;
    reference: string | null;
    financial_account: Account;
    received_by: { name: string };
};
type Refund = {
    id: number;
    refund_number: string;
    method: string;
    status: string;
    amount: string;
    currency_code: string;
    refund_date: string;
    reason: string;
    original_payment: { id: number; payment_number: string };
    financial_account: { id: number; name: string; code: string; type: string };
    refunded_by: { name: string } | null;
};
type InvoiceDoc = { id: number; sales_order_revision_id: number | null; sales_order_addendum_id: number | null; invoice_number: string | null; version: number; invoice_date: string; status: string; total_incl_tax: string };
type Revision = { id: number; revision_number: number; status: string; reason: string; initiated_at: string | null; initiated_by: string | null; completed_at: string | null; completed_by: string | null; before_total: string; after_total: string | null };
type Addendum = { id: number; sequence: number; before_total: string; added_total: string; after_total: string; fulfilled_at: string | null; created_at: string | null; created_by: string | null; lines: Pick<Line, 'id' | 'product_name' | 'variant_name' | 'quantity' | 'total_incl_tax'>[] };
type ActiveCorrection = { id: number; revision_number: number; reason: string };
type CompletionEligibility = { allowed: boolean; code: string; reason: string | null };
type PaymentEntry = { method: string; financial_account_id: string; amount: string; payment_date: string; reference: string; external_reference: string; notes: string };
type TransferReq = { id: number; request_number: string; status: string; source: string | null; destination: string | null; unit_count: number };
type ProcurementRow = {
    id: number;
    procurement_number: string;
    status: string;
    status_label: string;
    supplier_availability_status: string;
    supplier_availability_label: string;
    supplier: { id: number; name: string } | null;
    sales_order_line_id: number | null;
    product: string;
    quantity: string;
    supplier_reference: string | null;
    expected_at: string | null;
    ordered_at: string | null;
    received_at: string | null;
    receiving_warehouse: { id: number; name: string } | null;
    transfer_request: { id: number; request_number: string; status: string } | null;
    notes: string | null;
    cancellation_reason: string | null;
};
type ProcurementLine = {
    id: number;
    product: string;
    requested: string;
    company_available: string;
    procured: string;
    to_procure: string;
};
type ProcurementData = {
    rows: ProcurementRow[];
    awaiting: boolean;
    can: { manage: boolean; receive: boolean };
    lines: ProcurementLine[];
    suppliers: { id: number; name: string }[];
    warehouses: { id: number; name: string; code: string }[];
};
type CustomerExchange = {
    id: number;
    exchange_number: string;
    status: string;
    settlement_status: string;
    returned_total: string;
    new_items_total: string;
    difference_amount: string;
    created_at: string | null;
    return_number: string | null;
    addendum_sequence: number | null;
};
type FulfillmentRouting = {
    mode: 'pickup' | 'delivery';
    origin: 'showroom' | 'direct';
    explicit: boolean;
    showroom: { id: number; name: string } | null;
    warehouses: { id: number; name: string; is_showroom: boolean; allocated: string; reserved: string }[];
    reserved_outside_showroom: boolean;
};

/** Delivery method × fulfillment origin, and where each customer unit is reserved. */
function FulfillmentRoutingCard({ routing }: { routing: FulfillmentRouting }) {
    const { t } = useI18n();
    const showroom = routing.showroom?.name ?? t('pos.originShowroomFallback');
    return (
        <section className="mb-6 rounded-card border border-line bg-surface p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-semibold text-ink">{t('orders.routing.title')}</h2>
                <div className="flex flex-wrap gap-1.5">
                    <span className="rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink">
                        {routing.mode === 'delivery' ? t('inventory.trq.modeDelivery') : t('inventory.trq.modePickup')}
                    </span>
                    <span className="rounded-full bg-sage px-2.5 py-1 text-xs font-medium text-ink">
                        {routing.origin === 'direct' ? t('inventory.trq.originDirect') : t('inventory.trq.originShowroom').replace('{warehouse}', showroom)}
                    </span>
                </div>
            </div>
            <p className="mt-2 text-sm text-ink-muted">
                {routing.origin === 'direct' ? t('orders.routing.directHelp') : t('orders.routing.showroomHelp').replace('{warehouse}', showroom)}
                {!routing.explicit && <span className="ms-1 text-ink-faint">{t('orders.routing.legacy')}</span>}
            </p>
            {routing.warehouses.length > 0 && (
                <dl className="mt-3 divide-y divide-line rounded-field border border-line text-sm">
                    {routing.warehouses.map((w) => (
                        <div key={w.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <dt className="text-ink">
                                {w.name}
                                {!w.is_showroom && routing.origin === 'showroom' && Number(w.reserved) > 0 && (
                                    <span className="ms-2 text-xs text-warning">{t('orders.routing.toTransfer').replace('{warehouse}', showroom)}</span>
                                )}
                                {!w.is_showroom && routing.origin === 'direct' && Number(w.reserved) > 0 && (
                                    <span className="ms-2 text-xs text-ink-muted">{t('orders.routing.shipsFromHere')}</span>
                                )}
                            </dt>
                            <dd className="tabular-nums text-ink-muted" dir="ltr">
                                {t('orders.routing.reservedOf').replace('{reserved}', formatQuantity(w.reserved)).replace('{allocated}', formatQuantity(w.allocated))}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
            {routing.reserved_outside_showroom && (
                <p className="mt-2 rounded-field bg-warning-soft px-3 py-2 text-xs text-warning">{t('orders.routing.blocked').replace('{warehouse}', showroom)}</p>
            )}
        </section>
    );
}

type Props = {
    order: Order;
    paymentSummary: { paid: string; collected: string; refunded: string; net: string; remaining: string; status: string };
    payments: Payment[];
    refunds: Refund[];
    financialAccounts: Account[];
    documents: { invoices: InvoiceDoc[]; deliveryNotes: { id: number; delivery_note_number: string | null; delivery_date: string; status: string }[] };
    awaitingReplenishment: boolean;
    /** POS orders only: delivery method, fulfillment origin and customer reservations per warehouse. */
    fulfillmentRouting: FulfillmentRouting | null;
    procurement: ProcurementData;
    procurementPlan: ProcurementPlan;
    fulfillment: FulfillmentPayload | null;
    transferRequests: TransferReq[];
    revisions: Revision[];
    activeCorrection: ActiveCorrection | null;
    pendingReplacementInvoice: boolean;
    invoiceStaleAfterCompletion: boolean;
    addenda: Addendum[];
    customerReturns: any[];
    customerExchanges: CustomerExchange[];
    returnPolicy: any | null;
    commercialSummary: { gross: string; returns: string; net: string; state: 'none' | 'partial' | 'full'; fully_returned: boolean };
    marginSummary: { net_revenue_excl_tax: string; covered_revenue_excl_tax: string; cost_total: string; gross_margin_amount: string; gross_margin_rate: string | null; missing_cost_line_count: number } | null;
    salespersons: { id: number; name: string }[];
    completionEligibility: CompletionEligibility;
    can: {
        update: boolean;
        confirm: boolean;
        cancel: boolean;
        fulfill: boolean;
        recordPayment: boolean;
        backdatePayment: boolean;
        createInvoice: boolean;
        createDeliveryNote: boolean;
        reportOutOfStockArticle: boolean;
        correct: boolean;
        completePos: boolean;
        createReturn: boolean;
        createExchange: boolean;
        viewMargin: boolean;
        reassignSalesperson: boolean;
    };
};

const today = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const blankPayment = (remaining = ''): PaymentEntry => ({
    method: 'cash',
    financial_account_id: '',
    amount: remaining,
    payment_date: today(),
    reference: '',
    external_reference: '',
    notes: '',
});

function Card({ label: l, children }: { label: string; children: ReactNode }) {
    return (
        <div className="surface-card px-4 py-3">
            <p className="text-[13px] font-medium text-ink-muted">{l}</p>
            <div className="mt-1 text-sm font-semibold text-ink">{children}</div>
        </div>
    );
}

export default function ShowOrder({ order, paymentSummary, payments, refunds, financialAccounts, documents, awaitingReplenishment, fulfillmentRouting, procurement, procurementPlan, fulfillment, transferRequests, revisions, activeCorrection, pendingReplacementInvoice, invoiceStaleAfterCompletion, addenda, customerReturns, customerExchanges, returnPolicy, commercialSummary, marginSummary, salespersons, completionEligibility, can }: Props) {
    const { t } = useI18n();
    const [confirmCancel, setConfirmCancel] = useState(false);
    const cancellation = useForm({ reason: '' });
    const createInvoice = useForm({});
    const correctionForm = useForm({ reason: '' });
    const [showCorrection, setShowCorrection] = useState(false);
    const [showSalespersonCorrection, setShowSalespersonCorrection] = useState(false);
    const salespersonForm = useForm({ salesperson_id: order.salesperson_id, reason: '' });
    const paymentForm = useForm<{ client_operation_id: string; payments: PaymentEntry[] }>({
        client_operation_id: crypto.randomUUID(),
        payments: [blankPayment(paymentSummary.remaining)],
    });

    const activeInvoice = documents.invoices.find((i) => i.status !== 'cancelled') ?? null;
    const currentRevision = revisions.at(-1) ?? null;
    const correctionOrderError = (correctionForm.errors as Record<string, string>).order;
    const activeDeliveryNote = documents.deliveryNotes.find((n) => n.status !== 'cancelled') ?? null;
    // Test Mode (Phase 3): a Test draft order produces non-official Test documents.
    const documentableStatus = order.status === 'confirmed' || (order.sandbox_id != null && order.status === 'draft');
    const canCreateDeliveryNoteForOrder = documentableStatus && order.fulfillment_status === 'unfulfilled' && !activeDeliveryNote && can.createDeliveryNote;
    const isCompany = (order.customer?.type ?? 'individual') === 'company' || !!order.customer_company;
    const net = (Number(order.subtotal_excl_tax) - Number(order.discount_total)).toFixed(4);
    const hasDiscount = Number(order.discount_total) > 0;
    const hasShipping = Number(order.pos_shipping_fee) > 0;

    // Exactly one primary action, chosen by what the order needs next. Visibility
    // conditions below are unchanged; only the visual emphasis is decided here.
    const showContinueCorrection = Boolean(activeCorrection && can.update);
    const showConfirm = order.status === 'draft' && !activeCorrection && Boolean(can.confirm);
    const showComplete = Boolean(can.completePos);
    const showContinueInvoice = Boolean(activeInvoice && activeInvoice.status === 'draft');
    const showCreateInvoice = documentableStatus && (!activeInvoice || Boolean(pendingReplacementInvoice)) && Boolean(can.createInvoice);
    const primaryAction = showContinueCorrection
        ? 'correction'
        : showConfirm
          ? 'confirm'
          : showComplete
            ? 'complete'
            : showContinueInvoice
              ? 'continueInvoice'
              : showCreateInvoice
                ? 'createInvoice'
                : null;
    const variantFor = (key: string) => (primaryAction === key ? 'primary' : 'secondary');

    const cancel = (event: FormEvent) => {
        event.preventDefault();
        cancellation.post(`/sales/orders/${order.id}/cancel`, { preserveScroll: true, onSuccess: () => setConfirmCancel(false) });
    };
    const startCorrection = (event: FormEvent) => {
        event.preventDefault();
        correctionForm.post(`/sales/orders/${order.id}/corrections`);
    };
    const reassignSalesperson = (event: FormEvent) => {
        event.preventDefault();
        salespersonForm.patch(`/sales/orders/${order.id}/salesperson`, {
            preserveScroll: true,
            onSuccess: () => setShowSalespersonCorrection(false),
        });
    };
    const recordPayments = (event: FormEvent) => {
        event.preventDefault();
        paymentForm.post(`/sales/orders/${order.id}/payments`, {
            preserveScroll: true,
            onSuccess: () => paymentForm.setData({ client_operation_id: crypto.randomUUID(), payments: [blankPayment()] }),
        });
    };
    const setEntry = (index: number, key: keyof PaymentEntry, value: string) =>
        paymentForm.setData(
            'payments',
            paymentForm.data.payments.map((entry, position) => (position === index ? { ...entry, [key]: value } : entry)),
        );

    return (
        <SalesLayout>
            <Head title={order.order_number} />

            <div className="mb-6">
                <ButtonLink href="/sales/orders" variant="ghost" size="sm" className="-ms-2 mb-1">
                    <svg aria-hidden="true" className="directional-icon rtl:rotate-180" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 6 8 12l6 6" /></svg>
                    {t('orders.show.back')}
                </ButtonLink>
                <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink [overflow-wrap:anywhere] sm:text-page"><bdi>{order.order_number}</bdi></h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        {formatDate(order.sale_date)} · {order.store.name}
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-1.5" aria-label={t('common.status')}>
                        <DocBadge tone={orderStatusTone(order.status)}>{label(orderStatusLabel, order.status)}</DocBadge>
                        <DocBadge tone={fulfillment?.logistics_state === 'delivered' ? 'positive' : fulfillment?.logistics_state === 'shipped' ? 'info' : fulfillmentTone(order.fulfillment_status)}>
                            {fulfillment ? t(`fulfillment.logistics.${fulfillment.logistics_state}` as TranslationKey) : label(fulfillmentLabel, order.fulfillment_status)}
                        </DocBadge>
                        <DocBadge tone={paymentStatusTone(paymentSummary.status)}>{label(paymentStatusLabel, paymentSummary.status)}</DocBadge>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {showContinueCorrection ? (
                        <ButtonLink href={`/sales/orders/${order.id}/edit`} variant={variantFor('correction')}>
                            {t('orders.show.continueCorrection')}
                        </ButtonLink>
                    ) : order.status === 'draft' && can.update ? (
                        <ButtonLink href={`/sales/orders/${order.id}/edit`} variant="secondary">
                            {t('common.edit')}
                        </ButtonLink>
                    ) : null}
                    {showConfirm && (
                        <Button variant={variantFor('confirm')} onClick={() => router.post(`/sales/orders/${order.id}/confirm`)}>{t('common.confirm')}</Button>
                    )}

                    {order.status === 'confirmed' && order.fulfillment_status === 'unfulfilled' && can.correct && !documents.deliveryNotes.some((note) => note.status === 'issued') && (
                        <Button variant="secondary" onClick={() => setShowCorrection(true)}>{t('orders.show.correctOrder')}</Button>
                    )}
                    {order.status === 'confirmed' && order.fulfillment_status === 'unfulfilled' && can.correct && documents.deliveryNotes.some((note) => note.status === 'issued') && (
                        <span className="max-w-xs self-center text-[13px] text-ink-muted">{t('orders.show.correctionBlockedByDeliveryNote')}</span>
                    )}

                    {showComplete && (
                        <ButtonLink href={`/sales/orders/${order.id}/completion`} variant={variantFor('complete')}>
                            {t('orders.show.completeOrder')}
                        </ButtonLink>
                    )}
                    {!can.completePos && order.source === 'pos' && completionEligibility.code !== 'missing_permission' && (
                        <span className="inline-flex flex-col items-start gap-1">
                            <button
                                type="button"
                                disabled
                                title={completionEligibility.reason ?? undefined}
                                className="inline-flex min-h-10 cursor-not-allowed items-center rounded-field border border-line bg-raised px-4 text-sm font-semibold text-ink-muted"
                            >
                                {t('orders.show.completeOrder')}
                            </button>
                            {completionEligibility.reason && (
                                <span className="max-w-xs text-[13px] text-ink-muted">{completionEligibility.reason}</span>
                            )}
                        </span>
                    )}
                    {can.createReturn && (
                        <ButtonLink href={`/sales/orders/${order.id}/returns/create`} variant="secondary">
                            {t('orders.show.returnItems')}
                        </ButtonLink>
                    )}
                    {can.createExchange && (
                        <ButtonLink href={`/sales/orders/${order.id}/exchanges/create`} variant="secondary">
                            {t('orders.show.exchangeItems')}
                        </ButtonLink>
                    )}

                    {showCreateInvoice && (
                        <Button
                            variant={variantFor('createInvoice')}
                            loading={createInvoice.processing}
                            loadingText={t('common.creating')}
                            onClick={() => createInvoice.post(`/sales/orders/${order.id}/invoices`)}
                        >
                            {pendingReplacementInvoice ? t('orders.show.generateNewInvoice') : t('orders.show.createInvoice')}
                        </Button>
                    )}
                    {showContinueInvoice && activeInvoice && (
                        <ButtonLink href={`/invoices/${activeInvoice.id}`} variant={variantFor('continueInvoice')}>
                            {t('orders.show.continueInvoice')}
                        </ButtonLink>
                    )}
                    {activeInvoice && activeInvoice.status === 'issued' && !activeCorrection && !pendingReplacementInvoice && (
                        <>
                            <ButtonLink href={`/invoices/${activeInvoice.id}`} variant="secondary">
                                {t('orders.show.invoice')} <bdi>{activeInvoice.invoice_number}</bdi>
                            </ButtonLink>
                            <a
                                href={`/invoices/${activeInvoice.id}/pdf`}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex min-h-10 items-center gap-2 rounded-field border border-line-strong bg-surface px-4 text-sm font-semibold text-ink shadow-xs transition-soft hover:bg-sage"
                            >
                                PDF
                            </a>
                        </>
                    )}

                    {canCreateDeliveryNoteForOrder && (
                        <Button variant="secondary" onClick={() => router.post(`/sales/orders/${order.id}/delivery-notes`)}>
                            {t('orders.show.createDeliveryNote')}
                        </Button>
                    )}
                </div>
                </div>
            </div>

            <Dialog
                open={showCorrection && Boolean(can.correct)}
                onClose={() => setShowCorrection(false)}
                dismissible={!correctionForm.processing}
                size="md"
                title={t('orders.show.correctOrder')}
                description={t('orders.show.correctionHelp')}
            >
                    <form onSubmit={startCorrection} className="space-y-4 p-4 sm:p-6">
                        <div>
                            <label htmlFor="correction-reason" className="mb-1 block text-sm font-medium text-ink">{t('orders.show.correctionReason')}</label>
                            <textarea
                                id="correction-reason"
                                required
                                data-autofocus
                                value={correctionForm.data.reason}
                                onChange={(event) => correctionForm.setData('reason', event.target.value)}
                                placeholder={t('orders.show.correctionPlaceholder')}
                                aria-invalid={correctionForm.errors.reason ? true : undefined}
                                className="control-base min-h-28 px-3 py-2 text-sm"
                            />
                        </div>
                        {correctionForm.errors.reason && <p className="text-sm text-danger">{correctionForm.errors.reason}</p>}
                        {correctionOrderError && <p className="text-sm text-danger">{correctionOrderError}</p>}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setShowCorrection(false)}>{t('common.cancel')}</Button>
                            <Button type="submit" loading={correctionForm.processing} loadingText={t('common.opening')}>{t('orders.show.startCorrection')}</Button>
                        </div>
                    </form>
            </Dialog>

            <Dialog
                open={showSalespersonCorrection && Boolean(can.reassignSalesperson)}
                onClose={() => setShowSalespersonCorrection(false)}
                dismissible={!salespersonForm.processing}
                size="md"
                title={t('orders.show.reassignSalesperson')}
                description={t('orders.show.reassignHelp')}
            >
                    <form onSubmit={reassignSalesperson} className="space-y-4 p-4 sm:p-6">
                        <label className="block text-sm">
                            <span className="mb-1 block font-medium text-ink">{t('orders.show.salesperson')}</span>
                            <select data-autofocus value={salespersonForm.data.salesperson_id ?? ''} onChange={(e) => salespersonForm.setData('salesperson_id', e.target.value ? Number(e.target.value) : null)} className="control-select h-11">
                                <option value="">{t('orders.show.unassigned')}</option>
                                {salespersons.map((salesperson) => <option key={salesperson.id} value={salesperson.id}>{salesperson.name}</option>)}
                            </select>
                        </label>
                        <label className="block text-sm">
                            <span className="mb-1 block font-medium text-ink">{t('common.reason')}</span>
                            <textarea required value={salespersonForm.data.reason} onChange={(e) => salespersonForm.setData('reason', e.target.value)} className="control-base min-h-24 px-3 py-2 text-sm" />
                        </label>
                        {Object.values(salespersonForm.errors).map((error) => error && <p key={error} className="text-sm text-danger">{error}</p>)}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setShowSalespersonCorrection(false)}>{t('common.cancel')}</Button>
                            <Button type="submit" loading={salespersonForm.processing}>{t('orders.show.confirmReassignment')}</Button>
                        </div>
                    </form>
            </Dialog>

            {order.status === 'confirmed' && order.fulfillment_status === 'fulfilled' && (
                <div className="mb-6 rounded-field border border-line bg-raised/60 px-4 py-3 text-sm text-ink-muted">
                    {t('orders.show.fulfilledLockHelp')}
                </div>
            )}

            {order.status === 'cancelled' && (
                <section className="mb-6 rounded-card border border-danger/30 bg-danger-soft/40 p-5">
                    <h2 className="font-semibold text-danger">{t('orders.show.cancelledOrder')}</h2>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                        <div><dt className="text-ink-faint">{t('common.reason')}</dt><dd className="text-ink">{order.cancellation_reason ?? '—'}</dd></div>
                        <div><dt className="text-ink-faint">{t('orders.show.cancelledBy')}</dt><dd className="text-ink">{order.cancelled_by?.name ?? t('orders.show.deletedUser')}</dd></div>
                        <div><dt className="text-ink-faint">{t('orders.show.cancellationDate')}</dt><dd className="text-ink">{order.cancelled_at ? formatDate(order.cancelled_at) : '—'}</dd></div>
                    </dl>
                    <div className="mt-4 grid gap-3 sm:grid-cols-3">
                        <Card label={t('orders.show.collected')}>{formatMoney(paymentSummary.collected, order.currency_code)}</Card>
                        <Card label={t('orders.show.refunded')}>{formatMoney(paymentSummary.refunded, order.currency_code)}</Card>
                        <Card label={t('orders.show.netCollected')}>{formatMoney(paymentSummary.net, order.currency_code)}</Card>
                    </div>
                </section>
            )}

            {customerReturns.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="font-semibold">{t('orders.show.returns')}</h2>{commercialSummary.state !== 'none' && <span className={`rounded-full px-3 py-1 text-xs font-semibold ${commercialSummary.fully_returned ? 'bg-danger-soft text-danger' : 'bg-warning-soft text-warning'}`}>{commercialSummary.fully_returned ? t('orders.show.fullyReturned') : t('orders.show.partialReturn')}</span>}</div>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3"><Card label={t('orders.show.initialSale')}>{formatMoney(commercialSummary.gross, order.currency_code)}</Card><Card label={t('orders.show.returns')}>-{formatMoney(commercialSummary.returns, order.currency_code)}</Card><Card label={t('orders.show.commercialNet')}>{formatMoney(commercialSummary.net, order.currency_code)}</Card></div>
                    <div className="mt-3 divide-y divide-line">{customerReturns.map((item) => <Link key={item.id} href={`/sales/returns/${item.id}`} className="flex justify-between py-2 text-sm"><span>{item.return_number} · {item.status}</span><span>-{formatMoney(item.total_incl_tax, order.currency_code)}</span></Link>)}</div>
                    {returnPolicy && <p className="mt-3 text-xs text-ink-muted">{returnPolicy.within_policy ? t('orders.show.returnAllowed') : t('orders.show.returnExpired')} · {t('orders.show.deadline')} {returnPolicy.deadline ? formatDateTime(returnPolicy.deadline) : '—'}</p>}
                </section>
            )}

            {/* Two columns from xl: working content on the main track, the commercial
                summary (totals, balance, customer, salesperson, margin) beside it. */}
            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] 2xl:grid-cols-[minmax(0,1fr)_26rem]">
            <aside aria-label={t('orders.show.summary')} className="grid gap-4 sm:grid-cols-2 xl:col-start-2 xl:row-start-1 xl:grid-cols-1">
                {/* Financial summary */}
                <section className="surface-card p-5 sm:col-span-2 xl:col-span-1">
                    <h2 className="text-base font-semibold text-ink">{t('orders.show.summary')}</h2>
                    <dl className="mt-3 space-y-1.5 text-sm">
                        <Row term={t('orders.show.grossExclTax')} value={formatMoney(order.subtotal_excl_tax, order.currency_code)} />
                        {hasDiscount && (
                            <Row term={t('orders.show.discountExclTax')} value={`- ${formatMoney(order.discount_total, order.currency_code)}`} />
                        )}
                        {hasDiscount && <Row term={t('orders.show.totalExclTax')} value={formatMoney(net, order.currency_code)} />}
                        <Row term={t('orders.show.tax')} value={formatMoney(order.tax_total, order.currency_code)} />
                        {hasShipping && (
                            <Row term={t('orders.show.delivery')} value={formatMoney(order.pos_shipping_fee, order.currency_code)} />
                        )}
                        <div className="mt-2 flex items-baseline justify-between gap-3 border-t border-line pt-3 text-ink">
                            <dt className="font-semibold">{t('orders.show.totalInclTax')}</dt>
                            <dd className="metric-value text-2xl font-semibold" dir="ltr">{formatMoney(order.total_incl_tax, order.currency_code)}</dd>
                        </div>
                    </dl>
                    <div className="mt-4 grid grid-cols-2 gap-3 rounded-field bg-raised p-3 text-sm">
                        <div>
                            <p className="text-ink-muted">{t('orders.show.paid')}</p>
                            <p className="metric-value text-base font-semibold text-ink" dir="ltr">{formatMoney(paymentSummary.paid, order.currency_code)}</p>
                        </div>
                        <div>
                            <p className="text-ink-muted">{t('orders.show.remaining')}</p>
                            <p className={`metric-value text-base font-semibold ${Number(paymentSummary.remaining) > 0 ? 'text-warning' : 'text-success'}`} dir="ltr">
                                {formatMoney(paymentSummary.remaining, order.currency_code)}
                            </p>
                        </div>
                    </div>
                </section>

                {/* Customer + salesperson */}
                <section className="surface-card p-5">
                    <h2 className="text-base font-semibold text-ink">{t('common.customer')}</h2>
                    <div className="mt-2 space-y-0.5 text-sm text-ink-muted">
                        <p className="font-medium text-ink">
                            {isCompany
                                ? order.customer?.company_name ?? order.customer_company ?? order.customer_name
                                : order.customer_name ?? t('common.counterCustomer')}
                        </p>
                        {isCompany && order.customer_name && <p>{t('orders.show.contact')} : {order.customer_name}</p>}
                        {(order.customer?.tax_identifier ?? null) && <p>ICE : {order.customer?.tax_identifier}</p>}
                        {(order.customer?.phone ?? order.customer_phone) && (
                            <p>{t('orders.show.phone')} : <bdi>{order.customer?.phone ?? order.customer_phone}</bdi></p>
                        )}
                        {(order.customer?.email ?? order.customer_email) && <p className="break-all">{order.customer?.email ?? order.customer_email}</p>}
                        {(order.customer?.billing_address ?? null) && <p className="whitespace-pre-line">{order.customer?.billing_address}</p>}
                    </div>
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3 text-sm">
                        <div className="min-w-0">
                            <p className="text-ink-muted">{t('orders.show.salesperson')}</p>
                            <p className="truncate font-medium text-ink">{order.salesperson_name_snapshot ?? order.salesperson?.name ?? t('orders.show.unassigned')}</p>
                        </div>
                        {can.reassignSalesperson && order.status === 'confirmed' && (
                            <Button size="sm" variant="secondary" onClick={() => setShowSalespersonCorrection(true)}>
                                {t('orders.show.reassign')}
                            </Button>
                        )}
                    </div>
                </section>

                {can.viewMargin && marginSummary && (
                    <section className="surface-card p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold text-ink">{t('orders.show.grossMargin')}</h2>
                            {marginSummary.missing_cost_line_count > 0 && (
                                <DocBadge tone="warning">{t('orders.show.missingCosts').replace('{count}', String(marginSummary.missing_cost_line_count))}</DocBadge>
                            )}
                        </div>
                        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <Stat term={t('orders.show.coveredRevenue')} value={formatMoney(marginSummary.covered_revenue_excl_tax, order.currency_code)} />
                            <Stat term={t('orders.show.purchaseCost')} value={formatMoney(marginSummary.cost_total, order.currency_code)} />
                            <Stat term={t('orders.show.grossMarginShort')} value={formatMoney(marginSummary.gross_margin_amount, order.currency_code)} />
                            <Stat term={t('orders.show.marginRate')} value={marginSummary.gross_margin_rate === null ? '—' : `${formatQuantity(marginSummary.gross_margin_rate)}%`} />
                        </dl>
                    </section>
                )}
            </aside>

            <div className="min-w-0 xl:col-start-1 xl:row-start-1">
            {/* Product lines */}
            <section aria-label={t('orders.show.lines')} className="surface-card mb-6 min-w-0 overflow-hidden">
                <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={t('orders.show.lines')}>
                <table className="erp-table min-w-[720px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('common.product')}</th>
                            <th className="num px-4 py-2.5">{t('common.quantityShort')}</th>
                            <th className="num px-4 py-2.5">{t('orders.show.unitPriceExclTax')}</th>
                            <th className="num px-4 py-2.5">{t('orders.show.discountExclTax')}</th>
                            <th className="num px-4 py-2.5">{t('orders.show.tax')}</th>
                            <th className="num px-4 py-2.5">{t('orders.show.totalInclTax')}</th>
                            {can.viewMargin && <th className="num px-4 py-2.5">{t('orders.show.marginExclTax')}</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {order.lines.map((line) => (
                            <tr key={line.id}>
                                <td className="px-4 py-3">
                                    <div className="font-medium text-ink">{line.product_name}</div>
                                    <div className="text-xs text-ink-muted">
                                        {[line.variant_name, line.reference ?? line.sku].filter(Boolean).join(' · ')}
                                    </div>
                                    <div className="mt-0.5 text-xs text-ink-faint">
                                        {line.addendum
                                            ? t('orders.show.addendumDelivered').replace('{sequence}', String(line.addendum.sequence))
                                            : t('orders.show.initialDelivered')}
                                    </div>
                                    {line.allocations.length > 0 && (
                                        <div className="mt-0.5 text-xs text-ink-faint">
                                            {line.allocations
                                                .map((a) => `${formatQuantity(a.quantity)} · ${a.warehouse.name}`)
                                                .join('  ·  ')}
                                        </div>
                                    )}
                                </td>
                                <td className="num px-4 py-3" dir="ltr">
                                    {formatQuantity(line.quantity)} {line.unit_label ?? ''}
                                </td>
                                <td className="num px-4 py-3" dir="ltr">
                                    {formatMoney(line.unit_price_excl_tax, order.currency_code)}
                                </td>
                                <td className="num px-4 py-3" dir="ltr">
                                    {Number(line.discount_amount) > 0
                                        ? `- ${formatMoney(line.discount_amount, order.currency_code)}`
                                        : '—'}
                                </td>
                                <td className="num px-4 py-3" dir="ltr">{formatQuantity(line.tax_rate)}%</td>
                                <td className="num px-4 py-3 font-semibold text-ink" dir="ltr">
                                    {formatMoney(line.total_incl_tax, order.currency_code)}
                                </td>
                                {can.viewMargin && (
                                    <td className="num px-4 py-3" dir="ltr">
                                        {line.cost_status === 'available' && line.margin_amount_snapshot !== null
                                            ? formatMoney(line.margin_amount_snapshot, order.currency_code)
                                            : <span className="text-warning">{t('common.unavailable')}</span>}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
                </div>
            </section>

            {fulfillmentRouting && <FulfillmentRoutingCard routing={fulfillmentRouting} />}

            {/* Internal replenishment / transfer requests */}
            {transferRequests.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-semibold text-ink">{t('orders.show.replenishment')}</h2>
                        {awaitingReplenishment && (
                            <span className="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning">{t('orders.show.toReplenish')}</span>
                        )}
                    </div>
                    <ul className="mt-3 space-y-1.5 text-sm">
                        {transferRequests.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2">
                                <Link href={`/inventory/transfer-requests/${r.id}`} className="font-medium text-ink underline">
                                    {r.request_number}
                                </Link>
                                <span className="text-ink-muted">
                                    {r.source} → {r.destination} · {formatQuantity(r.unit_count)} u.
                                </span>
                                <span className="text-ink-muted">{t((`transferRequests.status.${r.status}`) as Parameters<typeof t>[0])}</span>
                            </li>
                        ))}
                    </ul>
                    {awaitingReplenishment && (
                        <p className="mt-2 text-xs text-ink-faint">
                            {t('orders.show.replenishmentHelp')}
                        </p>
                    )}
                </section>
            )}

            {/* Supplier procurement / special orders — a domain of its own,
                deliberately not mixed with the internal transfer requests above. */}
            <ProcurementReadinessCard orderId={order.id} plan={procurementPlan} />
            {fulfillment && <FulfillmentCard orderId={order.id} data={fulfillment} />}
            <ProcurementPanel order={order} data={procurement} />

            {/* Articles hors stock — a Custom (not-yet-catalogued) line flagged for
                the catalogue team to source or create. Separate queue: see
                /procurement/out-of-stock-articles. Never mixed with the supplier
                procurement panel above (distinct business problems). */}
            <OutOfStockArticlePanel order={order} canReport={can.reportOutOfStockArticle} />

            {revisions.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-base font-semibold text-ink">{t('orders.show.correctionHistory')}</h2>
                    <div className="mt-3 space-y-3">
                        {revisions.map((revision) => (
                            <div key={revision.revision_number} className="rounded-field border border-line bg-raised/50 p-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-ink">{t('orders.show.correction')} {revision.revision_number}</span>
                                    <span className="text-ink-muted">{revision.initiated_by ?? t('common.user')} · {revision.initiated_at ? formatDate(revision.initiated_at) : '—'}</span>
                                </div>
                                <p className="mt-1 text-ink-muted">{revision.reason}</p>
                                <p className="mt-1 text-xs text-ink-faint">
                                    {formatMoney(revision.before_total, order.currency_code)} → {revision.after_total ? formatMoney(revision.after_total, order.currency_code) : t('common.inProgress')}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {addenda.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-base font-semibold text-ink">{t('orders.show.addendumHistory')}</h2>
                    <div className="mt-3 space-y-3">
                        <div className="rounded-field border border-line bg-raised/50 p-3 text-sm">
                            <span className="font-medium text-ink">{t('orders.show.initialOrder')}</span>
                        </div>
                        {addenda.map((addendum) => (
                            <div key={addendum.id} className="rounded-field border border-line bg-raised/50 p-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-ink">{t('orders.show.addendum')} #{addendum.sequence}</span>
                                    <span className="text-ink-muted">{addendum.created_by ?? t('common.user')} · {addendum.fulfilled_at ? formatDate(addendum.fulfilled_at) : '—'}</span>
                                </div>
                                <p className="mt-1 text-ink-muted">
                                    {addendum.lines.map((line) => `${line.product_name} × ${formatQuantity(line.quantity)}`).join(' · ')}
                                </p>
                                <p className="mt-1 text-xs text-ink-faint">
                                    + {formatMoney(addendum.added_total, order.currency_code)} · {formatMoney(addendum.before_total, order.currency_code)} → {formatMoney(addendum.after_total, order.currency_code)}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {customerExchanges.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-base font-semibold text-ink">{t('orders.show.exchangeHistory')}</h2>
                    <div className="mt-3 divide-y divide-line">
                        {customerExchanges.map((exchange) => (
                            <Link key={exchange.id} href={`/sales/exchanges/${exchange.id}`} className="grid gap-2 py-3 text-sm sm:grid-cols-[1fr_auto]">
                                <div>
                                    <p className="font-medium text-ink">{exchange.exchange_number} · {exchange.status}</p>
                                    <p className="text-xs text-ink-muted">{t('orders.show.return')} <bdi>{exchange.return_number ?? '—'}</bdi>{exchange.addendum_sequence ? ` · ${t('orders.show.addendum')} #${exchange.addendum_sequence}` : ''}</p>
                                </div>
                                <div className="text-right text-xs text-ink-muted">
                                    <p>{t('orders.show.return')} : {formatMoney(exchange.returned_total, order.currency_code)}</p>
                                    <p>{t('orders.show.newItems')} : {formatMoney(exchange.new_items_total, order.currency_code)}</p>
                                    <p className="font-semibold text-ink">{t('orders.show.difference')} : {formatMoney(Math.abs(Number(exchange.difference_amount)), order.currency_code)}</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {/* Invoice history */}
            <section className="mb-6 rounded-card border border-line bg-surface p-5">
                <h2 className="text-base font-semibold text-ink">{t('orders.show.relatedInvoices')}</h2>
                {pendingReplacementInvoice && (
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-field border border-warning/30 bg-warning-soft/50 p-4">
                        <div>
                            <p className="font-semibold text-ink">{invoiceStaleAfterCompletion ? t('orders.show.completedAfterInvoice') : t('orders.show.correctedNeedsInvoice')}</p>
                            <p className="mt-1 text-sm text-ink-muted">{t('orders.show.historicalInvoiceHelp')}</p>
                        </div>
                        {documentableStatus && can.createInvoice && (
                            <Button
                                loading={createInvoice.processing}
                                loadingText={t('common.creating')}
                                onClick={() => createInvoice.post(`/sales/orders/${order.id}/invoices`)}
                            >
                                {t('orders.show.generateNewVersion')}
                            </Button>
                        )}
                    </div>
                )}
                {documents.invoices.length === 0 && !pendingReplacementInvoice && (
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-ink-muted">{t('orders.show.noInvoice')}</p>
                        {documentableStatus && can.createInvoice && (
                            <Button loading={createInvoice.processing} loadingText={t('common.creating')} onClick={() => createInvoice.post(`/sales/orders/${order.id}/invoices`)}>
                                {t('orders.show.createAnInvoice')}
                            </Button>
                        )}
                    </div>
                )}
                {documents.invoices.length > 0 && (
                    <ul className="mt-4 divide-y divide-line border-t border-line text-sm">
                        {documents.invoices.map((invoice) => {
                            const isPreviousCommercialState = currentRevision !== null
                                && invoice.sales_order_revision_id !== currentRevision.id
                                && invoice.status === 'issued';
                            const isPreCompletionVersion = invoiceStaleAfterCompletion
                                && invoice.status === 'issued'
                                && invoice.sales_order_addendum_id !== addenda.at(-1)?.id;
                            const isCurrentRevisionDraft = currentRevision !== null
                                && invoice.sales_order_revision_id === currentRevision.id
                                && invoice.status === 'draft';

                            return (
                                <li key={invoice.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link href={`/invoices/${invoice.id}`} className="font-medium text-ink underline">
                                                {invoice.invoice_number ? <>{t('orders.show.invoice')} <bdi>{invoice.invoice_number}</bdi></> : t('orders.show.draftInvoice')}
                                            </Link>
                                            <DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge>
                                        </div>
                                        <p className="mt-1 text-xs font-medium text-ink-muted">{t('orders.show.version')} {invoice.version}</p>
                                        {(isPreviousCommercialState || isPreCompletionVersion) && <p className="mt-1 text-xs text-warning">{t('orders.show.completedAfterInvoice')}</p>}
                                        {invoice.status === 'issued' && !isPreviousCommercialState && !isPreCompletionVersion && <p className="mt-1 text-xs text-ink-muted">{t('orders.show.currentInvoice')}</p>}
                                        {isCurrentRevisionDraft && <p className="mt-1 text-xs text-ink-muted">{t('orders.show.reviewInvoiceBeforeIssue')}</p>}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="tabular-nums text-ink-muted">{formatMoney(invoice.total_incl_tax, order.currency_code)}</span>
                                        <Link href={`/invoices/${invoice.id}`} className="inline-flex min-h-9 items-center rounded-field border border-line-strong bg-surface px-3 text-ink hover:bg-raised">
                                            {invoice.status === 'draft' ? t('common.continue') : t('common.view')}
                                        </Link>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            {/* Payment history */}
            <section className="mb-6">
                <h2 className="mb-2 text-base font-semibold text-ink">{t('orders.show.paymentHistory')}<SandboxPaymentBadge inline /></h2>
                <div className="surface-card overflow-x-auto">
                    <table className="erp-table min-w-[720px] text-sm">
                        <thead>
                            <tr>
                                <th className="px-4 py-2.5">{t('common.date')}</th>
                                <th className="px-4 py-2.5">{t('orders.show.method')}</th>
                                <th className="px-4 py-2.5">{t('orders.show.account')}</th>
                                <th className="px-4 py-2.5">{t('common.reference')}</th>
                                <th className="num px-4 py-2.5">{t('common.amount')}</th>
                                <th className="px-4 py-2.5">{t('common.status')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {payments.map((payment) => (
                                <tr key={payment.id}>
                                    <td className="px-4 py-3 text-ink-muted">{formatDate(payment.payment_date)}</td>
                                    <td className="px-4 py-3">{label(paymentMethodLabel, payment.method)}</td>
                                    <td className="px-4 py-3 text-ink-muted">{payment.financial_account?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-ink-muted">
                                        {payment.reference || <span className="text-ink-faint">{payment.payment_number}</span>}
                                    </td>
                                    <td className="num px-4 py-3" dir="ltr">
                                        {formatMoney(payment.allocated_amount, order.currency_code)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={payment.status === 'posted' ? 'positive' : 'danger'}>
                                            {label(paymentEntryStatusLabel, payment.status)}
                                        </DocBadge>
                                    </td>
                                </tr>
                            ))}
                            {payments.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-ink-muted">
                                        {t('orders.show.noPayment')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {refunds.length > 0 && (
                <section className="mb-6">
                    <h2 className="mb-2 text-base font-semibold text-ink">{t('orders.show.refundHistory')}</h2>
                    <div className="surface-card overflow-x-auto">
                        <table className="erp-table min-w-[760px] text-sm">
                            <thead>
                                <tr>
                                    <th className="px-4 py-2.5">{t('common.date')}</th>
                                    <th className="px-4 py-2.5">{t('orders.show.refund')}</th>
                                    <th className="px-4 py-2.5">{t('orders.show.originalPayment')}</th>
                                    <th className="px-4 py-2.5">{t('orders.show.methodAccount')}</th>
                                    <th className="px-4 py-2.5">{t('common.reason')}</th>
                                    <th className="num px-4 py-2.5">{t('orders.show.outflow')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {refunds.map((refund) => (
                                    <tr key={refund.id}>
                                        <td className="px-4 py-3 text-ink-muted">{formatDate(refund.refund_date)}</td>
                                        <td className="px-4 py-3 font-medium text-ink">{refund.refund_number}</td>
                                        <td className="px-4 py-3 text-ink-muted">{refund.original_payment.payment_number}</td>
                                        <td className="px-4 py-3 text-ink-muted">{label(paymentMethodLabel, refund.method)} · {refund.financial_account.name}</td>
                                        <td className="px-4 py-3 text-ink-muted">{refund.reason}</td>
                                        <td className="num px-4 py-3 font-medium text-danger" dir="ltr">− {formatMoney(refund.amount, order.currency_code)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {/* Record payment (kept, secondary) */}
            {can.recordPayment && order.status === 'confirmed' && paymentSummary.remaining !== '0.0000' && (
                <details className="group mb-6 rounded-card border border-line bg-surface p-5">
                    {/* Native disclosure kept; styled as a visible secondary action with the balance next to it. */}
                    <summary className="flex cursor-pointer list-none flex-wrap items-center gap-3 [&::-webkit-details-marker]:hidden">
                        <span className="inline-flex min-h-10 items-center gap-2 rounded-field border border-line-strong bg-surface px-4 text-sm font-semibold text-ink shadow-xs transition-soft hover:bg-sage group-open:bg-sage">
                            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" className="transition-transform group-open:rotate-45"><path d="M12 5v14M5 12h14" /></svg>
                            {t('orders.show.recordPayment')}
                        </span>
                        <span className="text-sm text-ink-muted">
                            {t('orders.show.remaining')} : <strong className="metric-value text-warning">{formatMoney(paymentSummary.remaining, order.currency_code)}</strong>
                        </span>
                    </summary>
                    <form onSubmit={recordPayments} className="mt-4 space-y-3">
                        {paymentForm.data.payments.map((entry, index) => (
                            <div key={index} className="grid gap-3 rounded-field bg-raised p-4 md:grid-cols-6">
                                <label className="text-sm">
                                    {t('orders.show.method')}
                                    <select
                                        value={entry.method}
                                        onChange={(e) => {
                                            const nextMethod = e.target.value;
                                            const stillCompatible = financialAccounts.some(
                                                (account) => String(account.id) === entry.financial_account_id && account.accepted_methods.includes(nextMethod),
                                            );
                                            paymentForm.setData(
                                                'payments',
                                                paymentForm.data.payments.map((row, position) =>
                                                    position === index
                                                        ? { ...row, method: nextMethod, financial_account_id: stillCompatible ? row.financial_account_id : '' }
                                                        : row,
                                                ),
                                            );
                                        }}
                                        className="control-input mt-1"
                                    >
                                        <option value="cash">{t('paymentMethods.cash')}</option>
                                        <option value="card">{t('paymentMethods.card')}</option>
                                        <option value="bank_transfer">{t('paymentMethods.bank_transfer')}</option>
                                        <option value="cheque">{t('paymentMethods.cheque')}</option>
                                    </select>
                                </label>
                                <label className="text-sm md:col-span-2">
                                    {t('orders.show.account')}
                                    <select
                                        required
                                        value={entry.financial_account_id}
                                        onChange={(e) => setEntry(index, 'financial_account_id', e.target.value)}
                                        className="control-input mt-1"
                                    >
                                        <option value="">{t('common.choose')}</option>
                                        {financialAccounts
                                            .filter((account) => account.accepted_methods.includes(entry.method))
                                            .map((account) => (
                                                <option key={account.id} value={account.id}>
                                                    {account.code} · {account.name}
                                                </option>
                                            ))}
                                    </select>
                                    {financialAccounts.length > 0 && financialAccounts.every((account) => !account.accepted_methods.includes(entry.method)) && (
                                        <span className="mt-1 block text-xs text-warning">{t('orders.show.noCompatibleAccount')}</span>
                                    )}
                                </label>
                                <label className="text-sm">
                                    {t('common.amount')}
                                    <input
                                        required
                                        inputMode="decimal"
                                        value={entry.amount}
                                        onChange={(e) => setEntry(index, 'amount', e.target.value)}
                                        className="control-input mt-1"
                                    />
                                </label>
                                <label className="text-sm">
                                    {t('common.date')}
                                    <input
                                        required
                                        type="date"
                                        readOnly={!can.backdatePayment}
                                        value={entry.payment_date}
                                        onChange={(e) => setEntry(index, 'payment_date', e.target.value)}
                                        className="control-input mt-1 read-only:bg-raised"
                                    />
                                </label>
                                <label className="text-sm">
                                    {t('common.reference')}
                                    <input
                                        maxLength={255}
                                        value={entry.reference}
                                        onChange={(e) => setEntry(index, 'reference', e.target.value)}
                                        className="control-input mt-1"
                                    />
                                </label>
                                {paymentForm.data.payments.length > 1 && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            paymentForm.setData(
                                                'payments',
                                                paymentForm.data.payments.filter((_, position) => position !== index),
                                            )
                                        }
                                        className="text-left text-sm text-danger"
                                    >
                                        {t('common.remove')}
                                    </button>
                                )}
                            </div>
                        ))}
                        {Object.values(paymentForm.errors).map(
                            (error) => error && <p key={error} className="text-sm text-danger">{error}</p>,
                        )}
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => paymentForm.setData('payments', [...paymentForm.data.payments, blankPayment()])}
                                className="rounded-field border border-line-strong px-4 py-2 text-sm"
                            >
                                {t('orders.show.addMethod')}
                            </button>
                            <Button
                                type="submit"
                                loading={paymentForm.processing}
                                loadingText={t('common.saving')}
                                disabled={financialAccounts.length === 0}
                            >
                                {t('common.save')}
                            </Button>
                        </div>
                        {financialAccounts.length === 0 && (
                            <p className="text-sm text-warning">
                                {t('orders.show.createAccountFirst')}
                            </p>
                        )}
                    </form>
                </details>
            )}

            {/* Delivery notes */}
            {(documents.deliveryNotes.length > 0 || canCreateDeliveryNoteForOrder) && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-base font-semibold text-ink">{t('orders.show.deliveryNotes')}</h2>
                    <ul className="mt-2 space-y-1 text-sm">
                        {documents.deliveryNotes.map((note) => (
                            <li key={note.id}>
                                <Link href={`/delivery-notes/${note.id}`} className="text-ink underline">
                                    {note.delivery_note_number ?? t('common.draft')}
                                </Link>{' '}
                                <span className="text-ink-muted">· {label(deliveryNoteStatusLabel, note.status)}</span>
                            </li>
                        ))}
                        {documents.deliveryNotes.length === 0 && <li className="text-ink-muted">{t('orders.show.noDeliveryNote')}</li>}
                    </ul>
                </section>
            )}

            {order.notes && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-base font-semibold text-ink">{t('common.notes')}</h2>
                    <p className="mt-2 whitespace-pre-line text-sm text-ink-muted">{order.notes}</p>
                </section>
            )}

            </div>
            </div>

            {/* Danger zone */}
            {can.cancel && order.status !== 'cancelled' && order.fulfillment_status === 'unfulfilled' && (
                <div className="mt-8 border-t border-line pt-4">
                    <Button variant="danger" onClick={() => setConfirmCancel(true)}>
                        {t('orders.show.cancelOrder')}
                    </Button>
                    <Dialog
                        open={confirmCancel}
                        onClose={() => setConfirmCancel(false)}
                        dismissible={!cancellation.processing}
                        danger
                        size="md"
                        title={t('orders.show.cancelOrder')}
                        description={`${t('orders.show.order')} ${order.order_number} · ${t('common.total')} ${formatMoney(order.total_incl_tax, order.currency_code)} · ${t('orders.show.deliveryState')} : ${label(fulfillmentLabel, order.fulfillment_status)}`}
                    >
                        <form onSubmit={cancel} className="space-y-4 p-4 sm:p-6">
                            {paymentSummary.collected !== '0.0000' && (
                                <div className="rounded-field border border-warning/30 bg-warning-soft p-3 text-sm text-ink">
                                    <p>{t('orders.show.collectedAmount')} : <strong>{formatMoney(paymentSummary.collected, order.currency_code)}</strong></p>
                                    <p>{t('orders.show.refundAmount')} : <strong>{formatMoney(paymentSummary.net, order.currency_code)}</strong></p>
                                    <p className="mt-1 text-xs text-ink-muted">{t('orders.show.refundHelp')}</p>
                                </div>
                            )}
                            <label htmlFor="cancellation-reason" className="block text-sm font-medium text-ink">{t('orders.show.cancellationReason')}</label>
                            <textarea
                                id="cancellation-reason"
                                required
                                data-autofocus
                                value={cancellation.data.reason}
                                onChange={(e) => cancellation.setData('reason', e.target.value)}
                                placeholder={t('orders.show.cancellationPlaceholder')}
                                aria-invalid={cancellation.errors.reason ? true : undefined}
                                className="control-base min-h-24 px-3 py-2 text-sm"
                            />
                            {cancellation.errors.reason && <p className="text-sm text-danger">{cancellation.errors.reason}</p>}
                            {(cancellation.errors as Record<string, string>).order && <p className="text-sm text-danger">{(cancellation.errors as Record<string, string>).order}</p>}
                            <div className="flex flex-wrap justify-end gap-2">
                                <Button type="button" variant="secondary" onClick={() => setConfirmCancel(false)} disabled={cancellation.processing}>
                                    {t('common.back')}
                                </Button>
                                <Button type="submit" variant="danger-solid" loading={cancellation.processing} loadingText={t('orders.show.cancelling')}>
                                    {paymentSummary.net !== '0.0000' ? t('orders.show.cancelAndRefund') : t('orders.show.confirmCancellation')}
                                </Button>
                            </div>
                        </form>
                    </Dialog>
                </div>
            )}
            <div className="mt-6">
                <ActivityTimeline subject="sales_order" id={order.id} />
            </div>
        </SalesLayout>
    );
}

/** Stacked label/value — for compact metric grids where a side-by-side Row would wrap. */
function Stat({ term, value }: { term: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-[13px] text-ink-muted">{term}</dt>
            <dd className="metric-value mt-0.5 font-semibold text-ink">{value}</dd>
        </div>
    );
}

function Row({ term, value }: { term: string; value: string }) {
    return (
        <div className="flex justify-between text-ink-muted">
            <dt>{term}</dt>
            <dd className="tabular-nums text-ink">{value}</dd>
        </div>
    );
}

const PROC_STATUS_CLASS: Record<string, string> = {
    pending_supplier: 'bg-warning-soft text-warning',
    supplier_confirmed: 'bg-info-soft text-info',
    ordered: 'bg-accent-soft text-accent',
    partially_received: 'bg-warning-soft text-warning',
    received: 'bg-success-soft text-success',
    completed: 'bg-success-soft text-success',
    cancelled: 'bg-sage text-ink-muted',
    unavailable: 'bg-danger-soft text-danger',
};

function OutOfStockArticlePanel({ order, canReport }: { order: Order; canReport: boolean }) {
    const { t } = useI18n();
    const unresolvedLines = order.lines.filter((l) => l.line_type === 'custom' && l.product_variant_id === null);
    if (unresolvedLines.length === 0) return null;

    const flag = (lineId: number) => router.post(`/sales/orders/${order.id}/out-of-stock-articles`, { sales_order_line_id: lineId }, { preserveScroll: true });

    return (
        <section className="mb-6 rounded-card border border-line bg-surface p-5">
            <h2 className="text-base font-semibold text-ink">{t('orders.show.outOfCatalog')}</h2>
            <ul className="mt-3 space-y-2">
                {unresolvedLines.map((line) => (
                    <li key={line.id} className="rounded-field border border-line p-3 text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-medium text-ink">
                                {line.product_name} · {formatQuantity(line.quantity)} u.
                            </span>
                            {line.out_of_stock_article ? (
                                <span
                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                        line.out_of_stock_article.status === 'resolved'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-amber-50 text-amber-700'
                                    }`}
                                >
                                    {line.out_of_stock_article.status === 'resolved' ? t('common.resolved') : t('orders.show.reportedPending')}
                                </span>
                            ) : canReport ? (
                                <button
                                    type="button"
                                    onClick={() => flag(line.id)}
                                    className="rounded-field bg-primary px-3 py-1.5 text-xs font-medium text-primary-fg hover:bg-primary-hover"
                                >
                                    {t('orders.show.reportForCatalog')}
                                </button>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function ProcurementPanel({ order, data }: { order: Order; data: ProcurementData }) {
    const { t } = useI18n();
    const [addLine, setAddLine] = useState<number | null>(null);
    const toProcureLines = data.lines.filter((l) => Number(l.to_procure) > 0);
    if (data.rows.length === 0 && toProcureLines.length === 0 && !data.awaiting) return null;

    return (
        <section className="mb-6 rounded-card border border-line bg-surface p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-semibold text-ink">{t('orders.show.supplierProcurement')}</h2>
                {data.awaiting && (
                    <span className="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning">
                        {t('orders.show.toProcureSupplier')}
                    </span>
                )}
            </div>

            {/* Draft coverage breakdown + raise action */}
            {order.status === 'draft' && data.can.manage && toProcureLines.length > 0 && (
                <div className="mt-3 space-y-2">
                    {data.suppliers.length === 0 && (
                        <p className="rounded-field bg-warning-soft/50 px-3 py-2 text-xs text-warning">
                            {t('orders.show.noActiveSupplier')}
                        </p>
                    )}
                    {toProcureLines.map((line) => (
                        <div key={line.id} className="rounded-field border border-line bg-raised p-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium text-ink">{line.product}</span>
                                <span className="text-xs text-ink-muted">
                                    {t('orders.show.requested')} {formatQuantity(line.requested)} · {t('orders.show.companyStock')} {formatQuantity(line.company_available)} ·
                                    {t('orders.show.toReplenish')} <strong className="text-ink">{formatQuantity(line.to_procure)}</strong>
                                </span>
                            </div>
                            {data.suppliers.length > 0 &&
                                (addLine === line.id ? (
                                    <RaiseProcurementForm
                                        orderId={order.id}
                                        line={line}
                                        suppliers={data.suppliers}
                                        onDone={() => setAddLine(null)}
                                    />
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => setAddLine(line.id)}
                                        className="mt-2 rounded-field bg-primary px-3 py-1.5 text-xs font-medium text-primary-fg hover:bg-primary-hover"
                                    >
                                        {t('orders.show.procureFromSupplier')}
                                    </button>
                                ))}
                        </div>
                    ))}
                </div>
            )}

            {/* Existing procurement rows */}
            {data.rows.length > 0 && (
                <ul className="mt-3 space-y-2">
                    {data.rows.map((row) => (
                        <li key={row.id} className="rounded-field border border-line p-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <span className="font-medium text-ink">{row.procurement_number}</span>{' '}
                                    <span className="text-ink-muted">
                                        · {row.product} · {formatQuantity(row.quantity)} u.
                                    </span>
                                </div>
                                <span
                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                        PROC_STATUS_CLASS[row.status] ?? 'bg-slate-100 text-slate-500'
                                    }`}
                                >
                                    {t((`orders.show.procurementStatus.${row.status}`) as Parameters<typeof t>[0])}
                                </span>
                            </div>
                            <div className="mt-1 text-xs text-ink-muted">
                                {row.supplier?.name ?? '—'}
                                {row.supplier_reference ? ` · ${t('orders.show.refShort')} ${row.supplier_reference}` : ''}
                                {row.expected_at ? ` · ${t('orders.show.estimatedReceipt')} ${formatDate(row.expected_at)}` : ''}
                                {row.ordered_at ? ` · ${t('orders.show.ordered')} ${formatDate(row.ordered_at)}` : ''}
                                {row.received_at
                                    ? ` · ${t('orders.show.received')} ${formatDate(row.received_at)} ${t('orders.show.at')} ${row.receiving_warehouse?.name ?? '—'}`
                                    : ''}
                            </div>
                            {row.transfer_request && (
                                <p className="mt-1 text-xs text-ink-faint">
                                    {t('orders.show.internalTransfer')}{' '}
                                    <Link
                                        href={`/inventory/transfer-requests/${row.transfer_request.id}`}
                                        className="underline"
                                    >
                                        {row.transfer_request.request_number}
                                    </Link>{' '}
                                    {t('orders.show.toShowroom')}
                                </p>
                            )}
                            {row.cancellation_reason && (
                                <p className="mt-1 text-xs text-ink-faint">{t('common.reason')} : {row.cancellation_reason}</p>
                            )}
                            <ProcurementRowActions row={row} data={data} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function RaiseProcurementForm({
    orderId,
    line,
    suppliers,
    onDone,
}: {
    orderId: number;
    line: ProcurementLine;
    suppliers: { id: number; name: string }[];
    onDone: () => void;
}) {
    const { t } = useI18n();
    const form = useForm({
        sales_order_line_id: line.id,
        supplier_id: suppliers[0]?.id ?? 0,
        quantity: line.to_procure,
        supplier_reference: '',
        expected_at: '',
        notes: '',
    });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/sales/orders/${orderId}/procurements`, { preserveScroll: true, onSuccess: onDone });
            }}
            className="mt-2 grid gap-2 sm:grid-cols-2"
        >
            <label className="text-xs">
                {t('common.supplier')}
                <select
                    value={form.data.supplier_id}
                    onChange={(e) => form.setData('supplier_id', Number(e.target.value))}
                    className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                >
                    {suppliers.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="text-xs">
                {t('common.quantity')}
                <input
                    inputMode="decimal"
                    value={form.data.quantity}
                    onChange={(e) => form.setData('quantity', e.target.value)}
                    className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                />
            </label>
            <label className="text-xs">
                {t('orders.show.supplierReferenceOptional')}
                <input
                    value={form.data.supplier_reference}
                    onChange={(e) => form.setData('supplier_reference', e.target.value)}
                    className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                />
            </label>
            <label className="text-xs">
                {t('orders.show.estimatedReceiptOptional')}
                <input
                    type="date"
                    value={form.data.expected_at}
                    onChange={(e) => form.setData('expected_at', e.target.value)}
                    className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                />
            </label>
            {Object.values(form.errors).map((err) => err && <p key={err} className="text-xs text-danger sm:col-span-2">{err}</p>)}
            <div className="flex gap-2 sm:col-span-2">
                <button
                    type="submit"
                    disabled={form.processing}
                    className="rounded-field bg-primary px-3 py-1.5 text-xs font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
                >
                    {t('orders.show.createProcurement')}
                </button>
                <button type="button" onClick={onDone} className="rounded-field px-3 py-1.5 text-xs text-ink-muted">
                    {t('common.cancel')}
                </button>
            </div>
        </form>
    );
}

function ProcurementRowActions({ row, data }: { row: ProcurementRow; data: ProcurementData }) {
    const { t } = useI18n();
    const [open, setOpen] = useState<'supplier' | 'cancel' | null>(null);
    const supplierForm = useForm({ supplier_id: data.suppliers[0]?.id ?? 0 });
    const cancelForm = useForm({ reason: '', acknowledge_ordered: false });
    const done = { preserveScroll: true, onSuccess: () => setOpen(null) };

    const canManage = data.can.manage;
    const isEditable = row.status === 'pending_supplier' || row.status === 'supplier_confirmed';

    if (row.status === 'cancelled' || row.status === 'unavailable' || row.status === 'received' || row.status === 'completed') {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {/* Ordering and receipt go through the purchase order (Approvisionnement card → « Créer la commande fournisseur », then the purchase order page). */}
            {canManage && isEditable && data.suppliers.length > 0 && (
                <ActionButton onClick={() => setOpen(open === 'supplier' ? null : 'supplier')}>{t('orders.show.changeSupplier')}</ActionButton>
            )}
            {canManage && (row.status === 'pending_supplier' || row.status === 'supplier_confirmed' || row.status === 'ordered') && (
                <ActionButton onClick={() => setOpen(open === 'cancel' ? null : 'cancel')} danger>
                    {t('common.cancel')}
                </ActionButton>
            )}

            {open === 'supplier' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        supplierForm.patch(`/procurement/${row.id}/supplier`, done);
                    }}
                    className="mt-1 w-full grid gap-2 rounded-field bg-raised p-3 sm:grid-cols-2"
                >
                    <label className="text-xs">
                        {t('orders.show.newSupplier')}
                        <select
                            value={supplierForm.data.supplier_id}
                            onChange={(e) => supplierForm.setData('supplier_id', Number(e.target.value))}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        >
                            {data.suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <FormErrors errors={supplierForm.errors} />
                    <SubmitRow processing={supplierForm.processing} onCancel={() => setOpen(null)} label={t('common.replace')} />
                </form>
            )}

            {open === 'cancel' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        cancelForm.post(`/procurement/${row.id}/cancel`, done);
                    }}
                    className="mt-1 w-full grid gap-2 rounded-field bg-danger-soft/40 p-3"
                >
                    <label className="text-xs">
                        {t('common.reason')}
                        <input
                            value={cancelForm.data.reason}
                            onChange={(e) => cancelForm.setData('reason', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        />
                    </label>
                    {row.status === 'ordered' && (
                        <label className="flex items-center gap-2 text-xs text-danger">
                            <input
                                type="checkbox"
                                checked={cancelForm.data.acknowledge_ordered}
                                onChange={(e) => cancelForm.setData('acknowledge_ordered', e.target.checked)}
                            />
                            {t('orders.show.confirmAbandonSupplierOrder')}
                        </label>
                    )}
                    <FormErrors errors={cancelForm.errors} />
                    <SubmitRow processing={cancelForm.processing} onCancel={() => setOpen(null)} label={t('orders.show.cancelProcurement')} danger />
                </form>
            )}
        </div>
    );
}

function ActionButton({ children, onClick, primary, danger }: { children: ReactNode; onClick: () => void; primary?: boolean; danger?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-field px-3 py-1.5 text-xs font-medium ${
                primary
                    ? 'bg-primary text-primary-fg hover:bg-primary-hover'
                    : danger
                      ? 'border border-danger/40 text-danger hover:bg-danger-soft'
                      : 'border border-line-strong text-ink hover:bg-raised'
            }`}
        >
            {children}
        </button>
    );
}

function FormErrors({ errors }: { errors: Partial<Record<string, string>> }) {
    const list = Object.values(errors).filter(Boolean) as string[];
    if (list.length === 0) return null;
    return (
        <div className="sm:col-span-2">
            {list.map((err) => (
                <p key={err} className="text-xs text-danger">
                    {err}
                </p>
            ))}
        </div>
    );
}

function SubmitRow({ processing, onCancel, label, danger }: { processing: boolean; onCancel: () => void; label: string; danger?: boolean }) {
    const { t } = useI18n();
    return (
        <div className="flex gap-2 sm:col-span-2">
            <button
                type="submit"
                disabled={processing}
                className={`rounded-field px-3 py-1.5 text-xs font-medium text-primary-fg disabled:opacity-50 ${
                    danger ? 'bg-danger hover:bg-danger/90' : 'bg-primary hover:bg-primary-hover'
                }`}
            >
                {label}
            </button>
            <button type="button" onClick={onCancel} className="rounded-field px-3 py-1.5 text-xs text-ink-muted">
                {t('common.close')}
            </button>
        </div>
    );
}
