import DocBadge from '@/components/ui/DocBadge';
import { Button } from '@/components/ui/Button';
import { Popover } from '@/components/pos/primitives';
import SalesLayout from '@/layouts/SalesLayout';
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
    discount_amount_ttc?: string;
    tax_rate: string;
    tax_amount: string;
    total_incl_tax: string;
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
    discount_total_ttc?: string;
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
type Props = {
    order: Order;
    paymentSummary: { paid: string; collected: string; refunded: string; net: string; remaining: string; status: string };
    payments: Payment[];
    refunds: Refund[];
    financialAccounts: Account[];
    documents: { invoices: InvoiceDoc[]; deliveryNotes: { id: number; delivery_note_number: string | null; delivery_date: string; status: string }[] };
    awaitingReplenishment: boolean;
    procurement: ProcurementData;
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
        <div className="rounded-card border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-muted">{l}</p>
            <div className="mt-1 text-sm font-semibold text-ink">{children}</div>
        </div>
    );
}

const TR_STATUS_LABEL: Record<string, string> = {
    requested: 'Demandée',
    preparing: 'En préparation',
    shipped: 'Expédiée',
    received: 'Réceptionnée',
    cancelled: 'Annulée',
};

export default function ShowOrder({ order, paymentSummary, payments, refunds, financialAccounts, documents, awaitingReplenishment, procurement, transferRequests, revisions, activeCorrection, pendingReplacementInvoice, invoiceStaleAfterCompletion, addenda, customerReturns, customerExchanges, returnPolicy, commercialSummary, completionEligibility, can }: Props) {
    const [confirmCancel, setConfirmCancel] = useState(false);
    const cancellation = useForm({ reason: '' });
    const createInvoice = useForm({});
    const correctionForm = useForm({ reason: '' });
    const [showCorrection, setShowCorrection] = useState(false);
    const paymentForm = useForm<{ client_operation_id: string; payments: PaymentEntry[] }>({
        client_operation_id: crypto.randomUUID(),
        payments: [blankPayment(paymentSummary.remaining)],
    });

    const activeInvoice = documents.invoices.find((i) => i.status !== 'cancelled') ?? null;
    const currentRevision = revisions.at(-1) ?? null;
    const correctionOrderError = (correctionForm.errors as Record<string, string>).order;
    const activeDeliveryNote = documents.deliveryNotes.find((n) => n.status !== 'cancelled') ?? null;
    const canCreateDeliveryNoteForOrder = order.status === 'confirmed' && order.fulfillment_status === 'unfulfilled' && !activeDeliveryNote && can.createDeliveryNote;
    const isCompany = (order.customer?.type ?? 'individual') === 'company' || !!order.customer_company;
    const net = (Number(order.subtotal_excl_tax) - Number(order.discount_total)).toFixed(4);
    const hasDiscount = Number(order.discount_total_ttc ?? order.discount_total) > 0;
    const hasShipping = Number(order.pos_shipping_fee) > 0;

    const cancel = (event: FormEvent) => {
        event.preventDefault();
        cancellation.post(`/sales/orders/${order.id}/cancel`, { preserveScroll: true, onSuccess: () => setConfirmCancel(false) });
    };
    const startCorrection = (event: FormEvent) => {
        event.preventDefault();
        correctionForm.post(`/sales/orders/${order.id}/corrections`);
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

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link href="/sales/orders" className="text-sm text-ink-muted">
                        ← Commandes
                    </Link>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink">{order.order_number}</h1>
                    <p className="text-sm text-ink-muted">
                        {formatDate(order.sale_date)} · {order.store.name}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {activeCorrection && can.update ? (
                        <Link
                            href={`/sales/orders/${order.id}/edit`}
                            className="inline-flex min-h-10 items-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                        >
                            Continuer la correction
                        </Link>
                    ) : order.status === 'draft' && can.update ? (
                        <Link
                            href={`/sales/orders/${order.id}/edit`}
                            className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                        >
                            Modifier
                        </Link>
                    ) : null}
                    {order.status === 'draft' && !activeCorrection && can.confirm && (
                        <Button onClick={() => router.post(`/sales/orders/${order.id}/confirm`)}>Confirmer</Button>
                    )}

                    {order.status === 'confirmed' && order.fulfillment_status === 'unfulfilled' && can.correct && (
                        <Button variant="secondary" onClick={() => setShowCorrection(true)}>Corriger la commande</Button>
                    )}

                    {can.completePos && (
                        <Link
                            href={`/sales/orders/${order.id}/completion`}
                            className="inline-flex min-h-10 items-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                        >
                            Compléter la commande
                        </Link>
                    )}
                    {!can.completePos && order.source === 'pos' && completionEligibility.code !== 'missing_permission' && (
                        <span className="inline-flex flex-col items-start gap-1">
                            <button
                                type="button"
                                disabled
                                title={completionEligibility.reason ?? undefined}
                                className="inline-flex min-h-10 cursor-not-allowed items-center rounded-field border border-line bg-raised px-4 text-sm font-medium text-ink-faint opacity-70"
                            >
                                Compléter la commande
                            </button>
                            {completionEligibility.reason && (
                                <span className="max-w-xs text-xs text-ink-muted">{completionEligibility.reason}</span>
                            )}
                        </span>
                    )}
                    {can.createReturn && (
                        <Link href={`/sales/orders/${order.id}/returns/create`} className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink hover:bg-raised">
                            Retourner des articles
                        </Link>
                    )}
                    {can.createExchange && (
                        <Link href={`/sales/orders/${order.id}/exchanges/create`} className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink hover:bg-raised">
                            Échanger des articles
                        </Link>
                    )}

                    {order.status === 'confirmed' && (!activeInvoice || pendingReplacementInvoice) && can.createInvoice && (
                        <Button
                            loading={createInvoice.processing}
                            loadingText="Création…"
                            onClick={() => createInvoice.post(`/sales/orders/${order.id}/invoices`)}
                        >
                            {pendingReplacementInvoice ? 'Générer la nouvelle facture' : 'Créer facture'}
                        </Button>
                    )}
                    {activeInvoice && activeInvoice.status === 'draft' && (
                        <Link
                            href={`/invoices/${activeInvoice.id}`}
                            className="inline-flex min-h-10 items-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                        >
                            Continuer la facture
                        </Link>
                    )}
                    {activeInvoice && activeInvoice.status === 'issued' && !activeCorrection && !pendingReplacementInvoice && (
                        <>
                            <Link
                                href={`/invoices/${activeInvoice.id}`}
                                className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                            >
                                Facture {activeInvoice.invoice_number}
                            </Link>
                            <a
                                href={`/invoices/${activeInvoice.id}/pdf`}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                            >
                                PDF
                            </a>
                        </>
                    )}

                    {canCreateDeliveryNoteForOrder && (
                            <button
                                type="button"
                                onClick={() => router.post(`/sales/orders/${order.id}/delivery-notes`)}
                                className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                            >
                                Créer bon de livraison
                            </button>
                    )}
                </div>
            </div>

            {showCorrection && can.correct && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="presentation">
                    <form
                        onSubmit={startCorrection}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="correction-title"
                        className="w-full max-w-lg space-y-4 rounded-card border border-line bg-surface p-5 shadow-xl"
                    >
                        <div>
                            <h2 id="correction-title" className="text-lg font-semibold text-ink">Corriger la commande</h2>
                            <p className="mt-1 text-sm text-ink-muted">Indiquez le motif de la correction avant de modifier la commande.</p>
                        </div>
                        <div>
                            <label htmlFor="correction-reason" className="mb-1 block text-sm font-medium text-ink">Motif de la correction</label>
                            <textarea
                                id="correction-reason"
                                required
                                autoFocus
                                value={correctionForm.data.reason}
                                onChange={(event) => correctionForm.setData('reason', event.target.value)}
                                placeholder="Ex. Ajout d’un article demandé par le client"
                                className="min-h-28 w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                            />
                        </div>
                        {correctionForm.errors.reason && <p className="text-sm text-danger">{correctionForm.errors.reason}</p>}
                        {correctionOrderError && <p className="text-sm text-danger">{correctionOrderError}</p>}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setShowCorrection(false)}>Annuler</Button>
                            <Button type="submit" loading={correctionForm.processing} loadingText="Ouverture…">Démarrer la correction</Button>
                        </div>
                    </form>
                </div>
            )}

            {order.status === 'confirmed' && order.fulfillment_status === 'fulfilled' && (
                <div className="mb-6 rounded-field border border-line bg-raised/60 px-4 py-3 text-sm text-ink-muted">
                    Les articles déjà remis sont verrouillés. Vous pouvez uniquement ajouter un nouveau complément POS local ; toute réduction ou suppression relève d’un retour ou d’un avoir.
                </div>
            )}

            {order.status === 'cancelled' && (
                <section className="mb-6 rounded-card border border-danger/30 bg-danger-soft/40 p-5">
                    <h2 className="font-semibold text-danger">Commande annulée</h2>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                        <div><dt className="text-ink-faint">Motif</dt><dd className="text-ink">{order.cancellation_reason ?? '—'}</dd></div>
                        <div><dt className="text-ink-faint">Annulée par</dt><dd className="text-ink">{order.cancelled_by?.name ?? 'Utilisateur supprimé'}</dd></div>
                        <div><dt className="text-ink-faint">Date d’annulation</dt><dd className="text-ink">{order.cancelled_at ? formatDate(order.cancelled_at) : '—'}</dd></div>
                    </dl>
                    <div className="mt-4 grid gap-3 sm:grid-cols-3">
                        <Card label="Encaissé">{formatMoney(paymentSummary.collected, order.currency_code)}</Card>
                        <Card label="Remboursé">{formatMoney(paymentSummary.refunded, order.currency_code)}</Card>
                        <Card label="Net encaissé">{formatMoney(paymentSummary.net, order.currency_code)}</Card>
                    </div>
                </section>
            )}

            {customerReturns.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="font-semibold">Retours</h2>{commercialSummary.state !== 'none' && <span className={`rounded-full px-3 py-1 text-xs font-semibold ${commercialSummary.fully_returned ? 'bg-danger-soft text-danger' : 'bg-warning-soft text-warning'}`}>{commercialSummary.fully_returned ? 'Retournée intégralement' : 'Retour partiel'}</span>}</div>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3"><Card label="Vente initiale">{formatMoney(commercialSummary.gross, order.currency_code)}</Card><Card label="Retours">-{formatMoney(commercialSummary.returns, order.currency_code)}</Card><Card label="Net commercial">{formatMoney(commercialSummary.net, order.currency_code)}</Card></div>
                    <div className="mt-3 divide-y divide-line">{customerReturns.map((item) => <Link key={item.id} href={`/sales/returns/${item.id}`} className="flex justify-between py-2 text-sm"><span>{item.return_number} · {item.status}</span><span>-{formatMoney(item.total_incl_tax, order.currency_code)}</span></Link>)}</div>
                    {returnPolicy && <p className="mt-3 text-xs text-ink-muted">{returnPolicy.within_policy ? 'Retour encore autorisé' : 'Délai de retour dépassé'} · échéance {returnPolicy.deadline ? formatDateTime(returnPolicy.deadline) : '—'}</p>}
                </section>
            )}

            <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Card label="Client">
                    {isCompany
                        ? order.customer_company ?? order.customer?.company_name ?? order.customer_name ?? 'Client comptoir'
                        : order.customer_name ?? 'Client comptoir'}
                </Card>
                <Card label="Commande">
                    <DocBadge tone={orderStatusTone(order.status)}>{label(orderStatusLabel, order.status)}</DocBadge>
                </Card>
                <Card label="Préparation">
                    <DocBadge tone={fulfillmentTone(order.fulfillment_status)}>
                        {label(fulfillmentLabel, order.fulfillment_status)}
                    </DocBadge>
                </Card>
                <Card label="Paiement">
                    <DocBadge tone={paymentStatusTone(paymentSummary.status)}>
                        {label(paymentStatusLabel, paymentSummary.status)}
                    </DocBadge>
                </Card>
            </div>

            <div className="mb-6 grid gap-4 lg:grid-cols-3">
                {/* Customer */}
                <section className="rounded-card border border-line bg-surface p-5 lg:col-span-1">
                    <h2 className="text-sm font-semibold text-ink">Client</h2>
                    <div className="mt-2 space-y-0.5 text-sm text-ink-muted">
                        <p className="font-medium text-ink">
                            {isCompany
                                ? order.customer?.company_name ?? order.customer_company ?? order.customer_name
                                : order.customer_name ?? 'Client comptoir'}
                        </p>
                        {isCompany && order.customer_name && <p>Contact : {order.customer_name}</p>}
                        {(order.customer?.tax_identifier ?? null) && <p>ICE : {order.customer?.tax_identifier}</p>}
                        {(order.customer?.phone ?? order.customer_phone) && (
                            <p>Tél : {order.customer?.phone ?? order.customer_phone}</p>
                        )}
                        {(order.customer?.email ?? order.customer_email) && (
                            <p>{order.customer?.email ?? order.customer_email}</p>
                        )}
                        {(order.customer?.billing_address ?? null) && (
                            <p className="whitespace-pre-line">{order.customer?.billing_address}</p>
                        )}
                    </div>
                </section>

                {/* Financial summary */}
                <section className="rounded-card border border-line bg-surface p-5 lg:col-span-2">
                    <h2 className="text-sm font-semibold text-ink">Récapitulatif</h2>
                    <dl className="mt-3 space-y-1.5 text-sm">
                        <Row term="Sous-total HT" value={formatMoney(order.subtotal_excl_tax, order.currency_code)} />
                        {hasDiscount && <Row term="Total HT" value={formatMoney(net, order.currency_code)} />}
                        <Row term="TVA" value={formatMoney(order.tax_total, order.currency_code)} />
                        {hasDiscount && (
                            <Row term="Remise TTC" value={`- ${formatMoney(order.discount_total_ttc ?? order.discount_total, order.currency_code)}`} />
                        )}
                        {hasShipping && (
                            <Row term="Livraison" value={formatMoney(order.pos_shipping_fee, order.currency_code)} />
                        )}
                        <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold text-ink">
                            <dt>TOTAL TTC</dt>
                            <dd className="tabular-nums">{formatMoney(order.total_incl_tax, order.currency_code)}</dd>
                        </div>
                    </dl>
                    <div className="mt-4 grid grid-cols-2 gap-3 border-t border-line pt-3 text-sm">
                        <div>
                            <p className="text-ink-muted">Payé</p>
                            <p className="font-semibold text-ink">{formatMoney(paymentSummary.paid, order.currency_code)}</p>
                        </div>
                        <div>
                            <p className="text-ink-muted">Reste</p>
                            <p className="font-semibold text-ink">
                                {formatMoney(paymentSummary.remaining, order.currency_code)}
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            {/* Product lines */}
            <div className="mb-6 overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full min-w-[760px] text-left text-sm">
                    <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th className="px-4 py-3 font-medium">Produit</th>
                            <th className="px-4 py-3 text-right font-medium">Qté</th>
                            <th className="px-4 py-3 text-right font-medium">PU HT</th>
                            <th className="px-4 py-3 text-right font-medium">Remise TTC</th>
                            <th className="px-4 py-3 text-right font-medium">TVA</th>
                            <th className="px-4 py-3 text-right font-medium">Total TTC</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {order.lines.map((line) => (
                            <tr key={line.id}>
                                <td className="px-4 py-3">
                                    <div className="font-medium text-ink">{line.product_name}</div>
                                    <div className="text-xs text-ink-muted">
                                        {[line.variant_name, line.reference ?? line.sku].filter(Boolean).join(' · ')}
                                    </div>
                                    <div className="mt-0.5 text-xs text-ink-faint">
                                        {line.addendum ? `Complément #${line.addendum.sequence} · remis` : 'Commande initiale · déjà remis'}
                                    </div>
                                    {line.allocations.length > 0 && (
                                        <div className="mt-0.5 text-xs text-ink-faint">
                                            {line.allocations
                                                .map((a) => `${formatQuantity(a.quantity)} · ${a.warehouse.name}`)
                                                .join('  ·  ')}
                                        </div>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">
                                    {formatQuantity(line.quantity)} {line.unit_label ?? ''}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">
                                    {formatMoney(line.unit_price_excl_tax, order.currency_code)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">
                                    {Number(line.discount_amount_ttc ?? line.discount_amount) > 0
                                        ? `- ${formatMoney(line.discount_amount_ttc ?? line.discount_amount, order.currency_code)}`
                                        : '—'}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatQuantity(line.tax_rate)}%</td>
                                <td className="px-4 py-3 text-right font-medium tabular-nums text-ink">
                                    {formatMoney(line.total_incl_tax, order.currency_code)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Internal replenishment / transfer requests */}
            {transferRequests.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Approvisionnement</h2>
                        {awaitingReplenishment && (
                            <span className="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning">À approvisionner</span>
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
                                <span className="text-ink-muted">{TR_STATUS_LABEL[r.status] ?? r.status}</span>
                            </li>
                        ))}
                    </ul>
                    {awaitingReplenishment && (
                        <p className="mt-2 text-xs text-ink-faint">
                            La remise au client sera possible une fois le transfert interne réceptionné au showroom.
                        </p>
                    )}
                </section>
            )}

            {/* Supplier procurement / special orders — a domain of its own,
                deliberately not mixed with the internal transfer requests above. */}
            <ProcurementPanel order={order} data={procurement} />

            {/* Articles hors stock — a Custom (not-yet-catalogued) line flagged for
                the catalogue team to source or create. Separate queue: see
                /procurement/out-of-stock-articles. Never mixed with the supplier
                procurement panel above (distinct business problems). */}
            <OutOfStockArticlePanel order={order} canReport={can.reportOutOfStockArticle} />

            {revisions.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Historique des corrections commerciales</h2>
                    <div className="mt-3 space-y-3">
                        {revisions.map((revision) => (
                            <div key={revision.revision_number} className="rounded-field border border-line bg-raised/50 p-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-ink">Correction {revision.revision_number}</span>
                                    <span className="text-ink-muted">{revision.initiated_by ?? 'Utilisateur'} · {revision.initiated_at ? formatDate(revision.initiated_at) : '—'}</span>
                                </div>
                                <p className="mt-1 text-ink-muted">{revision.reason}</p>
                                <p className="mt-1 text-xs text-ink-faint">
                                    {formatMoney(revision.before_total, order.currency_code)} → {revision.after_total ? formatMoney(revision.after_total, order.currency_code) : 'en cours'}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {addenda.length > 0 && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Historique des compléments POS</h2>
                    <div className="mt-3 space-y-3">
                        <div className="rounded-field border border-line bg-raised/50 p-3 text-sm">
                            <span className="font-medium text-ink">Commande initiale</span>
                        </div>
                        {addenda.map((addendum) => (
                            <div key={addendum.id} className="rounded-field border border-line bg-raised/50 p-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-ink">Complément #{addendum.sequence}</span>
                                    <span className="text-ink-muted">{addendum.created_by ?? 'Utilisateur'} · {addendum.fulfilled_at ? formatDate(addendum.fulfilled_at) : '—'}</span>
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
                    <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Historique des échanges</h2>
                    <div className="mt-3 divide-y divide-line">
                        {customerExchanges.map((exchange) => (
                            <Link key={exchange.id} href={`/sales/exchanges/${exchange.id}`} className="grid gap-2 py-3 text-sm sm:grid-cols-[1fr_auto]">
                                <div>
                                    <p className="font-medium text-ink">{exchange.exchange_number} · {exchange.status}</p>
                                    <p className="text-xs text-ink-muted">Retour {exchange.return_number ?? '—'}{exchange.addendum_sequence ? ` · Complément #${exchange.addendum_sequence}` : ''}</p>
                                </div>
                                <div className="text-right text-xs text-ink-muted">
                                    <p>Retour : {formatMoney(exchange.returned_total, order.currency_code)}</p>
                                    <p>Nouveaux : {formatMoney(exchange.new_items_total, order.currency_code)}</p>
                                    <p className="font-semibold text-ink">Différence : {formatMoney(Math.abs(Number(exchange.difference_amount)), order.currency_code)}</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {/* Invoice history */}
            <section className="mb-6 rounded-card border border-line bg-surface p-5">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Factures liées</h2>
                {pendingReplacementInvoice && (
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-field border border-warning/30 bg-warning-soft/50 p-4">
                        <div>
                            <p className="font-semibold text-ink">{invoiceStaleAfterCompletion ? 'Commande complétée après cette facture' : 'Commande corrigée — nouvelle facture à générer'}</p>
                            <p className="mt-1 text-sm text-ink-muted">La facture émise précédemment reste l’état commercial historique jusqu’à l’émission de sa remplaçante.</p>
                        </div>
                        {order.status === 'confirmed' && can.createInvoice && (
                            <Button
                                loading={createInvoice.processing}
                                loadingText="Création…"
                                onClick={() => createInvoice.post(`/sales/orders/${order.id}/invoices`)}
                            >
                                Générer la nouvelle version
                            </Button>
                        )}
                    </div>
                )}
                {documents.invoices.length === 0 && !pendingReplacementInvoice && (
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-ink-muted">Aucune facture créée.</p>
                        {order.status === 'confirmed' && can.createInvoice && (
                            <Button loading={createInvoice.processing} loadingText="Création…" onClick={() => createInvoice.post(`/sales/orders/${order.id}/invoices`)}>
                                Créer une facture
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
                                                {invoice.invoice_number ? `Facture ${invoice.invoice_number}` : 'Facture brouillon'}
                                            </Link>
                                            <DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge>
                                        </div>
                                        <p className="mt-1 text-xs font-medium text-ink-muted">Version {invoice.version}</p>
                                        {(isPreviousCommercialState || isPreCompletionVersion) && <p className="mt-1 text-xs text-warning">Commande complétée après cette facture</p>}
                                        {invoice.status === 'issued' && !isPreviousCommercialState && !isPreCompletionVersion && <p className="mt-1 text-xs text-ink-muted">Facture actuelle</p>}
                                        {isCurrentRevisionDraft && <p className="mt-1 text-xs text-ink-muted">Nouvelle facture à vérifier avant émission</p>}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="tabular-nums text-ink-muted">{formatMoney(invoice.total_incl_tax, order.currency_code)}</span>
                                        <Link href={`/invoices/${invoice.id}`} className="inline-flex min-h-9 items-center rounded-field border border-line-strong bg-surface px-3 text-ink hover:bg-raised">
                                            {invoice.status === 'draft' ? 'Continuer' : 'Voir'}
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
                <h2 className="mb-2 text-sm font-semibold text-ink">Historique des paiements</h2>
                <div className="overflow-x-auto rounded-card border border-line bg-surface">
                    <table className="w-full min-w-[720px] text-left text-sm">
                        <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th className="px-4 py-3 font-medium">Date</th>
                                <th className="px-4 py-3 font-medium">Moyen</th>
                                <th className="px-4 py-3 font-medium">Compte</th>
                                <th className="px-4 py-3 font-medium">Référence</th>
                                <th className="px-4 py-3 text-right font-medium">Montant</th>
                                <th className="px-4 py-3 font-medium">Statut</th>
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
                                    <td className="px-4 py-3 text-right tabular-nums">
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
                                        Aucun paiement enregistré.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {refunds.length > 0 && (
                <section className="mb-6">
                    <h2 className="mb-2 text-sm font-semibold text-ink">Historique des remboursements</h2>
                    <div className="overflow-x-auto rounded-card border border-line bg-surface">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Date</th>
                                    <th className="px-4 py-3 font-medium">Remboursement</th>
                                    <th className="px-4 py-3 font-medium">Paiement original</th>
                                    <th className="px-4 py-3 font-medium">Moyen / compte</th>
                                    <th className="px-4 py-3 font-medium">Motif</th>
                                    <th className="px-4 py-3 text-right font-medium">Sortie</th>
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
                                        <td className="px-4 py-3 text-right font-medium tabular-nums text-danger">− {formatMoney(refund.amount, order.currency_code)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {/* Record payment (kept, secondary) */}
            {can.recordPayment && order.status === 'confirmed' && paymentSummary.remaining !== '0.0000' && (
                <details className="mb-6 rounded-card border border-line bg-surface p-5">
                    <summary className="cursor-pointer text-sm font-semibold text-ink">Enregistrer un paiement</summary>
                    <form onSubmit={recordPayments} className="mt-4 space-y-3">
                        {paymentForm.data.payments.map((entry, index) => (
                            <div key={index} className="grid gap-3 rounded-field bg-raised p-4 md:grid-cols-6">
                                <label className="text-sm">
                                    Moyen
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
                                        className="mt-1 w-full rounded-field border border-line-strong px-3 py-2"
                                    >
                                        <option value="cash">Espèces</option>
                                        <option value="card">TPE</option>
                                        <option value="bank_transfer">Virement</option>
                                        <option value="cheque">Chèque</option>
                                    </select>
                                </label>
                                <label className="text-sm md:col-span-2">
                                    Compte
                                    <select
                                        required
                                        value={entry.financial_account_id}
                                        onChange={(e) => setEntry(index, 'financial_account_id', e.target.value)}
                                        className="mt-1 w-full rounded-field border border-line-strong px-3 py-2"
                                    >
                                        <option value="">Choisir…</option>
                                        {financialAccounts
                                            .filter((account) => account.accepted_methods.includes(entry.method))
                                            .map((account) => (
                                                <option key={account.id} value={account.id}>
                                                    {account.code} · {account.name}
                                                </option>
                                            ))}
                                    </select>
                                    {financialAccounts.length > 0 && financialAccounts.every((account) => !account.accepted_methods.includes(entry.method)) && (
                                        <span className="mt-1 block text-xs text-warning">Aucun compte n’accepte ce moyen de paiement.</span>
                                    )}
                                </label>
                                <label className="text-sm">
                                    Montant
                                    <input
                                        required
                                        inputMode="decimal"
                                        value={entry.amount}
                                        onChange={(e) => setEntry(index, 'amount', e.target.value)}
                                        className="mt-1 w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </label>
                                <label className="text-sm">
                                    Date
                                    <input
                                        required
                                        type="date"
                                        readOnly={!can.backdatePayment}
                                        value={entry.payment_date}
                                        onChange={(e) => setEntry(index, 'payment_date', e.target.value)}
                                        className="mt-1 w-full rounded-field border border-line-strong px-3 py-2 read-only:bg-raised"
                                    />
                                </label>
                                <label className="text-sm">
                                    Référence
                                    <input
                                        maxLength={255}
                                        value={entry.reference}
                                        onChange={(e) => setEntry(index, 'reference', e.target.value)}
                                        className="mt-1 w-full rounded-field border border-line-strong px-3 py-2"
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
                                        Retirer
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
                                Ajouter un moyen
                            </button>
                            <Button
                                type="submit"
                                loading={paymentForm.processing}
                                loadingText="Enregistrement…"
                                disabled={financialAccounts.length === 0}
                            >
                                Enregistrer
                            </Button>
                        </div>
                        {financialAccounts.length === 0 && (
                            <p className="text-sm text-warning">
                                Créez un compte financier actif compatible avant d’enregistrer un paiement.
                            </p>
                        )}
                    </form>
                </details>
            )}

            {/* Delivery notes */}
            {(documents.deliveryNotes.length > 0 || canCreateDeliveryNoteForOrder) && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Bons de livraison</h2>
                    <ul className="mt-2 space-y-1 text-sm">
                        {documents.deliveryNotes.map((note) => (
                            <li key={note.id}>
                                <Link href={`/delivery-notes/${note.id}`} className="text-ink underline">
                                    {note.delivery_note_number ?? 'Brouillon'}
                                </Link>{' '}
                                <span className="text-ink-muted">· {label(deliveryNoteStatusLabel, note.status)}</span>
                            </li>
                        ))}
                        {documents.deliveryNotes.length === 0 && <li className="text-ink-muted">Aucun bon de livraison créé.</li>}
                    </ul>
                </section>
            )}

            {order.notes && (
                <section className="mb-6 rounded-card border border-line bg-surface p-5">
                    <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Notes</h2>
                    <p className="mt-2 whitespace-pre-line text-sm text-ink-muted">{order.notes}</p>
                </section>
            )}

            {/* Danger zone */}
            {can.cancel && order.status !== 'cancelled' && order.fulfillment_status === 'unfulfilled' && (
                <div className="mt-8 border-t border-line pt-4">
                    {!confirmCancel ? (
                        <button
                            type="button"
                            onClick={() => setConfirmCancel(true)}
                            className="text-sm text-danger hover:underline"
                        >
                            Annuler la commande
                        </button>
                    ) : (
                        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="presentation">
                        <form onSubmit={cancel} role="dialog" aria-modal="true" aria-labelledby="cancel-order-title" className="w-full max-w-lg space-y-4 rounded-card border border-line bg-surface p-5 shadow-xl">
                            <div>
                                <h2 id="cancel-order-title" className="text-lg font-semibold text-ink">Annuler la commande</h2>
                                <p className="mt-1 text-sm text-ink-muted">Commande {order.order_number} · Total {formatMoney(order.total_incl_tax, order.currency_code)}</p>
                                <p className="mt-1 text-sm text-ink-muted">État de livraison : {label(fulfillmentLabel, order.fulfillment_status)}</p>
                            </div>
                            {paymentSummary.collected !== '0.0000' && (
                                <div className="rounded-field border border-warning/30 bg-warning-soft/50 p-3 text-sm">
                                    <p>Montant encaissé : <strong>{formatMoney(paymentSummary.collected, order.currency_code)}</strong></p>
                                    <p>Montant à rembourser : <strong>{formatMoney(paymentSummary.net, order.currency_code)}</strong></p>
                                    <p className="mt-1 text-xs text-ink-muted">Le paiement original restera dans l’historique. Une sortie de remboursement distincte sera enregistrée sur le même compte.</p>
                                </div>
                            )}
                            <label htmlFor="cancellation-reason" className="block text-sm font-medium text-ink">Motif de l’annulation</label>
                            <textarea
                                id="cancellation-reason"
                                required
                                autoFocus
                                value={cancellation.data.reason}
                                onChange={(e) => cancellation.setData('reason', e.target.value)}
                                placeholder="Ex. Client a changé d’avis"
                                className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                            />
                            {cancellation.errors.reason && <p className="text-sm text-danger">{cancellation.errors.reason}</p>}
                            {(cancellation.errors as Record<string, string>).order && <p className="text-sm text-danger">{(cancellation.errors as Record<string, string>).order}</p>}
                            <div className="flex gap-2">
                                <Button type="submit" variant="danger" loading={cancellation.processing} loadingText="Annulation…">
                                    {paymentSummary.net !== '0.0000' ? 'Annuler et rembourser' : 'Confirmer l’annulation'}
                                </Button>
                                <button
                                    type="button"
                                    onClick={() => setConfirmCancel(false)}
                                    className="rounded-field px-4 py-2 text-sm text-ink-muted"
                                >
                                    Retour
                                </button>
                            </div>
                        </form>
                        </div>
                    )}
                </div>
            )}
        </SalesLayout>
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
    supplier_confirmed: 'bg-sky-50 text-sky-700',
    ordered: 'bg-indigo-50 text-indigo-700',
    received: 'bg-emerald-50 text-emerald-700',
    completed: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-slate-100 text-slate-500',
    unavailable: 'bg-rose-50 text-rose-700',
};

function OutOfStockArticlePanel({ order, canReport }: { order: Order; canReport: boolean }) {
    const unresolvedLines = order.lines.filter((l) => l.line_type === 'custom' && l.product_variant_id === null);
    if (unresolvedLines.length === 0) return null;

    const flag = (lineId: number) => router.post(`/sales/orders/${order.id}/out-of-stock-articles`, { sales_order_line_id: lineId }, { preserveScroll: true });

    return (
        <section className="mb-6 rounded-card border border-line bg-surface p-5">
            <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Articles hors catalogue</h2>
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
                                    {line.out_of_stock_article.status === 'resolved' ? 'Résolu' : 'Signalé — à traiter'}
                                </span>
                            ) : canReport ? (
                                <button
                                    type="button"
                                    onClick={() => flag(line.id)}
                                    className="rounded-field bg-primary px-3 py-1.5 text-xs font-medium text-primary-fg hover:bg-primary-hover"
                                >
                                    Signaler pour ajout au catalogue
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
    const [addLine, setAddLine] = useState<number | null>(null);
    const toProcureLines = data.lines.filter((l) => Number(l.to_procure) > 0);
    if (data.rows.length === 0 && toProcureLines.length === 0 && !data.awaiting) return null;

    return (
        <section className="mb-6 rounded-card border border-line bg-surface p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Approvisionnement fournisseur</h2>
                {data.awaiting && (
                    <span className="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning">
                        À approvisionner fournisseur
                    </span>
                )}
            </div>

            {/* Draft coverage breakdown + raise action */}
            {order.status === 'draft' && data.can.manage && toProcureLines.length > 0 && (
                <div className="mt-3 space-y-2">
                    {data.suppliers.length === 0 && (
                        <p className="rounded-field bg-warning-soft/50 px-3 py-2 text-xs text-warning">
                            Aucun fournisseur actif. Créez-en un dans Achats → Fournisseurs avant d’approvisionner.
                        </p>
                    )}
                    {toProcureLines.map((line) => (
                        <div key={line.id} className="rounded-field border border-line bg-raised p-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium text-ink">{line.product}</span>
                                <span className="text-xs text-ink-muted">
                                    Demandé {formatQuantity(line.requested)} · Stock société {formatQuantity(line.company_available)} ·
                                    À approvisionner <strong className="text-ink">{formatQuantity(line.to_procure)}</strong>
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
                                        Approvisionner auprès d’un fournisseur
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
                                    {row.status_label}
                                </span>
                            </div>
                            <div className="mt-1 text-xs text-ink-muted">
                                {row.supplier?.name ?? '—'} · Disponibilité : {row.supplier_availability_label}
                                {row.supplier_reference ? ` · Réf ${row.supplier_reference}` : ''}
                                {row.expected_at ? ` · Réception estimée ${formatDate(row.expected_at)}` : ''}
                                {row.ordered_at ? ` · Commandé ${formatDate(row.ordered_at)}` : ''}
                                {row.received_at
                                    ? ` · Reçu ${formatDate(row.received_at)} à ${row.receiving_warehouse?.name ?? '—'}`
                                    : ''}
                            </div>
                            {row.transfer_request && (
                                <p className="mt-1 text-xs text-ink-faint">
                                    Transfert interne{' '}
                                    <Link
                                        href={`/inventory/transfer-requests/${row.transfer_request.id}`}
                                        className="underline"
                                    >
                                        {row.transfer_request.request_number}
                                    </Link>{' '}
                                    vers le showroom.
                                </p>
                            )}
                            {row.cancellation_reason && (
                                <p className="mt-1 text-xs text-ink-faint">Motif : {row.cancellation_reason}</p>
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
                Fournisseur
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
                Quantité
                <input
                    inputMode="decimal"
                    value={form.data.quantity}
                    onChange={(e) => form.setData('quantity', e.target.value)}
                    className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                />
            </label>
            <label className="text-xs">
                Référence fournisseur (opt.)
                <input
                    value={form.data.supplier_reference}
                    onChange={(e) => form.setData('supplier_reference', e.target.value)}
                    className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                />
            </label>
            <label className="text-xs">
                Réception estimée (opt.)
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
                    Créer l’approvisionnement
                </button>
                <button type="button" onClick={onDone} className="rounded-field px-3 py-1.5 text-xs text-ink-muted">
                    Annuler
                </button>
            </div>
        </form>
    );
}

function ProcurementRowActions({ row, data }: { row: ProcurementRow; data: ProcurementData }) {
    const [open, setOpen] = useState<'availability' | 'order' | 'receive' | 'supplier' | 'cancel' | null>(null);
    const availability = useForm({ supplier_availability_status: 'confirmed_available', quantity: row.quantity, supplier_reference: row.supplier_reference ?? '', expected_at: row.expected_at ?? '' });
    const orderForm = useForm({ supplier_reference: row.supplier_reference ?? '', expected_at: row.expected_at ?? '' });
    const receive = useForm({ receiving_warehouse_id: data.warehouses[0]?.id ?? 0, quantity: row.quantity });
    const supplierForm = useForm({ supplier_id: data.suppliers[0]?.id ?? 0 });
    const cancelForm = useForm({ reason: '', acknowledge_ordered: false });
    const done = { preserveScroll: true, onSuccess: () => setOpen(null) };

    const canManage = data.can.manage;
    const canReceive = data.can.receive;
    const isEditable = row.status === 'pending_supplier' || row.status === 'supplier_confirmed';

    if (row.status === 'cancelled' || row.status === 'unavailable' || row.status === 'received' || row.status === 'completed') {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {canManage && isEditable && (
                <ActionButton onClick={() => setOpen(open === 'availability' ? null : 'availability')}>
                    Enregistrer la disponibilité
                </ActionButton>
            )}
            {canManage && row.status === 'supplier_confirmed' && (
                <ActionButton onClick={() => setOpen(open === 'order' ? null : 'order')} primary>
                    Commander au fournisseur
                </ActionButton>
            )}
            {canReceive && row.status === 'ordered' && (
                <ActionButton onClick={() => setOpen(open === 'receive' ? null : 'receive')} primary>
                    Confirmer la réception
                </ActionButton>
            )}
            {canManage && isEditable && data.suppliers.length > 0 && (
                <ActionButton onClick={() => setOpen(open === 'supplier' ? null : 'supplier')}>Changer de fournisseur</ActionButton>
            )}
            {canManage && (row.status === 'pending_supplier' || row.status === 'supplier_confirmed' || row.status === 'ordered') && (
                <ActionButton onClick={() => setOpen(open === 'cancel' ? null : 'cancel')} danger>
                    Annuler
                </ActionButton>
            )}

            {open === 'availability' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        availability.patch(`/procurement/${row.id}/availability`, done);
                    }}
                    className="mt-1 w-full grid gap-2 rounded-field bg-raised p-3 sm:grid-cols-2"
                >
                    <label className="text-xs">
                        Disponibilité
                        <select
                            value={availability.data.supplier_availability_status}
                            onChange={(e) => availability.setData('supplier_availability_status', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        >
                            <option value="confirmed_available">Confirmée disponible</option>
                            <option value="pending_confirmation">À confirmer</option>
                            <option value="unavailable">Indisponible</option>
                        </select>
                    </label>
                    <label className="text-xs">
                        Quantité confirmée
                        <input
                            inputMode="decimal"
                            value={availability.data.quantity}
                            onChange={(e) => availability.setData('quantity', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        />
                    </label>
                    <label className="text-xs">
                        Référence fournisseur
                        <input
                            value={availability.data.supplier_reference}
                            onChange={(e) => availability.setData('supplier_reference', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        />
                    </label>
                    <label className="text-xs">
                        Réception estimée
                        <input
                            type="date"
                            value={availability.data.expected_at}
                            onChange={(e) => availability.setData('expected_at', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        />
                    </label>
                    <FormErrors errors={availability.errors} />
                    <SubmitRow processing={availability.processing} onCancel={() => setOpen(null)} label="Enregistrer" />
                </form>
            )}

            {open === 'order' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        orderForm.post(`/procurement/${row.id}/order`, done);
                    }}
                    className="mt-1 w-full grid gap-2 rounded-field bg-raised p-3 sm:grid-cols-2"
                >
                    <label className="text-xs">
                        Référence fournisseur
                        <input
                            value={orderForm.data.supplier_reference}
                            onChange={(e) => orderForm.setData('supplier_reference', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        />
                    </label>
                    <label className="text-xs">
                        Réception estimée
                        <input
                            type="date"
                            value={orderForm.data.expected_at}
                            onChange={(e) => orderForm.setData('expected_at', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        />
                    </label>
                    <FormErrors errors={orderForm.errors} />
                    <SubmitRow processing={orderForm.processing} onCancel={() => setOpen(null)} label="Confirmer la commande" />
                </form>
            )}

            {open === 'receive' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        receive.post(`/procurement/${row.id}/receive`, done);
                    }}
                    className="mt-1 w-full grid gap-2 rounded-field bg-raised p-3 sm:grid-cols-2"
                >
                    <label className="text-xs">
                        Entrepôt de réception
                        <select
                            value={receive.data.receiving_warehouse_id}
                            onChange={(e) => receive.setData('receiving_warehouse_id', Number(e.target.value))}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        >
                            {data.warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name} · {w.code}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs">
                        Reçu (qté)
                        <input
                            inputMode="decimal"
                            value={receive.data.quantity}
                            onChange={(e) => receive.setData('quantity', e.target.value)}
                            className="mt-1 w-full rounded-field border border-line-strong px-2 py-1.5 text-sm"
                        />
                    </label>
                    <p className="text-xs text-ink-faint sm:col-span-2">
                        La marchandise entre en stock puis est immédiatement réservée à cette commande. Si l’entrepôt choisi n’est
                        pas le showroom de la commande, une demande de transfert interne est créée automatiquement.
                    </p>
                    <FormErrors errors={receive.errors} />
                    <SubmitRow processing={receive.processing} onCancel={() => setOpen(null)} label="Confirmer la réception" />
                </form>
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
                        Nouveau fournisseur
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
                    <SubmitRow processing={supplierForm.processing} onCancel={() => setOpen(null)} label="Remplacer" />
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
                        Motif
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
                            Je confirme abandonner la commande fournisseur déjà passée.
                        </label>
                    )}
                    <FormErrors errors={cancelForm.errors} />
                    <SubmitRow processing={cancelForm.processing} onCancel={() => setOpen(null)} label="Annuler l’approvisionnement" danger />
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
                Fermer
            </button>
        </div>
    );
}
