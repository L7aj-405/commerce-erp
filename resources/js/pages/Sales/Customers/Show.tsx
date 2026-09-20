import DocBadge from '@/components/ui/DocBadge';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney } from '@/utils/format';
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
} from '@/utils/labels';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Customer = {
    id: number;
    type: string;
    display_name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    tax_identifier: string | null;
    billing_address: string | null;
    status: string;
};
type Summary = { sales_total: string; invoiced_total: string; paid_total: string; outstanding: string; last_activity: string | null };
type OrderRow = { id: number; order_number: string; sale_date: string; status: string; payment_status: string; total_incl_tax: string };
type InvoiceRow = { id: number; invoice_number: string | null; version: number; invoice_date: string; status: string; total_incl_tax: string };
type PaymentRow = { id: number; payment_number: string; payment_date: string; method: string; status: string; amount: string };
type QuotationRow = { id: number; quotation_number: string | null; quotation_date: string; status: string; total_incl_tax: string };
type DeliveryNoteRow = { id: number; delivery_note_number: string | null; delivery_date: string; status: string };
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
};

function SummaryTile({ label: title, value, tone = 'neutral' }: { label: string; value: ReactNode; tone?: 'neutral' | 'warning' }) {
    return (
        <div className="rounded-card border border-line bg-surface px-4 py-3.5">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{title}</p>
            <p className={`mt-1.5 text-lg font-semibold ${tone === 'warning' ? 'text-warning' : 'text-ink'}`}>{value}</p>
        </div>
    );
}

function Section({ id, title, count, children }: { id: string; title: string; count: number; children: ReactNode }) {
    return (
        <section id={id} className="mb-6 scroll-mt-4 rounded-card border border-line bg-surface">
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 className="text-sm font-semibold text-ink">{title}</h2>
                <span className="text-[12px] text-ink-faint">{count} au total{count > 15 ? ' · 15 plus récent(e)s affiché(e)s' : ''}</span>
            </div>
            {children}
        </section>
    );
}

const DASH_LABEL: Record<string, string> = { commandes: 'Commandes', factures: 'Factures', paiements: 'Paiements', devis: 'Devis', 'bons-de-livraison': 'Bons de livraison' };

