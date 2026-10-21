import DocBadge from "@/components/ui/DocBadge";
import SalesLayout from "@/layouts/SalesLayout";
import { useI18n } from "@/i18n";
import { formatDate, formatMoney } from "@/utils/format";
import {
    deliveryNoteStatusLabel,
    deliveryNoteStatusTone,
    invoiceStatusLabel,
    invoiceStatusTone,
    label,
    orderStatusLabel,
    orderStatusTone,
    paymentEntryStatusLabel,
    paymentMethodLabel,
    paymentStatusLabel,
    paymentStatusTone,
    quotationStatusLabel,
    quotationStatusTone,
} from "@/utils/labels";
import { Head, Link } from "@inertiajs/react";
import type { ReactNode } from "react";
import type { WhatsAppDestination } from "@/utils/whatsapp";

type Customer = {
    id: number;
    type: string;
    display_name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    whatsapp_username: string | null;
    tax_identifier: string | null;
    billing_address: string | null;
    status: string;
};
type Summary = {
    sales_total: string;
    invoiced_total: string;
    paid_total: string;
    outstanding: string;
    last_activity: string | null;
};
type OrderRow = {
    id: number;
    order_number: string;
    sale_date: string;
    status: string;
    payment_status: string;
    total_incl_tax: string;
};
type InvoiceRow = {
    id: number;
    invoice_number: string | null;
    version: number;
    invoice_date: string;
    status: string;
    total_incl_tax: string;
};
type PaymentRow = {
    id: number;
    payment_number: string;
    payment_date: string;
    method: string;
    status: string;
    amount: string;
};
type QuotationRow = {
    id: number;
    quotation_number: string | null;
    quotation_date: string;
    status: string;
    total_incl_tax: string;
};
type DeliveryNoteRow = {
    id: number;
    delivery_note_number: string | null;
    delivery_date: string;
    status: string;
};
type Props = {
    customer: Customer;
    summary: Summary;
    orders: OrderRow[];
    ordersCount: number;
    invoices: InvoiceRow[];
    invoicesCount: number;
    payments: PaymentRow[];
    paymentsCount: number;
    quotations: QuotationRow[];
    quotationsCount: number;
    deliveryNotes: DeliveryNoteRow[];
    deliveryNotesCount: number;
    can: { update: boolean };
    whatsappDestination: WhatsAppDestination;
};

function SummaryTile({
    label: title,
    value,
    tone = "neutral",
}: {
    label: string;
    value: ReactNode;
    tone?: "neutral" | "warning";
}) {
    return (
        <div className="rounded-card border border-line bg-surface px-4 py-3.5">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                {title}
            </p>
            <p
                className={`mt-1.5 text-lg font-semibold ${tone === "warning" ? "text-warning" : "text-ink"}`}
            >
                {value}
            </p>
        </div>
    );
}

function Section({
    id,
    title,
    count,
    children,
}: {
    id: string;
    title: string;
    count: number;
    children: ReactNode;
}) {
    const { t } = useI18n();
    return (
        <section
            id={id}
            className="mb-6 scroll-mt-4 rounded-card border border-line bg-surface"
        >
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 className="text-sm font-semibold text-ink">{title}</h2>
                <span className="text-[12px] text-ink-faint">
                    {t("customers.show.totalCount").replace(
                        "{count}",
                        String(count),
                    )}
                    {count > 15 ? ` · ${t("customers.show.latestShown")}` : ""}
                </span>
            </div>
            {children}
        </section>
    );
}

