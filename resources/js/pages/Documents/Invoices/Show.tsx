import DocBadge from '@/components/ui/DocBadge';
import { Button } from '@/components/ui/Button';
import SendDocumentEmailModal from '@/components/documents/SendDocumentEmailModal';
import StampDocumentAction from '@/components/documents/StampDocumentAction';
import { useI18n } from '@/i18n';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import { invoiceStatusLabel, invoiceStatusTone, label } from '@/utils/labels';
import { whatsappLink, type WhatsAppDestination } from '@/utils/whatsapp';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Line = {
    id: number;
    product_variant_id: number | null;
    description: string;
    product_name: string | null;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
    unit_label: string | null;
    quantity: string;
    unit_price_excl_tax: string;
    discount_type: 'none' | 'fixed' | 'percentage';
    discount_value: string;
    subtotal_excl_tax: string;
    discount_amount: string;
    taxable_amount: string;
    tax_name: string | null;
    tax_rate: string;
    tax_amount: string;
    total_incl_tax: string;
};
type TotalsSnapshot = {
    subtotal_excl_tax: string;
    discount_total: string;
    tax_total: string;
    total_incl_tax: string;
};
type CorrectionComparison = {
    original: TotalsSnapshot;
    correction: TotalsSnapshot;
    delta_incl_tax: string;
};
type Seller = {
    legal_name?: string;
    trade_name?: string;
    address?: string;
    phone?: string;
    fax?: string;
    email?: string;
    tax_identifier?: string;
};
type Invoice = {
    id: number;
    invoice_number: string | null;
    version: number;
    status: string;
    invoice_date: string;
    customer_name: string | null;
    customer_company: string | null;
    customer_email: string | null;
    customer_phone: string | null;
    customer_tax_identifier: string | null;
    billing_address: string | null;
    representative_name: string | null;
    payment_method_summary: string | null;
    notes: string | null;
    subtotal_excl_tax: string;
    discount_total: string;
    tax_total: string;
    total_incl_tax: string;
    currency_code: string;
    issued_at: string | null;
    cancellation_reason: string | null;
    correction_reason: string | null;
    seller_snapshot: Seller | null;
    store: { name: string; code: string };
    sales_order: { id: number; order_number: string };
    lines: Line[];
    issued_by: { name: string } | null;
};
type Sharing = {
    pdfUrl: string;
    email: string | null;
    phone: string | null;
    whatsappDestination: WhatsAppDestination;
    whatsappMessage: string;
    defaultSubject: string;
    attachmentName: string;
};
type HistoryEntry = {
    id: number;
    invoice_number: string | null;
    version: number;
    status: string;
    issued_at: string | null;
    reason: string | null;
    is_current: boolean;
};
type Props = {
    invoice: Invoice;
    hasDiscount: boolean;
    accentColor: string;
    previewUrl: string;
    isCorrection: boolean;
    isReplacement: boolean;
    correctionComparison: CorrectionComparison | null;
    history: HistoryEntry[];
    isCurrentVersion: boolean;
    currentVersion: HistoryEntry | null;
    orderExpandedAfterInvoice: boolean;
    relatedOrderPaymentSummary: { paid: string; collected: string; refunded: string; net: string; remaining: string; status: string };
    creditNotes: { id: number; credit_note_number: string; credit_note_date: string; total_incl_tax: string }[];
    creditSummary: { credited: string; net: string; receivable: string; refund_obligation: string; state: 'none' | 'partial' | 'full' };
    sharing: Sharing | null;
    mailConfigured: boolean;
    stamp: { applied: boolean; appliedAt: string | null; unavailable: boolean };
    can: {
        updateDraft: boolean;
        issue: boolean;
        backdate: boolean;
        email: boolean;
        configureMail: boolean;
        stamp: boolean;
        viewOrder: boolean;
    };
};

