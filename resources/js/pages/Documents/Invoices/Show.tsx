import DocBadge from '@/components/ui/DocBadge';
import { Button } from '@/components/ui/Button';
import CorrectionLineEditor from './CorrectionLineEditor';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import { invoiceStatusLabel, invoiceStatusTone, label } from '@/utils/labels';
import { toWhatsAppDigits } from '@/utils/phone';
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
    whatsappPhone: string | null;
    whatsappMessage: string;
};
type HistoryEntry = {
    role: 'original' | 'correction';
    id: number;
    invoice_number: string | null;
    status: string;
    issued_at: string | null;
    reason: string | null;
};
type Props = {
    invoice: Invoice;
    hasDiscount: boolean;
    accentColor: string;
    previewUrl: string;
    isCorrection: boolean;
    correctionComparison: CorrectionComparison | null;
    productSearchUrl: string | null;
    history: HistoryEntry[];
    relatedOrderPaymentSummary: { paid: string; remaining: string; status: string };
    sharing: Sharing | null;
    can: { updateDraft: boolean; issue: boolean; backdate: boolean; email: boolean; correct: boolean; editLines: boolean };
};

export default function InvoiceShow({
    invoice,
    hasDiscount,
    accentColor,
    previewUrl,
    isCorrection,
    correctionComparison,
    productSearchUrl,
    history,
    relatedOrderPaymentSummary,
    sharing,
    can,
}: Props) {
    const isDraft = invoice.status === 'draft';
    const isIssued = invoice.status === 'issued';
    const isSuperseded = invoice.status === 'superseded';
    const canEditLines = Boolean(isCorrection && isDraft && can.editLines && productSearchUrl);
    const currency = invoice.currency_code;
    const seller = invoice.seller_snapshot ?? {};
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [confirmCorrect, setConfirmCorrect] = useState(false);
    const originalEntry = history.find((h) => h.role === 'original') ?? null;
    const correctionEntry = history.find((h) => h.role === 'correction') ?? null;

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
    const correction = useForm({ reason: '' });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/invoices/${invoice.id}`, { preserveScroll: true });
    };
    const issue = () => issueForm.post(`/invoices/${invoice.id}/issue`, { preserveScroll: true });
    const startCorrection = (event: FormEvent) => {
        event.preventDefault();
        correction.post(`/invoices/${invoice.id}/corrections`, { preserveScroll: true });
    };
    const cancel = (event: FormEvent) => {
        event.preventDefault();
        cancellation.post(`/invoices/${invoice.id}/cancel`, { preserveScroll: true, onSuccess: () => setConfirmCancel(false) });
    };

    const net = (Number(invoice.subtotal_excl_tax) - Number(invoice.discount_total)).toFixed(4);

    return (
        <SalesLayout>
            <Head title={invoice.invoice_number ?? 'Facture brouillon'} />

            <div className="mb-6">
                <Link href="/invoices" className="text-sm text-ink-muted">
                    ← Factures
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink">
                        {invoice.invoice_number ? `Facture ${invoice.invoice_number}` : 'Facture brouillon'}
                    </h1>
                    <DocBadge tone={invoiceStatusTone(invoice.status)}>{label(invoiceStatusLabel, invoice.status)}</DocBadge>
                </div>
                <p className="text-sm text-ink-muted">
                    Commande{' '}
                    <Link href={`/sales/orders/${invoice.sales_order.id}`} className="text-ink underline">
                        {invoice.sales_order.order_number}
                    </Link>{' '}
                    · {invoice.store.name}
                </p>
            </div>

            {isCorrection && originalEntry && (
                <div className="mb-4 rounded-card border border-line bg-sage/50 px-4 py-2.5 text-sm text-ink">
                    {isDraft ? 'Correction de la ' : 'Cette facture corrige la '}
                    <Link href={`/invoices/${originalEntry.id}`} className="font-medium underline">
                        facture {originalEntry.invoice_number ?? 'brouillon'}
                    </Link>
                    {invoice.correction_reason && <> — {invoice.correction_reason}</>}
                </div>
            )}

            {isSuperseded && correctionEntry && (
                <div className="mb-4 rounded-card border border-warning/30 bg-warning-soft/50 px-4 py-2.5 text-sm text-warning">
                    Cette facture a été remplacée par la{' '}
                    <Link href={`/invoices/${correctionEntry.id}`} className="font-medium underline">
                        facture {correctionEntry.invoice_number ?? 'en cours'}
                    </Link>
                    . Le partage se fait depuis la version corrigée.
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
                                {seller.phone && <p className="text-ink-muted">Tél : {seller.phone}</p>}
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
                                {invoice.customer_phone && <p className="text-ink-muted">Tél : {invoice.customer_phone}</p>}
                                {invoice.customer_tax_identifier && (
                                    <p className="text-ink-muted">ICE : {invoice.customer_tax_identifier}</p>
                                )}
                            </div>
                        </div>

                        <h2
                            className="my-5 text-center text-2xl font-bold uppercase tracking-[0.15em]"
                            style={{ color: accentColor }}
                        >
                            Facture
                        </h2>

                        <table className="mb-5 w-full border-collapse text-center text-sm">
                            <thead>
                                <tr style={{ background: accentColor }} className="text-white">
                                    <th className="px-2 py-1.5 font-normal">Facture N°</th>
                                    <th className="px-2 py-1.5 font-normal">Représentant</th>
                                    <th className="px-2 py-1.5 font-normal">Modalité de paiement</th>
                                    <th className="px-2 py-1.5 font-normal">Date de facture</th>
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

                        {canEditLines && productSearchUrl ? (
                            <CorrectionLineEditor
                                invoiceId={invoice.id}
                                currency={currency}
                                lines={invoice.lines}
                                searchUrl={productSearchUrl}
                            />
                        ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px] border-collapse text-sm">
                                <thead>
                                    <tr style={{ background: accentColor }} className="text-white [&>th]:px-2 [&>th]:py-2 [&>th]:font-normal">
                                        <th className="text-left">Référence</th>
                                        <th className="text-left">Désignation</th>
                                        <th>Présentation</th>
                                        <th className="text-right">Qté</th>
                                        <th className="text-right">PU HT</th>
                                        <th className="text-right">PT HT</th>
                                        {hasDiscount && <th className="text-right">Remise</th>}
                                        <th className="text-right">Total TTC</th>
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
                                            <td className="text-center">{line.unit_label ?? 'Unité'}</td>
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
                        )}

                        <div className="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                            {hasDiscount ? (
                                <>
                                    <SummaryRow term="Sous-total HT" value={formatMoney(invoice.subtotal_excl_tax, currency)} />
                                    <SummaryRow term="Remise" value={`- ${formatMoney(invoice.discount_total, currency)}`} />
                                    <SummaryRow term="Total HT" value={formatMoney(net, currency)} />
                                </>
                            ) : (
                                <SummaryRow term="Total HT" value={formatMoney(invoice.subtotal_excl_tax, currency)} />
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

                    <section className="rounded-card border border-line bg-surface p-4 text-sm text-ink-muted">
                        <p className="font-medium text-ink">Paiement de la commande liée</p>
                        <p className="mt-1">
                            Payé {formatMoney(relatedOrderPaymentSummary.paid, currency)} · Reste{' '}
                            {formatMoney(relatedOrderPaymentSummary.remaining, currency)}. Information au niveau de la commande,
                            distincte du calcul de la facture.
                        </p>
                    </section>
                </div>

                {/* RIGHT — status & actions */}
                <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                    <section className="rounded-card border border-line bg-surface p-5">
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-ink-muted">Total TTC</dt>
                                <dd className="font-semibold text-ink">{formatMoney(invoice.total_incl_tax, currency)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-ink-muted">Statut</dt>
                                <dd>
                                    <DocBadge tone={invoiceStatusTone(invoice.status)}>
                                        {label(invoiceStatusLabel, invoice.status)}
                                    </DocBadge>
                                </dd>
                            </div>
                            {(isIssued || isSuperseded) && (
                                <div className="flex justify-between">
                                    <dt className="text-ink-muted">Émise</dt>
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
                                        Aperçu PDF
                                    </a>
                                    {can.issue && (
                                        <Button loading={issueForm.processing} loadingText="Émission…" onClick={issue}>
                                            {isCorrection ? 'Émettre la correction' : 'Émettre la facture'}
                                        </Button>
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
                                        Voir PDF
                                    </a>
                                    <a
                                        href={`/invoices/${invoice.id}/download`}
                                        className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                                    >
                                        Télécharger PDF
                                    </a>
                                </>
                            )}
                        </div>

                        {isIssued && can.correct && (
                            <div className="mt-3 border-t border-line pt-3">
                                {!confirmCorrect ? (
                                    <button
                                        type="button"
                                        onClick={() => setConfirmCorrect(true)}
                                        className="text-sm text-ink-muted hover:text-ink hover:underline"
                                    >
                                        Corriger la facture
                                    </button>
                                ) : (
                                    <form onSubmit={startCorrection} className="space-y-2">
                                        <p className="text-sm font-medium text-ink">Corriger cette facture ?</p>
                                        <p className="text-xs text-ink-muted">
                                            La version actuellement émise sera conservée dans l’historique.
                                        </p>
                                        <textarea
                                            required
                                            value={correction.data.reason}
                                            onChange={(e) => correction.setData('reason', e.target.value)}
                                            placeholder="Motif de la correction"
                                            className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                                        />
                                        {correction.errors.reason && (
                                            <p className="text-sm text-danger">{correction.errors.reason}</p>
                                        )}
                                        <div className="flex gap-2">
                                            <Button
                                                type="submit"
                                                variant="secondary"
                                                loading={correction.processing}
                                                loadingText="Création de la correction…"
                                            >
                                                Créer une correction
                                            </Button>
                                            <button
                                                type="button"
                                                onClick={() => setConfirmCorrect(false)}
                                                className="rounded-field px-3 py-2 text-sm text-ink-muted"
                                            >
                                                Annuler
                                            </button>
                                        </div>
                                    </form>
                                )}
                            </div>
                        )}
                    </section>

                    {isIssued && sharing && (
                        <ShareCard invoiceId={invoice.id} sharing={sharing} canEmail={can.email} />
                    )}

                    {history.length > 0 && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Historique</h2>
                            <ul className="mt-2 space-y-2 text-sm">
                                {history.map((entry) => (
                                    <li key={entry.id} className="flex flex-col">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-ink-muted">
                                                {entry.role === 'original' ? 'Facture originale' : 'Correction'}
                                            </span>
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
                                            {entry.issued_at && ` · émise le ${formatDate(entry.issued_at)}`}
                                        </span>
                                        {entry.reason && (
                                            <span className="text-xs text-ink-muted">Motif : {entry.reason}</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {isDraft && can.updateDraft && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-sm font-semibold text-ink">Informations variables</h2>
                            <form onSubmit={save} className="mt-3 space-y-3 text-sm">
                                <Field label="Date de facture">
                                    <input
                                        type="date"
                                        readOnly={!can.backdate}
                                        value={form.data.invoice_date}
                                        onChange={(e) => form.setData('invoice_date', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2 read-only:bg-raised"
                                    />
                                </Field>
                                <Field label="Représentant">
                                    <input
                                        value={form.data.representative_name}
                                        onChange={(e) => form.setData('representative_name', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label="Modalité de paiement">
                                    <input
                                        value={form.data.payment_method_summary}
                                        onChange={(e) => form.setData('payment_method_summary', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label="Nom / raison sociale du client">
                                    <input
                                        value={form.data.customer_name}
                                        onChange={(e) => form.setData('customer_name', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label="Société">
                                    <input
                                        value={form.data.customer_company}
                                        onChange={(e) => form.setData('customer_company', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label="ICE client">
                                    <input
                                        value={form.data.customer_tax_identifier}
                                        onChange={(e) => form.setData('customer_tax_identifier', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <div className="grid grid-cols-2 gap-2">
                                    <Field label="Email">
                                        <input
                                            type="email"
                                            value={form.data.customer_email}
                                            onChange={(e) => form.setData('customer_email', e.target.value)}
                                            className="w-full rounded-field border border-line-strong px-3 py-2"
                                        />
                                    </Field>
                                    <Field label="Téléphone">
                                        <input
                                            value={form.data.customer_phone}
                                            onChange={(e) => form.setData('customer_phone', e.target.value)}
                                            className="w-full rounded-field border border-line-strong px-3 py-2"
                                        />
                                    </Field>
                                </div>
                                <Field label="Adresse de facturation">
                                    <textarea
                                        value={form.data.billing_address}
                                        onChange={(e) => form.setData('billing_address', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                <Field label="Notes">
                                    <textarea
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        className="w-full rounded-field border border-line-strong px-3 py-2"
                                    />
                                </Field>
                                {Object.values(form.errors).map(
                                    (error) => error && <p key={error} className="text-sm text-danger">{error}</p>,
                                )}
                                <Button type="submit" variant="secondary" loading={form.processing} loadingText="Enregistrement…">
                                    Enregistrer
                                </Button>
                            </form>
                        </section>
                    )}

                    {invoice.status === 'cancelled' && (
                        <section className="rounded-card border border-warning/30 bg-warning-soft/50 p-4 text-sm text-warning">
                            Brouillon annulé : {invoice.cancellation_reason ?? 'sans motif'}
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
                                    Annuler le brouillon
                                </button>
                            ) : (
                                <form
                                    onSubmit={cancel}
                                    className="space-y-2 rounded-card border border-danger/30 bg-danger-soft/40 p-3"
                                >
                                    <p className="text-sm font-medium text-danger">Annuler ce brouillon de facture ?</p>
                                    <textarea
                                        value={cancellation.data.reason}
                                        onChange={(e) => cancellation.setData('reason', e.target.value)}
                                        placeholder="Motif (facultatif)"
                                        className="w-full rounded-field border border-line-strong px-3 py-2 text-sm"
                                    />
                                    <div className="flex gap-2">
                                        <Button type="submit" variant="danger" loading={cancellation.processing} loadingText="Annulation…">
                                            Confirmer
                                        </Button>
                                        <button
                                            type="button"
                                            onClick={() => setConfirmCancel(false)}
                                            className="rounded-field px-3 py-2 text-sm text-ink-muted"
                                        >
                                            Retour
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
    const delta = Number(comparison.delta_incl_tax);
    const changed = Math.abs(delta) >= 0.005;
    const indicativeBalance = (Number(comparison.correction.total_incl_tax) - Number(paid)).toFixed(4);

    return (
        <div className="mt-4 ml-auto max-w-xs rounded-card border border-line bg-raised/60 p-3 text-sm">
            <div className="flex justify-between text-ink-muted">
                <span>Facture originale</span>
                <span className="tabular-nums text-ink">{formatMoney(comparison.original.total_incl_tax, currency)}</span>
            </div>
            <div className="flex justify-between text-ink-muted">
                <span>Facture corrigée</span>
                <span className="tabular-nums text-ink">{formatMoney(comparison.correction.total_incl_tax, currency)}</span>
            </div>
            <div className={`mt-1 flex justify-between border-t border-line pt-1 font-medium ${changed ? 'text-warning' : 'text-ink-muted'}`}>
                <span>Écart</span>
                <span className="tabular-nums">
                    {delta > 0 ? '+ ' : delta < 0 ? '- ' : ''}
                    {formatMoney(Math.abs(delta).toFixed(4), currency)}
                </span>
            </div>
            <div className="mt-2 space-y-0.5 border-t border-line pt-2 text-xs text-ink-muted">
                <div className="flex justify-between">
                    <span>Montant corrigé</span>
                    <span className="tabular-nums">{formatMoney(comparison.correction.total_incl_tax, currency)}</span>
                </div>
                <div className="flex justify-between">
                    <span>Payé (commande liée)</span>
                    <span className="tabular-nums">{formatMoney(paid, currency)}</span>
                </div>
                <div className="flex justify-between">
                    <span>Solde indicatif</span>
                    <span className="tabular-nums">{formatMoney(indicativeBalance, currency)}</span>
                </div>
                <p className="pt-1 text-ink-faint">
                    Les paiements restent gérés au niveau de la commande et ne sont pas modifiés par la correction.
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

function ShareCard({ invoiceId, sharing, canEmail }: { invoiceId: number; sharing: Sharing; canEmail: boolean }) {
    const [tab, setTab] = useState<'email' | 'whatsapp'>('email');
    const [waPhone, setWaPhone] = useState(sharing.phone ?? '');
    const [waMessage, setWaMessage] = useState(sharing.whatsappMessage);
    const [waOpened, setWaOpened] = useState(false);
    const email = useForm({ email: sharing.email ?? '' });

    const sendEmail = (event: FormEvent) => {
        event.preventDefault();
        email.post(`/invoices/${invoiceId}/email`, { preserveScroll: true });
    };

    const openWhatsApp = () => {
        const digits = toWhatsAppDigits(waPhone);
        const base = digits ? `https://wa.me/${digits}` : 'https://wa.me/';
        window.open(`${base}?text=${encodeURIComponent(waMessage)}`, '_blank', 'noopener,noreferrer');
        setWaOpened(true);
    };

    const noPhone = !toWhatsAppDigits(waPhone);

    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <h2 className="text-sm font-semibold text-ink">Envoyer la facture</h2>

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
                <form onSubmit={sendEmail} className="mt-3 space-y-2 text-sm">
                    <Field label="Adresse email">
                        <input
                            type="email"
                            required
                            value={email.data.email}
                            onChange={(e) => email.setData('email', e.target.value)}
                            placeholder="destinataire@exemple.ma"
                            className="w-full rounded-field border border-line-strong px-3 py-2"
                        />
                    </Field>
                    {email.errors.email && <p className="text-sm text-danger">{email.errors.email}</p>}
                    {!canEmail && (
                        <p className="text-xs text-ink-muted">
                            Vous n’avez pas la permission d’envoyer des factures par email.
                        </p>
                    )}
                    <Button type="submit" variant="secondary" loading={email.processing} loadingText="Envoi…" disabled={!canEmail}>
                        Envoyer par email
                    </Button>
                </form>
            )}

            {tab === 'whatsapp' && (
                <div className="mt-3 space-y-2 text-sm">
                    <Field label="Téléphone">
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
                    {noPhone && (
                        <p className="text-xs text-ink-muted">
                            Aucun numéro de téléphone enregistré. Saisissez un numéro, ou ouvrez WhatsApp et choisissez le contact.
                        </p>
                    )}
                    <Field label="Message">
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
                        Le lien PDF ci-dessus est temporaire et sécurisé. WhatsApp n’attache pas le fichier automatiquement — le
                        message s’ouvre, vous l’envoyez depuis WhatsApp.
                    </p>
                    <Button type="button" variant="secondary" onClick={openWhatsApp}>
                        Ouvrir dans WhatsApp
                    </Button>
                    {waOpened && <p className="text-sm text-success">WhatsApp ouvert. Envoyez le message depuis WhatsApp.</p>}
                </div>
            )}
        </section>
    );
}