export default function CustomerShow({
    customer,
    summary,
    orders,
    ordersCount,
    invoices,
    invoicesCount,
    payments,
    paymentsCount,
    quotations,
    quotationsCount,
    deliveryNotes,
    deliveryNotesCount,
    can,
    whatsappDestination,
}: Props) {
    const { t } = useI18n();
    const title =
        customer.type === "company"
            ? customer.company_name || customer.display_name
            : customer.display_name;

    return (
        <SalesLayout>
            <Head title={title} />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link
                        href="/sales/customers"
                        className="text-sm text-ink-muted"
                    >
                        ← {t("customers.title")}
                    </Link>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">
                            {title}
                        </h1>
                        <DocBadge
                            tone={
                                customer.status === "active"
                                    ? "positive"
                                    : "neutral"
                            }
                        >
                            {customer.status === "active"
                                ? t("status.active")
                                : t("status.inactive")}
                        </DocBadge>
                        <span className="text-[12px] text-ink-faint">
                            {customer.type === "company"
                                ? t("customers.company")
                                : t("customers.individual")}
                        </span>
                    </div>
                    <p className="mt-1.5 text-sm text-ink-muted">
                        {[
                            customer.phone,
                            customer.whatsapp_username
                                ? `@${customer.whatsapp_username}`
                                : null,
                            customer.email,
                        ]
                            .filter(Boolean)
                            .join(" · ") || t("customers.show.noContact")}
                    </p>
                    {customer.billing_address && (
                        <p className="mt-0.5 text-sm text-ink-muted">
                            {customer.billing_address}
                        </p>
                    )}
                    {customer.tax_identifier && (
                        <p className="mt-0.5 text-[12px] text-ink-faint">
                            ICE : {customer.tax_identifier}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    {whatsappDestination.available &&
                        whatsappDestination.link && (
                            <a
                                href={whatsappDestination.link}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink transition-soft hover:bg-raised"
                            >
                                {t("customers.show.openWhatsApp")}
                            </a>
                        )}
                    {can.update && (
                        <Link
                            href={`/sales/customers/${customer.id}/edit`}
                            className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink transition-soft hover:bg-raised"
                        >
                            {t("customers.show.editRecord")}
                        </Link>
                    )}
                </div>
            </div>

            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <SummaryTile
                    label={t("customers.show.confirmedSales")}
                    value={formatMoney(summary.sales_total)}
                />
                <SummaryTile
                    label={t("customers.show.invoiced")}
                    value={formatMoney(summary.invoiced_total)}
                />
                <SummaryTile
                    label={t("customers.show.collected")}
                    value={formatMoney(summary.paid_total)}
                />
                <SummaryTile
                    label={t("orders.show.remaining")}
                    value={formatMoney(summary.outstanding)}
                    tone={
                        Number(summary.outstanding) > 0 ? "warning" : "neutral"
                    }
                />
                <SummaryTile
                    label={t("customers.show.lastActivity")}
                    value={
                        summary.last_activity
                            ? formatDate(summary.last_activity)
                            : "—"
                    }
                />
            </div>

            <div className="mb-6 flex flex-wrap gap-2 text-[13px]">
                {(
                    [
                        ["commandes", t("commissions.dashboard.orders")],
                        ["factures", t("customers.show.invoices")],
                        ["paiements", t("customers.show.payments")],
                        ["devis", t("customers.show.quotations")],
                        [
                            "bons-de-livraison",
                            t("customers.show.deliveryNotes"),
                        ],
                    ] as const
                ).map(([id, l]) => (
                    <a
                        key={id}
                        href={`#${id}`}
                        className="rounded-full border border-line-strong px-3 py-1 text-ink-muted transition-soft hover:bg-raised hover:text-ink"
                    >
                        {l}
                    </a>
                ))}
            </div>

            <Section
                id="commandes"
                title={t("commissions.dashboard.orders")}
                count={ordersCount}
            >
                {orders.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">
                        {t("customers.show.noOrders")}
                    </p>
                ) : (
                    <table className="w-full text-start text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">
                                    {t("common.date")}
                                </th>
                                <th className="px-4 py-2">
                                    {t("common.status")}
                                </th>
                                <th className="px-4 py-2">
                                    {t("customers.show.payment")}
                                </th>
                                <th className="px-4 py-2 text-end">
                                    {t("orders.show.totalInclTax")}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.map((order) => (
                                <tr
                                    key={order.id}
                                    className="border-t border-line"
                                >
                                    <td className="px-4 py-2.5 font-medium text-ink">
                                        <Link
                                            href={`/sales/orders/${order.id}`}
                                        >
                                            {order.order_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">
                                        {formatDate(order.sale_date)}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <DocBadge
                                            tone={orderStatusTone(order.status)}
                                        >
                                            {label(
                                                orderStatusLabel,
                                                order.status,
                                            )}
                                        </DocBadge>
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <DocBadge
                                            tone={paymentStatusTone(
                                                order.payment_status,
                                            )}
                                        >
                                            {label(
                                                paymentStatusLabel,
                                                order.payment_status,
                                            )}
                                        </DocBadge>
                                    </td>
                                    <td className="px-4 py-2.5 text-end">
                                        {formatMoney(order.total_incl_tax)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section
                id="factures"
                title={t("customers.show.invoices")}
                count={invoicesCount}
            >
                {invoices.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">
                        {t("customers.show.noInvoices")}
                    </p>
                ) : (
                    <table className="w-full text-start text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">
                                    {t("common.date")}
                                </th>
                                <th className="px-4 py-2">
                                    {t("common.status")}
                                </th>
                                <th className="px-4 py-2 text-end">
                                    {t("orders.show.totalInclTax")}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.map((invoice) => (
                                <tr
                                    key={invoice.id}
                                    className="border-t border-line"
                                >
                                    <td className="px-4 py-2.5 font-medium text-ink">
                                        <Link href={`/invoices/${invoice.id}`}>
                                            <bdi>
                                                {invoice.invoice_number ??
                                                    `${t("common.draft")} #${invoice.id}`}{" "}
                                                · V{invoice.version}
                                            </bdi>
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">
                                        {formatDate(invoice.invoice_date)}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <DocBadge
                                            tone={invoiceStatusTone(
                                                invoice.status,
                                            )}
                                        >
                                            {label(
                                                invoiceStatusLabel,
                                                invoice.status,
                                            )}
                                        </DocBadge>
                                    </td>
                                    <td className="px-4 py-2.5 text-end">
                                        {formatMoney(invoice.total_incl_tax)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section
                id="paiements"
                title={t("customers.show.payments")}
                count={paymentsCount}
            >
                {payments.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">
                        {t("customers.show.noPayments")}
                    </p>
                ) : (
                    <table className="w-full text-start text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">
                                    {t("common.date")}
                                </th>
                                <th className="px-4 py-2">
                                    {t("customers.show.method")}
                                </th>
                                <th className="px-4 py-2">
                                    {t("common.status")}
                                </th>
                                <th className="px-4 py-2 text-end">
                                    {t("common.amount")}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((payment) => (
                                <tr
                                    key={payment.id}
                                    className="border-t border-line"
                                >
                                    <td className="px-4 py-2.5 font-medium text-ink">
                                        <Link href={`/payments/${payment.id}`}>
                                            {payment.payment_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">
                                        {formatDate(payment.payment_date)}
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">
                                        {label(
                                            paymentMethodLabel,
                                            payment.method,
                                        )}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <DocBadge
                                            tone={
                                                payment.status === "posted"
                                                    ? "positive"
                                                    : "danger"
                                            }
                                        >
                                            {label(
                                                paymentEntryStatusLabel,
                                                payment.status,
                                            )}
                                        </DocBadge>
                                    </td>
                                    <td className="px-4 py-2.5 text-end">
                                        {formatMoney(payment.amount)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section
                id="devis"
                title={t("customers.show.quotations")}
                count={quotationsCount}
            >
                {quotations.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">
                        {t("customers.show.noQuotations")}
                    </p>
                ) : (
                    <table className="w-full text-start text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">
                                    {t("common.date")}
                                </th>
                                <th className="px-4 py-2">
                                    {t("common.status")}
                                </th>
                                <th className="px-4 py-2 text-end">
                                    {t("orders.show.totalInclTax")}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {quotations.map((quotation) => (
                                <tr
                                    key={quotation.id}
                                    className="border-t border-line"
                                >
                                    <td className="px-4 py-2.5 font-medium text-ink">
                                        <Link
                                            href={`/quotations/${quotation.id}`}
                                        >
                                            <bdi>
                                                {quotation.quotation_number ??
                                                    `${t("common.draft")} #${quotation.id}`}
                                            </bdi>
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">
                                        {formatDate(quotation.quotation_date)}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <DocBadge
                                            tone={quotationStatusTone(
                                                quotation.status,
                                            )}
                                        >
                                            {label(
                                                quotationStatusLabel,
                                                quotation.status,
                                            )}
                                        </DocBadge>
                                    </td>
                                    <td className="px-4 py-2.5 text-end">
                                        {formatMoney(quotation.total_incl_tax)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section
                id="bons-de-livraison"
                title={t("customers.show.deliveryNotes")}
                count={deliveryNotesCount}
            >
                {deliveryNotes.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">
                        {t("customers.show.noDeliveryNotes")}
                    </p>
                ) : (
                    <table className="w-full text-start text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">
                                    {t("common.date")}
                                </th>
                                <th className="px-4 py-2">
                                    {t("common.status")}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {deliveryNotes.map((note) => (
                                <tr
                                    key={note.id}
                                    className="border-t border-line"
                                >
                                    <td className="px-4 py-2.5 font-medium text-ink">
                                        <Link
                                            href={`/delivery-notes/${note.id}`}
                                        >
                                            <bdi>
                                                {note.delivery_note_number ??
                                                    `${t("common.draft")} #${note.id}`}
                                            </bdi>
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">
                                        {formatDate(note.delivery_date)}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <DocBadge
                                            tone={deliveryNoteStatusTone(
                                                note.status,
                                            )}
                                        >
                                            {label(
                                                deliveryNoteStatusLabel,
                                                note.status,
                                            )}
                                        </DocBadge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>
        </SalesLayout>
    );
}