export default function InvoiceShow({
    invoice,
    hasDiscount,
    accentColor,
    previewUrl,
    isCorrection,
    isReplacement,
    correctionComparison,
    history,
    isCurrentVersion,
    currentVersion,
    orderExpandedAfterInvoice,
    relatedOrderPaymentSummary,
    creditNotes,
    creditSummary,
    sharing,
    mailConfigured,
    stamp,
    can,
}: Props) {
    const { direction, t } = useI18n();
    const isDraft = invoice.status === 'draft';
    const isIssued = invoice.status === 'issued';
    const isSuperseded = invoice.status === 'superseded';
    const currency = invoice.currency_code;
    const seller = invoice.seller_snapshot ?? {};
    const [confirmCancel, setConfirmCancel] = useState(false);
    const previousVersion = history.find((entry) => entry.version === invoice.version - 1) ?? null;

    const form = useForm({
        invoice_date: invoice.invoice_date.slice(0, 10),
        customer_name: invoice.customer_name ?? '',
        customer_company: invoice.customer_company ?? '',
        customer_email: invoice.customer_email ?? '',
        customer_phone: invoice.customer_phone ?? '',
        customer_tax_identifier: invoice.customer_tax_identifier ?? '',
        billing_address: invoice.billing_address ?? '',
        representative_name: invoice.representative_name ?? '',
        payment_method_summary: invoice.payment_method_summary ?? '',
        notes: invoice.notes ?? '',
    });
    const issueForm = useForm({});
    const cancellation = useForm({ reason: '' });
    const issueBlockedByPayment = isDraft && Number(relatedOrderPaymentSummary.remaining) > 0.00005;

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/invoices/${invoice.id}`, { preserveScroll: true });
    };
    const issue = () => issueForm.post(`/invoices/${invoice.id}/issue`, { preserveScroll: true });
    const cancel = (event: FormEvent) => {
        event.preventDefault();
        cancellation.post(`/invoices/${invoice.id}/cancel`, { preserveScroll: true, onSuccess: () => setConfirmCancel(false) });
    };
    const net = (Number(invoice.subtotal_excl_tax) - Number(invoice.discount_total)).toFixed(4);

    return (
        <SalesLayout>
            <Head title={invoice.invoice_number ?? `${t('invoices.invoice')} ${t('invoices.draft')}`} />

            <div className="mb-6">
                <Link href="/invoices" className="text-sm text-ink-muted">
                    {direction === 'rtl' ? '→' : '←'} {t('invoices.title')}
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink">
                        {t('invoices.invoice')} <bdi>{invoice.invoice_number ?? t('invoices.draft')}</bdi>
                    </h1>
                    <DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge>
                </div>
                <p className="mt-1 text-sm font-medium text-ink">
                    {t('invoices.version')} {invoice.version} · {isCurrentVersion ? t('invoices.current') : isSuperseded ? t('invoices.replaced') : t('invoices.draft')}
                </p>
                <p className="text-sm text-ink-muted">
                    {t('invoices.order')}{' '}
                    <Link href={`/sales/orders/${invoice.sales_order.id}`} className="text-ink underline">
                        {invoice.sales_order.order_number}
                    </Link>{' '}
                    · {invoice.store.name}
                </p>
            </div>

            {isReplacement && previousVersion && (
                <div className="mb-4 rounded-card border border-line bg-sage/50 px-4 py-2.5 text-sm text-ink">
                    {isDraft ? 'Nouvelle version de la ' : 'Cette version remplace la '}
                    <Link href={`/invoices/${previousVersion.id}`} className="font-medium underline">
                        facture {previousVersion.invoice_number ?? 'brouillon'} · V{previousVersion.version}
                    </Link>
                    {invoice.correction_reason && <> — {invoice.correction_reason}</>}
                </div>
            )}

            {isSuperseded && currentVersion && (
                <div className="mb-4 rounded-card border border-warning/30 bg-warning-soft/50 px-4 py-2.5 text-sm text-warning">
                    {t('invoices.supersededNotice')}{' '}
                    <Link href={`/invoices/${currentVersion.id}`} className="font-medium underline">
                        Voir la version actuelle — V{currentVersion.version}
                    </Link>
                    .
                </div>
            )}

            {orderExpandedAfterInvoice && (
                <div className="mb-4 rounded-card border border-warning/30 bg-warning-soft/50 px-4 py-2.5 text-sm text-ink">
                    <p className="font-medium">{t('invoices.orderCompletedAfter')}</p>
                    <p className="mt-1 text-ink-muted">{t('invoices.historicalVersionHelp')}</p>
                    {can.viewOrder && <Link href={`/sales/orders/${invoice.sales_order.id}`} className="mt-2 inline-block font-medium underline">{t('invoices.openOrder')}</Link>}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                {/* LEFT — document */}
                <div className="space-y-4">
                    <section className="rounded-card border border-line bg-surface p-6">
                        <div className="flex flex-wrap justify-between gap-6">
                            <div className="text-sm">
                                <p className="text-base font-semibold uppercase text-ink">{seller.legal_name ?? '—'}</p>
                                {seller.trade_name && <p className="text-ink-muted">{seller.trade_name}</p>}
                                {seller.address && <p className="text-ink-muted">{seller.address}</p>}
                                {seller.tax_identifier && <p className="text-ink-muted">ICE : {seller.tax_identifier}</p>}
                                {seller.phone && <p className="text-ink-muted">{t('invoices.phoneShort')} : <bdi>{seller.phone}</bdi></p>}
                                {seller.email && <p className="text-ink-muted">{seller.email}</p>}
                            </div>
                            <div className="min-w-52 border p-3 text-sm" style={{ borderColor: accentColor }}>
                                <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: accentColor }}>
                                    Destinataire
                                </p>
                                <p className="font-medium text-ink">
                                    {invoice.customer_company || invoice.customer_name || '—'}
                                </p>
                                {invoice.customer_company && invoice.customer_name && (
                                    <p className="text-ink-muted">{invoice.customer_name}</p>
                                )}
                                {invoice.billing_address && (
                                    <p className="whitespace-pre-line text-ink-muted">{invoice.billing_address}</p>
                                )}
                                {invoice.customer_phone && <p className="text-ink-muted">{t('invoices.phoneShort')} : <bdi>{invoice.customer_phone}</bdi></p>}
                                {invoice.customer_tax_identifier && (
                                    <p className="text-ink-muted">ICE : {invoice.customer_tax_identifier}</p>
                                )}
                            </div>
                        </div>

                        <h2
                            className="my-5 text-center text-2xl font-bold uppercase tracking-[0.15em]"
                            style={{ color: accentColor }}
                        >
                            {t('invoices.invoice')}
                        </h2>

                        <table className="mb-5 w-full border-collapse text-center text-sm">
                            <thead>
                                <tr style={{ background: accentColor }} className="text-white">
                                    <th className="px-2 py-1.5 font-normal">{t('invoices.invoice')} N°</th><th className="px-2 py-1.5 font-normal">{t('invoices.representative')}</th><th className="px-2 py-1.5 font-normal">{t('invoices.paymentTerms')}</th><th className="px-2 py-1.5 font-normal">{t('invoices.date')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="font-medium text-ink [&>td]:border [&>td]:border-line [&>td]:px-2 [&>td]:py-1.5">
                                    <td>{invoice.invoice_number ?? 'Brouillon'}</td>
                                    <td>{invoice.representative_name || '—'}</td>
                                    <td>{invoice.payment_method_summary || '—'}</td>
                                    <td>{formatDate(invoice.invoice_date)}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px] border-collapse text-sm">
                                <thead>
                                    <tr style={{ background: accentColor }} className="text-white [&>th]:px-2 [&>th]:py-2 [&>th]:font-normal">
                                        <th className="text-start">{t('invoices.reference')}</th><th className="text-start">{t('invoices.designation')}</th><th>{t('invoices.presentation')}</th><th className="text-end">{t('invoices.quantity')}</th>
                                        <th className="text-right">PU HT</th>
                                        <th className="text-right">PT HT</th>
                                        {hasDiscount && <th className="text-end">{t('invoices.discountExcl')}</th>}<th className="text-end">{t('invoices.totalIncl')}</th>
                                    </tr>
                                </thead>
                                <tbody className="[&>tr>td]:border [&>tr>td]:border-line [&>tr>td]:px-2 [&>tr>td]:py-1.5">
                                    {invoice.lines.map((line) => (
                                        <tr key={line.id}>
                                            <td>{line.reference ?? line.sku ?? '—'}</td>
                                            <td>
                                                <span className="font-medium text-ink">
                                                    {line.product_name ?? line.description}
                                                </span>
                                                {line.variant_name && (
                                                    <span className="block text-xs text-ink-muted">{line.variant_name}</span>
                                                )}
                                            </td>
                                            <td className="text-center">{line.unit_label ?? t('invoices.unit')}</td>
                                            <td className="text-right tabular-nums">{formatQuantity(line.quantity)}</td>
                                            <td className="text-right tabular-nums">
                                                {formatMoney(line.unit_price_excl_tax, currency)}
                                            </td>
                                            <td className="text-right tabular-nums">
                                                {formatMoney(line.taxable_amount, currency)}
                                            </td>
                                            {hasDiscount && (
                                                <td className="text-right tabular-nums">
                                                    {Number(line.discount_amount) > 0
                                                        ? formatMoney(line.discount_amount, currency)
                                                        : '—'}
                                                </td>
                                            )}
                                            <td className="text-right font-medium tabular-nums text-ink">
                                                {formatMoney(line.total_incl_tax, currency)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-4 flex flex-col gap-2 rounded-field border border-line bg-raised/60 p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-ink-muted">{t('invoices.linesManagedFromOrder')}</p>
                            {can.viewOrder ? (
                                <Link
                                    href={`/sales/orders/${invoice.sales_order.id}`}
                                    className="inline-flex min-h-10 shrink-0 items-center justify-center rounded-field border border-line-strong bg-surface px-4 font-medium text-ink hover:bg-raised"
                                >
                                    Voir la commande
                                </Link>
                            ) : null}
                        </div>

                        <div className="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                            {hasDiscount ? (
                                <>
                                    <SummaryRow term={t('invoices.grossExcl')} value={formatMoney(invoice.subtotal_excl_tax, currency)} />
                                    <SummaryRow term={t('invoices.discountExcl')} value={`- ${formatMoney(invoice.discount_total, currency)}`} />
                                    <SummaryRow term={t('invoices.totalExcl')} value={formatMoney(net, currency)} />
                                </>
                            ) : (
                                <SummaryRow term={t('invoices.totalExcl')} value={formatMoney(invoice.subtotal_excl_tax, currency)} />
                            )}
                            <SummaryRow term="TVA" value={formatMoney(invoice.tax_total, currency)} />
                            <div
                                className="flex justify-between border-t-2 pt-1 text-base font-bold"
                                style={{ borderColor: accentColor, color: accentColor }}
                            >
                                <span>TOTAL TTC</span>
                                <span className="tabular-nums">{formatMoney(invoice.total_incl_tax, currency)}</span>
                            </div>
                        </div>

                        {isCorrection && correctionComparison && (
                            <CorrectionDeltaBlock
                                comparison={correctionComparison}
                                currency={currency}
                                paid={relatedOrderPaymentSummary.paid}
                            />
                        )}

                        {invoice.notes && (
                            <p className="mt-4 whitespace-pre-line border-t border-line pt-3 text-sm text-ink-muted">
                                {invoice.notes}
                            </p>
                        )}
                    </section>

                    <section className="rounded-card border border-line bg-surface p-4 text-sm">
                        <p className="font-medium text-ink">{t('invoices.financialPosition')}</p>
                        <dl className="mt-2 grid gap-1 text-ink-muted sm:grid-cols-2">
                            <SummaryRow term={t('invoices.original')} value={formatMoney(invoice.total_incl_tax, currency)} />
                            <SummaryRow term={t('invoices.issuedCredits')} value={`-${formatMoney(creditSummary.credited, currency)}`} />
                            <SummaryRow term="Facturation nette" value={formatMoney(creditSummary.net, currency)} />
                            <SummaryRow term="Encaissements" value={formatMoney(relatedOrderPaymentSummary.collected, currency)} />
                            <SummaryRow term="Remboursements" value={`-${formatMoney(relatedOrderPaymentSummary.refunded, currency)}`} />
                            <SummaryRow term={t('invoices.netCollected')} value={formatMoney(relatedOrderPaymentSummary.net, currency)} />
                            <SummaryRow term={t('invoices.remainingReceivable')} value={formatMoney(creditSummary.receivable, currency)} />
                            <SummaryRow term={t('invoices.customerRefundDue')} value={formatMoney(creditSummary.refund_obligation, currency)} />
                        </dl>
                        <p className="mt-2 text-xs text-ink-faint">{t('invoices.creditRefundDistinct')}</p>
                    </section>
                    {creditNotes.length > 0 && (
                        <section className="rounded-card border border-line bg-surface p-4 text-sm">
                            <div className="flex justify-between gap-3"><h2 className="font-medium">{t('invoices.credits')}</h2><span className="rounded-full bg-raised px-2 py-1 text-xs">{creditSummary.state === 'full' ? t('invoices.fullCredit') : t('invoices.partialCredit')}</span></div>
                            <div className="mt-2 divide-y divide-line">{creditNotes.map(note => <Link key={note.id} href={`/credit-notes/${note.id}`} className="flex justify-between py-2"><span>{note.credit_note_number} · {formatDate(note.credit_note_date)}</span><span>-{formatMoney(note.total_incl_tax, currency)}</span></Link>)}</div>
                            <div className="mt-3 flex justify-between border-t border-line pt-3 font-semibold"><span>{t('invoices.netAfterCredits')}</span><span>{formatMoney(creditSummary.net, currency)}</span></div>
                        </section>
                    )}
                </div>

                {/* RIGHT — status & actions */}
                <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                    <section className="rounded-card border border-line bg-surface p-5">
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-ink-muted">{t('quotations.totalTaxIncluded')}</dt>
                                <dd className="font-semibold text-ink">{formatMoney(invoice.total_incl_tax, currency)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-ink-muted">{t('common.status')}</dt>
                                <dd>
                                    <DocBadge tone={invoiceStatusTone(invoice.status)}>
                                        {label(invoiceStatusLabel, invoice.status)}
                                    </DocBadge>
                                </dd>
                            </div>
                            {(isIssued || isSuperseded) && (
                                <div className="flex justify-between">
                                    <dt className="text-ink-muted">{t('invoices.issued')}</dt>
                                    <dd className="text-right text-ink">
                                        {invoice.issued_by?.name ?? '—'}
                                        <br />
                                        {invoice.issued_at ? formatDateTime(invoice.issued_at) : ''}
                                    </dd>
                                </div>
                            )}
                        </dl>

                        <div className="mt-4 flex flex-col gap-2">
                            {isDraft && (
                                <>
                                    <a
                                        href={previewUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                                    >
                                        {t('quotations.previewPdf')}
                                    </a>
                                    {can.issue && (
                                        <>
                                            <Button
                                                loading={issueForm.processing}
                                                loadingText={t('common.loading')}
                                                onClick={issue}
                                                disabled={issueBlockedByPayment}
                                            >
                                                {isReplacement ? t('invoices.issueReplacement') : t('invoices.issue')}
                                            </Button>
                                            {issueBlockedByPayment && (
                                                <div className="rounded-field border border-warning/30 bg-warning-soft/50 px-3 py-2 text-xs text-warning">
                                                    <p className="font-medium">
                                                        {t('invoices.fullPaymentRequired')}
                                                    </p>
                                                    <p className="mt-1">
                                                        {t('invoices.paid')} : {formatMoney(relatedOrderPaymentSummary.net, currency)} · {t('invoices.remaining')} :{' '}
                                                        {formatMoney(relatedOrderPaymentSummary.remaining, currency)}
                                                    </p>
                                                </div>
                                            )}
                                        </>
                                    )}
                                </>
                            )}
                            {(isIssued || isSuperseded) && (
                                <>
                                    <a
                                        href={`/invoices/${invoice.id}/pdf`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                                    >
                                        {t('quotations.viewPdf')}
                                    </a>
                                    <a
                                        href={`/invoices/${invoice.id}/download`}
                                        className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                                    >
                                        {t('quotations.downloadPdf')}
                                    </a>
                                </>
                            )}
                        </div>

                    </section>

                    {isIssued && sharing && (
                        <ShareCard
                            invoiceId={invoice.id}
                            sharing={sharing}
                            canEmail={can.email}
                            mailConfigured={mailConfigured}
                            canConfigureMail={can.configureMail}
                        />
                    )}

                    {isIssued && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-sm font-semibold text-ink">{t('invoices.companyStamp')}</h2>
                            <div className="mt-3">
                                <StampDocumentAction
                                    postUrl={`/invoices/${invoice.id}/stamp`}
                                    applied={stamp.applied}
                                    appliedAt={stamp.appliedAt}
                                    canStamp={can.stamp}
                                    unavailable={stamp.unavailable}
                                />
                            </div>
                        </section>
                    )}

                    {history.length > 0 && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{t('invoices.history')}</h2>
                            <ul className="mt-2 space-y-2 text-sm">
                                {history.map((entry) => (
                                    <li key={entry.id} className="flex flex-col">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-ink-muted">{t('invoices.version')} {entry.version}{entry.is_current ? ` · ${t('invoices.currentInvoice')}` : ''}</span>
                                            {entry.id === invoice.id ? (
                                                <span className="font-medium text-ink">
                                                    {entry.invoice_number ?? 'Brouillon'}
                                                </span>
                                            ) : (
                                                <Link href={`/invoices/${entry.id}`} className="font-medium text-ink underline">
                                                    {entry.invoice_number ?? 'Brouillon'}
                                                </Link>
                                            )}
                                        </div>
                                        <span className="text-xs text-ink-faint">
                                            {label(invoiceStatusLabel, entry.status)}
                                            {entry.issued_at && ` · ${t('invoices.issuedOn')} ${formatDate(entry.issued_at)}`}
                                        </span>
                                        {entry.reason && (
                                            <span className="text-xs text-ink-muted">{t('invoices.reason')} : {entry.reason}</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {isDraft && can.updateDraft && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-sm font-semibold text-ink">{t('invoices.variableInfo')}</h2>
                            <form onSubmit={save} className="mt-3 space-y-3 text-sm">
                                <Field label={t('invoices.date')}>
                                    <input
                                        type="date"
                                        readOnly={!can.backdate}
                                        value={form.data.invoice_date}
                                        onChange={(e) => form.setData('invoice_date', e.target.value)}
                                        className="control-input w-full read-only:bg-raised"
                                    />
                                </Field>
                                <Field label={t('quotations.representative')}>
                                    <input
                                        value={form.data.representative_name}
                                        onChange={(e) => form.setData('representative_name', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label={t('invoices.paymentTerms')}>
                                    <input
                                        value={form.data.payment_method_summary}
                                        onChange={(e) => form.setData('payment_method_summary', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label={t('quotations.nameCompany')}>
                                    <input
                                        value={form.data.customer_name}
                                        onChange={(e) => form.setData('customer_name', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label={t('quotations.company')}>
                                    <input
                                        value={form.data.customer_company}
                                        onChange={(e) => form.setData('customer_company', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label={t('quotations.taxId')}>
                                    <input
                                        value={form.data.customer_tax_identifier}
                                        onChange={(e) => form.setData('customer_tax_identifier', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <div className="grid grid-cols-2 gap-2">
                                    <Field label={t('quotations.email')}>
                                        <input
                                            type="email"
                                            value={form.data.customer_email}
                                            onChange={(e) => form.setData('customer_email', e.target.value)}
                                            className="w-full rounded-field border border-line-strong px-3 py-2"
                                        />
                                    </Field>
                                    <Field label={t('quotations.phone')}>
                                        <input
                                            value={form.data.customer_phone}
                                            onChange={(e) => form.setData('customer_phone', e.target.value)}
                                            className="w-full rounded-field border border-line-strong px-3 py-2"
                                        />
                                    </Field>
                                </div>
                                <Field label={t('quotations.billingAddress')}>
                                    <textarea
                                        value={form.data.billing_address}
                                        onChange={(e) => form.setData('billing_address', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label={t('quotations.notes')}>
                                    <textarea
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                {Object.values(form.errors).map(
                                    (error) => error && <p key={error} className="text-sm text-danger">{error}</p>,
                                )}
                                <Button type="submit" variant="secondary" loading={form.processing} loadingText={t('common.loading')}>
                                    {t('invoices.save')}
                                </Button>
                            </form>
                        </section>
                    )}

                    {invoice.status === 'cancelled' && (
                        <section className="rounded-card border border-warning/30 bg-warning-soft/50 p-4 text-sm text-warning">
                            {t('invoices.cancelledDraft')} : {invoice.cancellation_reason ?? t('invoices.withoutReason')}
                        </section>
                    )}

                    {isDraft && can.updateDraft && (
                        <div className="pt-1">
                            {!confirmCancel ? (
                                <button
                                    type="button"
                                    onClick={() => setConfirmCancel(true)}
                                    className="text-sm text-danger hover:underline"
                                >
                                    {t('invoices.cancelDraft')}
                                </button>
                            ) : (
                                <form
                                    onSubmit={cancel}
                                    className="space-y-2 rounded-card border border-danger/30 bg-danger-soft/40 p-3"
                                >
                                    <p className="text-sm font-medium text-danger">{t('invoices.cancelQuestion')}</p>
                                    <textarea
                                        value={cancellation.data.reason}
                                        onChange={(e) => cancellation.setData('reason', e.target.value)}
                                        placeholder={t('invoices.optionalReason')}
                                        className="control-input w-full"
                                    />
                                    <div className="flex gap-2">
                                        <Button type="submit" variant="danger" loading={cancellation.processing} loadingText="Annulation…">
                                            {t('auth.confirm')}
                                        </Button>
                                        <button
                                            type="button"
                                            onClick={() => setConfirmCancel(false)}
                                            className="rounded-field px-3 py-2 text-sm text-ink-muted"
                                        >
                                            {t('common.back')}
                                        </button>
                                    </div>
                                </form>
                            )}
                        </div>
                    )}
                </aside>
            </div>
        </SalesLayout>
    );
}

function SummaryRow({ term, value }: { term: string; value: string }) {
    return (
        <div className="flex justify-between text-ink-muted">
            <span>{term}</span>
            <span className="tabular-nums text-ink">{value}</span>
        </div>
    );
}

function CorrectionDeltaBlock({
    comparison,
    currency,
    paid,
}: {
    comparison: CorrectionComparison;
    currency: string;
    paid: string;
}) {
    const { t } = useI18n();
    const delta = Number(comparison.delta_incl_tax);
    const changed = Math.abs(delta) >= 0.005;
    const indicativeBalance = (Number(comparison.correction.total_incl_tax) - Number(paid)).toFixed(4);

    return (
        <div className="mt-4 ms-auto max-w-xs rounded-card border border-line bg-raised/60 p-3 text-sm">
            <div className="flex justify-between text-ink-muted">
                <span>{t('invoices.original')}</span>
                <span className="tabular-nums text-ink">{formatMoney(comparison.original.total_incl_tax, currency)}</span>
            </div>
            <div className="flex justify-between text-ink-muted">
                <span>{t('invoices.correctedInvoice')}</span>
                <span className="tabular-nums text-ink">{formatMoney(comparison.correction.total_incl_tax, currency)}</span>
            </div>
            <div className={`mt-1 flex justify-between border-t border-line pt-1 font-medium ${changed ? 'text-warning' : 'text-ink-muted'}`}>
                <span>{t('invoices.difference')}</span>
                <span className="tabular-nums">
                    {delta > 0 ? '+ ' : delta < 0 ? '- ' : ''}
                    {formatMoney(Math.abs(delta).toFixed(4), currency)}
                </span>
            </div>
            <div className="mt-2 space-y-0.5 border-t border-line pt-2 text-xs text-ink-muted">
                <div className="flex justify-between">
                    <span>{t('invoices.correctedAmount')}</span>
                    <span className="tabular-nums">{formatMoney(comparison.correction.total_incl_tax, currency)}</span>
                </div>
                <div className="flex justify-between">
                    <span>{t('invoices.linkedOrderPaid')}</span>
                    <span className="tabular-nums">{formatMoney(paid, currency)}</span>
                </div>
                <div className="flex justify-between">
                    <span>{t('invoices.indicativeBalance')}</span>
                    <span className="tabular-nums">{formatMoney(indicativeBalance, currency)}</span>
                </div>
                <p className="pt-1 text-ink-faint">
                    {t('invoices.correctionPaymentHelp')}
                </p>
            </div>
        </div>
    );
}

function Field({ label: l, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink-muted">{l}</span>
            {children}
        </label>
    );
}

function ShareCard({
    invoiceId,
    sharing,
    canEmail,
    mailConfigured,
    canConfigureMail,
}: {
    invoiceId: number;
    sharing: Sharing;
    canEmail: boolean;
    mailConfigured: boolean;
    canConfigureMail: boolean;
}) {
    const { t } = useI18n();
    const [tab, setTab] = useState<'email' | 'whatsapp'>('email');
    const [waPhone, setWaPhone] = useState(sharing.phone ?? '');
    const [waMessage, setWaMessage] = useState(sharing.whatsappMessage);
    const [waOpened, setWaOpened] = useState(false);
    const [emailModalOpen, setEmailModalOpen] = useState(false);

    const openWhatsApp = () => {
        const link = whatsappLink(sharing.whatsappDestination, waMessage, waPhone);
        if (!link) return;
        window.open(link, '_blank', 'noopener,noreferrer');
        setWaOpened(true);
    };

    const usesUsername = sharing.whatsappDestination.type === 'username';
    const unavailable = !whatsappLink(sharing.whatsappDestination, waMessage, waPhone);

    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <h2 className="text-sm font-semibold text-ink">{t('invoices.send')}</h2>

            <div role="tablist" className="mt-3 inline-flex rounded-field border border-line-strong p-0.5 text-sm">
                {(['email', 'whatsapp'] as const).map((key) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={tab === key}
                        onClick={() => setTab(key)}
                        className={`rounded-[7px] px-3 py-1 font-medium transition-soft ${
                            tab === key ? 'bg-primary text-primary-fg' : 'text-ink-muted hover:text-ink'
                        }`}
                    >
                        {key === 'email' ? 'Email' : 'WhatsApp'}
                    </button>
                ))}
            </div>

            {tab === 'email' && (
                <div className="mt-3 space-y-2 text-sm">
                    {!mailConfigured && (
                        <p className="text-xs text-warning">
                            {t('quotations.emailConfigRequired')}
                            {canConfigureMail && (
                                <>
                                    {' '}
                                    <Link href="/email-settings" className="font-medium underline">
                                        {t('quotations.configure')}
                                    </Link>
                                </>
                            )}
                        </p>
                    )}
                    {!canEmail && (
                        <p className="text-xs text-ink-muted">
                            {t('invoices.noEmailPermission')}
                        </p>
                    )}
                    <Button type="button" variant="secondary" disabled={!canEmail} onClick={() => setEmailModalOpen(true)}>
                        {t('invoices.sendEmail')}
                    </Button>
                    <SendDocumentEmailModal
                        open={emailModalOpen}
                        onClose={() => setEmailModalOpen(false)}
                        postUrl={`/invoices/${invoiceId}/email`}
                        title={t('invoices.sendEmail')}
                        defaultTo={sharing.email ?? ''}
                        defaultSubject={sharing.defaultSubject}
                        defaultMessage={`Bonjour,\n\nVeuillez trouver ci-joint votre facture.\n\nCordialement.`}
                        attachmentName={sharing.attachmentName}
                        canSend={canEmail}
                        mailConfigured={mailConfigured}
                        canConfigureMail={canConfigureMail}
                    />
                </div>
            )}

            {tab === 'whatsapp' && (
                <div className="mt-3 space-y-2 text-sm">
                    {usesUsername ? (
                        <Field label={t('quotations.whatsappUser')}>
                            <div className="rounded-field border border-line bg-raised px-3 py-2 text-ink">@{sharing.whatsappDestination.destination}</div>
                        </Field>
                    ) : (
                        <Field label={t('quotations.phone')}>
                            <input
                                value={waPhone}
                                onChange={(e) => {
                                    setWaPhone(e.target.value);
                                    setWaOpened(false);
                                }}
                                placeholder="+212 6 12 34 56 78"
                                className="w-full rounded-field border border-line-strong px-3 py-2"
                            />
                        </Field>
                    )}
                    {unavailable && <p className="text-xs text-ink-muted">{t('quotations.noWhatsapp')}</p>}
                    <Field label={t('quotations.message')}>
                        <textarea
                            rows={5}
                            value={waMessage}
                            onChange={(e) => {
                                setWaMessage(e.target.value);
                                setWaOpened(false);
                            }}
                            className="w-full rounded-field border border-line-strong px-3 py-2"
                        />
                    </Field>
                    <p className="text-xs text-ink-faint">
                        {t('quotations.whatsappHelp')}
                    </p>
                    <Button type="button" variant="secondary" disabled={unavailable} onClick={openWhatsApp}>
                        {t('quotations.openWhatsapp')}
                    </Button>
                    {waOpened && <p className="text-sm text-success">{t('invoices.whatsappOpened')}</p>}
                </div>
            )}
        </section>
    );
}