export default function CustomerShow({ customer, summary, orders, ordersCount, invoices, invoicesCount, payments, paymentsCount, quotations, quotationsCount, deliveryNotes, deliveryNotesCount, can }: Props) {
    const title = customer.type === 'company' ? (customer.company_name || customer.display_name) : customer.display_name;

    return (
        <SalesLayout>
            <Head title={title} />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link href="/sales/customers" className="text-sm text-ink-muted">
                        ← Clients
                    </Link>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">{title}</h1>
                        <DocBadge tone={customer.status === 'active' ? 'positive' : 'neutral'}>{customer.status === 'active' ? 'Actif' : 'Inactif'}</DocBadge>
                        <span className="text-[12px] text-ink-faint">{customer.type === 'company' ? 'Entreprise' : 'Particulier'}</span>
                    </div>
                    <p className="mt-1.5 text-sm text-ink-muted">
                        {[customer.phone, customer.email].filter(Boolean).join(' · ') || 'Aucun contact renseigné'}
                    </p>
                    {customer.billing_address && <p className="mt-0.5 text-sm text-ink-muted">{customer.billing_address}</p>}
                    {customer.tax_identifier && <p className="mt-0.5 text-[12px] text-ink-faint">ICE : {customer.tax_identifier}</p>}
                </div>
                {can.update && (
                    <Link
                        href={`/sales/customers/${customer.id}/edit`}
                        className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink transition-soft hover:bg-raised"
                    >
                        Modifier la fiche
                    </Link>
                )}
            </div>

            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <SummaryTile label="Ventes confirmées" value={formatMoney(summary.sales_total)} />
                <SummaryTile label="Facturé" value={formatMoney(summary.invoiced_total)} />
                <SummaryTile label="Encaissé" value={formatMoney(summary.paid_total)} />
                <SummaryTile label="Reste à payer" value={formatMoney(summary.outstanding)} tone={Number(summary.outstanding) > 0 ? 'warning' : 'neutral'} />
                <SummaryTile label="Dernière activité" value={summary.last_activity ? formatDate(summary.last_activity) : '—'} />
            </div>

            <div className="mb-6 flex flex-wrap gap-2 text-[13px]">
                {Object.entries(DASH_LABEL).map(([id, l]) => (
                    <a key={id} href={`#${id}`} className="rounded-full border border-line-strong px-3 py-1 text-ink-muted transition-soft hover:bg-raised hover:text-ink">
                        {l}
                    </a>
                ))}
            </div>

            <Section id="commandes" title="Commandes" count={ordersCount}>
                {orders.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">Aucune commande pour ce client.</p>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">Date</th>
                                <th className="px-4 py-2">Statut</th>
                                <th className="px-4 py-2">Paiement</th>
                                <th className="px-4 py-2 text-right">Total TTC</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.map((order) => (
                                <tr key={order.id} className="border-t border-line">
                                    <td className="px-4 py-2.5 font-medium text-ink"><Link href={`/sales/orders/${order.id}`}>{order.order_number}</Link></td>
                                    <td className="px-4 py-2.5 text-ink-muted">{formatDate(order.sale_date)}</td>
                                    <td className="px-4 py-2.5"><DocBadge tone={orderStatusTone(order.status)}>{label(orderStatusLabel, order.status)}</DocBadge></td>
                                    <td className="px-4 py-2.5"><DocBadge tone={paymentStatusTone(order.payment_status)}>{label(paymentStatusLabel, order.payment_status)}</DocBadge></td>
                                    <td className="px-4 py-2.5 text-right">{formatMoney(order.total_incl_tax)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section id="factures" title="Factures" count={invoicesCount}>
                {invoices.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">Aucune facture pour ce client.</p>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">Date</th>
                                <th className="px-4 py-2">Statut</th>
                                <th className="px-4 py-2 text-right">Total TTC</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.map((invoice) => (
                                <tr key={invoice.id} className="border-t border-line">
                                    <td className="px-4 py-2.5 font-medium text-ink"><Link href={`/invoices/${invoice.id}`}>{invoice.invoice_number ?? `Brouillon #${invoice.id}`} · V{invoice.version}</Link></td>
                                    <td className="px-4 py-2.5 text-ink-muted">{formatDate(invoice.invoice_date)}</td>
                                    <td className="px-4 py-2.5"><DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge></td>
                                    <td className="px-4 py-2.5 text-right">{formatMoney(invoice.total_incl_tax)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section id="paiements" title="Paiements" count={paymentsCount}>
                {payments.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">Aucun paiement pour ce client.</p>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">Date</th>
                                <th className="px-4 py-2">Moyen</th>
                                <th className="px-4 py-2">Statut</th>
                                <th className="px-4 py-2 text-right">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((payment) => (
                                <tr key={payment.id} className="border-t border-line">
                                    <td className="px-4 py-2.5 font-medium text-ink"><Link href={`/payments/${payment.id}`}>{payment.payment_number}</Link></td>
                                    <td className="px-4 py-2.5 text-ink-muted">{formatDate(payment.payment_date)}</td>
                                    <td className="px-4 py-2.5 text-ink-muted">{label(paymentMethodLabel, payment.method)}</td>
                                    <td className="px-4 py-2.5"><DocBadge tone={payment.status === 'posted' ? 'positive' : 'danger'}>{label(paymentEntryStatusLabel, payment.status)}</DocBadge></td>
                                    <td className="px-4 py-2.5 text-right">{formatMoney(payment.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section id="devis" title="Devis" count={quotationsCount}>
                {quotations.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">Aucun devis pour ce client.</p>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">Date</th>
                                <th className="px-4 py-2">Statut</th>
                                <th className="px-4 py-2 text-right">Total TTC</th>
                            </tr>
                        </thead>
                        <tbody>
                            {quotations.map((quotation) => (
                                <tr key={quotation.id} className="border-t border-line">
                                    <td className="px-4 py-2.5 font-medium text-ink"><Link href={`/quotations/${quotation.id}`}>{quotation.quotation_number ?? `Brouillon #${quotation.id}`}</Link></td>
                                    <td className="px-4 py-2.5 text-ink-muted">{formatDate(quotation.quotation_date)}</td>
                                    <td className="px-4 py-2.5"><DocBadge tone={quotationStatusTone(quotation.status)}>{label(quotationStatusLabel, quotation.status)}</DocBadge></td>
                                    <td className="px-4 py-2.5 text-right">{formatMoney(quotation.total_incl_tax)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>

            <Section id="bons-de-livraison" title="Bons de livraison" count={deliveryNotesCount}>
                {deliveryNotes.length === 0 ? (
                    <p className="px-4 py-6 text-center text-[13px] text-ink-faint">Aucun bon de livraison pour ce client.</p>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2">N°</th>
                                <th className="px-4 py-2">Date</th>
                                <th className="px-4 py-2">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            {deliveryNotes.map((note) => (
                                <tr key={note.id} className="border-t border-line">
                                    <td className="px-4 py-2.5 font-medium text-ink"><Link href={`/delivery-notes/${note.id}`}>{note.delivery_note_number ?? `Brouillon #${note.id}`}</Link></td>
                                    <td className="px-4 py-2.5 text-ink-muted">{formatDate(note.delivery_date)}</td>
                                    <td className="px-4 py-2.5"><DocBadge tone={deliveryNoteStatusTone(note.status)}>{label(deliveryNoteStatusLabel, note.status)}</DocBadge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Section>
        </SalesLayout>
    );
}
