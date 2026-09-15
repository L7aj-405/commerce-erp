import DocBadge from '@/components/ui/DocBadge';
import { Button } from '@/components/ui/Button';
import SendDocumentEmailModal from '@/components/documents/SendDocumentEmailModal';
import StampDocumentAction from '@/components/documents/StampDocumentAction';
import SalesLayout from '@/layouts/SalesLayout';
import LineGrid, { type QLine } from './LineGrid';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/utils/format';
import { label, quotationStatusLabel, quotationStatusTone } from '@/utils/labels';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Seller = { legal_name?: string; trade_name?: string; address?: string; phone?: string; email?: string; tax_identifier?: string; accent_color?: string };
type Quotation = {
    id: number;
    quotation_number: string | null;
    status: string;
    quotation_date: string;
    valid_until: string | null;
    customer_name: string | null;
    customer_company: string | null;
    customer_email: string | null;
    customer_phone: string | null;
    customer_tax_identifier: string | null;
    billing_address: string | null;
    representative_name: string | null;
    notes: string | null;
    terms: string | null;
    rejection_reason: string | null;
    revision_number: number;
    revision_reason: string | null;
    revised_from: { id: number; quotation_number: string | null; revision_number: number } | null;
    root_quotation: { id: number; quotation_number: string | null } | null;
    subtotal_excl_tax: string;
    discount_total: string;
    tax_total: string;
    total_incl_tax: string;
    currency_code: string;
    issued_at: string | null;
    seller_snapshot: Seller | null;
    lines: QLine[];
    issued_by: { name: string } | null;
    created_by: { name: string } | null;
    converted_sales_order: { id: number; order_number: string; status: string } | null;
};
type Sharing = { pdfUrl: string; email: string | null; phone: string | null; whatsappMessage: string; defaultSubject: string; attachmentName: string };
type HistoryEntry = {
    id: number;
    quotation_number: string | null;
    revision_number: number;
    label: string;
    status: string;
    issued_at: string | null;
    reason: string | null;
    is_current: boolean;
};
type Props = {
    quotation: Quotation;
    hasDiscount: boolean;
    accentColor: string;
    previewUrl: string;
    searchUrl: string;
    taxRates: { id: number; name: string; rate: string; is_default: boolean }[];
    warehouses: { id: number; name: string; code: string }[];
    isPastValidity: boolean;
    isCurrentVersion: boolean;
    history: HistoryEntry[];
    sharing: Sharing | null;
    mailConfigured: boolean;
    stamp: { applied: boolean; appliedAt: string | null };
    can: {
        update: boolean;
        issue: boolean;
        decide: boolean;
        convert: boolean;
        revise: boolean;
        email: boolean;
        duplicate: boolean;
        createCustomer: boolean;
        configureMail: boolean;
        stamp: boolean;
    };
};

export default function QuotationShow({
    quotation,
    hasDiscount,
    accentColor,
    previewUrl,
    searchUrl,
    taxRates,
    warehouses,
    isPastValidity,
    isCurrentVersion,
    history,
    sharing,
    mailConfigured,
    stamp,
    can,
}: Props) {
    const isDraft = quotation.status === 'draft';
    const isSuperseded = quotation.status === 'superseded';
    const isConverted = quotation.status === 'converted';
    const isRevision = (quotation.revision_number ?? 0) > 0;
    const currency = quotation.currency_code;
    const seller = quotation.seller_snapshot ?? {};
    const editable = isDraft && can.update;
    const currentEntry = history.find((h) => h.is_current) ?? null;
    const rootNumber = quotation.root_quotation?.quotation_number ?? quotation.revised_from?.quotation_number ?? null;

    const header = useForm({
        quotation_date: quotation.quotation_date.slice(0, 10),
        valid_until: quotation.valid_until ? quotation.valid_until.slice(0, 10) : '',
        representative_name: quotation.representative_name ?? '',
        customer_name: quotation.customer_name ?? '',
        customer_company: quotation.customer_company ?? '',
        customer_email: quotation.customer_email ?? '',
        customer_phone: quotation.customer_phone ?? '',
        customer_tax_identifier: quotation.customer_tax_identifier ?? '',
        billing_address: quotation.billing_address ?? '',
        notes: quotation.notes ?? '',
        terms: quotation.terms ?? '',
    });
    const issueForm = useForm({});
    const decideForm = useForm({ reason: '' });
    const reviseForm = useForm({ reason: '' });
    const convertForm = useForm<{ warehouse_id: number | null }>({ warehouse_id: warehouses[0]?.id ?? null });
    const [confirmReject, setConfirmReject] = useState(false);
    const [confirmRevise, setConfirmRevise] = useState(false);
    const [convertOpen, setConvertOpen] = useState(false);

    const saveHeader = (e: FormEvent) => {
        e.preventDefault();
        header.patch(`/quotations/${quotation.id}`, { preserveScroll: true });
    };
    const issue = () => issueForm.post(`/quotations/${quotation.id}/issue`, { preserveScroll: true });
    const accept = () => decideForm.post(`/quotations/${quotation.id}/accept`, { preserveScroll: true });
    const reject = (e: FormEvent) => {
        e.preventDefault();
        decideForm.post(`/quotations/${quotation.id}/reject`, { preserveScroll: true, onSuccess: () => setConfirmReject(false) });
    };
    const startRevision = (e: FormEvent) => {
        e.preventDefault();
        reviseForm.post(`/quotations/${quotation.id}/revise`, { preserveScroll: true, onSuccess: () => setConfirmRevise(false) });
    };
    const convert = () => convertForm.post(`/quotations/${quotation.id}/conversion`, { preserveScroll: true });

    const net = (Number(quotation.subtotal_excl_tax) - Number(quotation.discount_total)).toFixed(4);
    const buyerLabel = quotation.customer_company || quotation.customer_name || '—';
    const heading = quotation.quotation_number
        ? `Devis ${quotation.quotation_number}`
        : isRevision
          ? `Devis brouillon · Révision ${quotation.revision_number}`
          : 'Devis brouillon';
    const showBusinessActions = can.revise || can.convert;

    return (
        <SalesLayout>
            <Head title={quotation.quotation_number ?? 'Devis brouillon'} />

            <div className="mb-6">
                <Link href="/quotations" className="text-sm text-ink-muted">
                    ← Devis
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink">{heading}</h1>
                    <DocBadge tone={quotationStatusTone(quotation.status)}>{label(quotationStatusLabel, quotation.status)}</DocBadge>
                    {isRevision && <span className="text-xs text-ink-muted">Révision {quotation.revision_number}</span>}
                    {isPastValidity && quotation.status === 'issued' && <span className="text-xs text-warning">Échu</span>}
                </div>
                <p className="text-sm text-ink-muted">
                    {buyerLabel}
                    {quotation.valid_until && <> · Valable jusqu’au {formatDate(quotation.valid_until)}</>}
                </p>
            </div>

            {isConverted && quotation.converted_sales_order && (
                <div className="mb-4 rounded-card border border-line bg-sage/50 px-4 py-2.5 text-sm text-ink">
                    Devis transformé en{' '}
                    <Link href={`/sales/orders/${quotation.converted_sales_order.id}`} className="font-medium underline">
                        commande {quotation.converted_sales_order.order_number}
                    </Link>
                    .
                </div>
            )}
            {quotation.status === 'rejected' && quotation.rejection_reason && (
                <div className="mb-4 rounded-card border border-danger/30 bg-danger-soft/40 px-4 py-2.5 text-sm text-danger">
                    Devis refusé — {quotation.rejection_reason}
                </div>
            )}
            {isSuperseded && currentEntry && (
                <div className="mb-4 rounded-card border border-warning/30 bg-warning-soft/50 px-4 py-2.5 text-sm text-warning">
                    Cette version a été remplacée par la{' '}
                    <Link href={`/quotations/${currentEntry.id}`} className="font-medium underline">
                        {currentEntry.label.toLowerCase()} ({currentEntry.quotation_number ?? 'en cours'})
                    </Link>
                    . Le partage et la conversion se font depuis la version actuelle.
                </div>
            )}
            {isRevision && isDraft && (
                <div className="mb-4 rounded-card border border-line bg-raised/60 px-4 py-2.5 text-sm text-ink">
                    Révision {quotation.revision_number}
                    {rootNumber && <> de {rootNumber}</>} — brouillon éditable copié depuis la version émise.
                    {quotation.revision_reason && <> Motif : {quotation.revision_reason}</>} La version émise reste le
                    document courant jusqu’à l’émission de cette révision.
                </div>
            )}
            {isRevision && !isDraft && !isSuperseded && rootNumber && (
                <div className="mb-4 rounded-card border border-line bg-surface px-4 py-2.5 text-sm text-ink-muted">
                    Révision {quotation.revision_number} de la proposition {rootNumber}.
                    {quotation.revision_reason && <> Motif : {quotation.revision_reason}</>}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                {/* LEFT — document */}
                <div className="space-y-4">
                    <section className="rounded-card border border-line bg-surface p-6">
                        <div className="flex flex-wrap justify-between gap-6">
                            <div className="text-sm">
                                <p className="text-base font-semibold uppercase text-ink">{seller.legal_name ?? '—'}</p>
                                {seller.address && <p className="text-ink-muted">{seller.address}</p>}
                                {seller.tax_identifier && <p className="text-ink-muted">ICE : {seller.tax_identifier}</p>}
                                {seller.phone && <p className="text-ink-muted">Tél : {seller.phone}</p>}
                                {seller.email && <p className="text-ink-muted">{seller.email}</p>}
                            </div>
                            <div className="min-w-52 border p-3 text-sm" style={{ borderColor: accentColor }}>
                                <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: accentColor }}>
                                    Destinataire
                                </p>
                                <p className="font-medium text-ink">{buyerLabel}</p>
                                {quotation.customer_company && quotation.customer_name && <p className="text-ink-muted">{quotation.customer_name}</p>}
                                {quotation.billing_address && <p className="whitespace-pre-line text-ink-muted">{quotation.billing_address}</p>}
                                {quotation.customer_phone && <p className="text-ink-muted">Tél : {quotation.customer_phone}</p>}
                                {quotation.customer_tax_identifier && <p className="text-ink-muted">ICE : {quotation.customer_tax_identifier}</p>}
                            </div>
                        </div>

                        <h2 className="mb-1 mt-5 text-center text-2xl font-bold uppercase tracking-[0.15em]" style={{ color: accentColor }}>
                            Devis
                        </h2>
                        {isRevision && (
                            <p className="mb-4 text-center text-sm font-semibold" style={{ color: accentColor }}>
                                Révision {quotation.revision_number}
                                {rootNumber && <> — Devis {rootNumber}</>}
                            </p>
                        )}

                        <table className="mb-5 mt-4 w-full border-collapse text-center text-sm">
                            <thead>
                                <tr style={{ background: accentColor }} className="text-white">
                                    <th className="px-2 py-1.5 font-normal">Devis N°</th>
                                    <th className="px-2 py-1.5 font-normal">Représentant</th>
                                    <th className="px-2 py-1.5 font-normal">Date du devis</th>
                                    <th className="px-2 py-1.5 font-normal">Valable jusqu’au</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="font-medium text-ink [&>td]:border [&>td]:border-line [&>td]:px-2 [&>td]:py-1.5">
                                    <td>{quotation.quotation_number ?? 'Brouillon'}</td>
                                    <td>{quotation.representative_name || '—'}</td>
                                    <td>{formatDate(quotation.quotation_date)}</td>
                                    <td>{quotation.valid_until ? formatDate(quotation.valid_until) : '—'}</td>
                                </tr>
                            </tbody>
                        </table>

                        {editable ? (
                            <LineGrid quotationId={quotation.id} currency={currency} lines={quotation.lines} searchUrl={searchUrl} taxRates={taxRates} />
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
                                        {quotation.lines.map((line) => (
                                            <tr key={line.id}>
                                                <td>{line.reference ?? line.sku ?? '—'}</td>
                                                <td>
                                                    <span className="font-medium text-ink">{line.product_name ?? line.description}</span>
                                                    {line.variant_name && <span className="block text-xs text-ink-muted">{line.variant_name}</span>}
                                                </td>
                                                <td className="text-center">{line.unit_label ?? 'Unité'}</td>
                                                <td className="text-right tabular-nums">{formatQuantity(line.quantity)}</td>
                                                <td className="text-right tabular-nums">{formatMoney(line.unit_price_excl_tax, currency)}</td>
                                                <td className="text-right tabular-nums">{formatMoney(line.taxable_amount, currency)}</td>
                                                {hasDiscount && (
                                                    <td className="text-right tabular-nums">
                                                        {Number(line.discount_amount) > 0 ? formatMoney(line.discount_amount, currency) : '—'}
                                                    </td>
                                                )}
                                                <td className="text-right font-medium tabular-nums text-ink">{formatMoney(line.total_incl_tax, currency)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <div className="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                            {hasDiscount ? (
                                <>
                                    <SummaryRow term="Sous-total HT" value={formatMoney(quotation.subtotal_excl_tax, currency)} />
                                    <SummaryRow term="Remise" value={`- ${formatMoney(quotation.discount_total, currency)}`} />
                                    <SummaryRow term="Total HT" value={formatMoney(net, currency)} />
                                </>
                            ) : (
                                <SummaryRow term="Total HT" value={formatMoney(quotation.subtotal_excl_tax, currency)} />
                            )}
                            <SummaryRow term="TVA" value={formatMoney(quotation.tax_total, currency)} />
                            <div className="flex justify-between border-t-2 pt-1 text-base font-bold" style={{ borderColor: accentColor, color: accentColor }}>
                                <span>TOTAL TTC</span>
                                <span className="tabular-nums">{formatMoney(quotation.total_incl_tax, currency)}</span>
                            </div>
                        </div>

                        {quotation.notes && <p className="mt-4 whitespace-pre-line border-t border-line pt-3 text-sm text-ink-muted">{quotation.notes}</p>}
                        {quotation.terms && <p className="mt-2 whitespace-pre-line text-sm text-ink-muted"><strong>Conditions :</strong> {quotation.terms}</p>}
                    </section>
                </div>

                {/* RIGHT — status & actions */}
                <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                    <section className="rounded-card border border-line bg-surface p-5">
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-ink-muted">Total TTC</dt>
                                <dd className="font-semibold text-ink">{formatMoney(quotation.total_incl_tax, currency)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-ink-muted">Statut</dt>
                                <dd>
                                    <DocBadge tone={quotationStatusTone(quotation.status)}>{label(quotationStatusLabel, quotation.status)}</DocBadge>
                                </dd>
                            </div>
                            {quotation.issued_at && (
                                <div className="flex justify-between">
                                    <dt className="text-ink-muted">Émis</dt>
                                    <dd className="text-right text-ink">
                                        {quotation.issued_by?.name ?? '—'}
                                        <br />
                                        {formatDateTime(quotation.issued_at)}
                                    </dd>
                                </div>
                            )}
                        </dl>

                        {/* DOCUMENT — one action convention, shared with Invoice */}
                        <div className="mt-4 flex flex-col gap-2">
                            {isDraft ? (
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
                                        <Button loading={issueForm.processing} loadingText="Émission…" onClick={issue} disabled={quotation.lines.length === 0}>
                                            {isRevision ? 'Émettre la révision' : 'Émettre le devis'}
                                        </Button>
                                    )}
                                </>
                            ) : (
                                <>
                                    <a
                                        href={`/quotations/${quotation.id}/pdf`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                                    >
                                        Voir PDF
                                    </a>
                                    <a
                                        href={`/quotations/${quotation.id}/download`}
                                        className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                                    >
                                        Télécharger PDF
                                    </a>
                                </>
                            )}
                        </div>

                        {/* BUSINESS actions — current issued version only */}
                        {showBusinessActions && (
                            <div className="mt-3 flex flex-col gap-2 border-t border-line pt-3">
                                {can.revise &&
                                    (!confirmRevise ? (
                                        <Button variant="secondary" onClick={() => setConfirmRevise(true)}>
                                            Réviser le devis
                                        </Button>
                                    ) : (
                                        <form onSubmit={startRevision} className="space-y-2 rounded-field border border-line-strong p-3">
                                            <p className="text-sm font-medium text-ink">Créer une révision ?</p>
                                            <p className="text-xs text-ink-muted">
                                                La version émise est conservée dans l’historique. Un brouillon éditable est créé à partir de sa copie.
                                            </p>
                                            <textarea
                                                required
                                                value={reviseForm.data.reason}
                                                onChange={(e) => reviseForm.setData('reason', e.target.value)}
                                                placeholder="Motif de la révision"
                                                className="w-full rounded-field border border-line-strong px-2 py-1 text-sm"
                                            />
                                            {reviseForm.errors.reason && <p className="text-sm text-danger">{reviseForm.errors.reason}</p>}
                                            <div className="flex gap-2">
                                                <Button size="sm" variant="secondary" type="submit" loading={reviseForm.processing} loadingText="Création…">
                                                    Créer la révision
                                                </Button>
                                                <button type="button" onClick={() => setConfirmRevise(false)} className="text-xs text-ink-muted hover:underline">
                                                    Annuler
                                                </button>
                                            </div>
                                        </form>
                                    ))}

                                {can.convert &&
                                    (!convertOpen ? (
                                        <Button variant="secondary" onClick={() => setConvertOpen(true)}>
                                            Transformer en commande
                                        </Button>
                                    ) : (
                                        <div className="space-y-2 rounded-field border border-line-strong p-3">
                                            <p className="text-sm font-medium text-ink">Transformer en commande brouillon ?</p>
                                            {warehouses.length > 0 && (
                                                <label className="block text-xs text-ink-muted">
                                                    Entrepôt (lignes catalogue)
                                                    <select
                                                        value={convertForm.data.warehouse_id ?? ''}
                                                        onChange={(e) => convertForm.setData('warehouse_id', e.target.value ? Number(e.target.value) : null)}
                                                        className="mt-1 w-full rounded-field border border-line-strong px-2 py-1 text-sm"
                                                    >
                                                        {warehouses.map((w) => (
                                                            <option key={w.id} value={w.id}>
                                                                {w.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </label>
                                            )}
                                            <p className="text-xs text-ink-faint">Le stock est vérifié à la confirmation de la commande, pas ici.</p>
                                            <div className="flex gap-2">
                                                <Button size="sm" loading={convertForm.processing} loadingText="Création de la commande…" onClick={convert}>
                                                    Confirmer
                                                </Button>
                                                <button type="button" onClick={() => setConvertOpen(false)} className="text-xs text-ink-muted hover:underline">
                                                    Annuler
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                            </div>
                        )}

                        {/* LIFECYCLE — accept / reject the current issued version */}
                        {can.decide && (
                            <div className="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
                                {quotation.status !== 'accepted' && (
                                    <Button size="sm" variant="secondary" loading={decideForm.processing} onClick={accept}>
                                        Accepter
                                    </Button>
                                )}
                                {quotation.status !== 'rejected' &&
                                    (confirmReject ? (
                                        <form onSubmit={reject} className="w-full space-y-2">
                                            <textarea
                                                value={decideForm.data.reason}
                                                onChange={(e) => decideForm.setData('reason', e.target.value)}
                                                placeholder="Motif du refus (facultatif)"
                                                className="w-full rounded-field border border-line-strong px-2 py-1 text-sm"
                                            />
                                            <div className="flex gap-2">
                                                <Button size="sm" variant="danger" type="submit" loading={decideForm.processing}>
                                                    Confirmer le refus
                                                </Button>
                                                <button type="button" onClick={() => setConfirmReject(false)} className="text-xs text-ink-muted hover:underline">
                                                    Annuler
                                                </button>
                                            </div>
                                        </form>
                                    ) : (
                                        <button type="button" onClick={() => setConfirmReject(true)} className="text-sm text-danger hover:underline">
                                            Refuser
                                        </button>
                                    ))}
                            </div>
                        )}

                        {can.duplicate && (
                            <div className="mt-3 border-t border-line pt-3">
                                <button
                                    type="button"
                                    onClick={() => router.post(`/quotations/${quotation.id}/duplicate`)}
                                    className="text-sm text-ink-muted hover:text-ink hover:underline"
                                >
                                    Dupliquer ce devis
                                </button>
                                <p className="mt-1 text-xs text-ink-faint">Crée un nouveau devis commercial distinct, sans lien d’historique.</p>
                            </div>
                        )}
                    </section>

                    {sharing && (
                        <ShareCard
                            quotationId={quotation.id}
                            sharing={sharing}
                            canEmail={can.email}
                            mailConfigured={mailConfigured}
                            canConfigureMail={can.configureMail}
                        />
                    )}

                    {sharing && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-sm font-semibold text-ink">Cachet de l’entreprise</h2>
                            <div className="mt-3">
                                <StampDocumentAction
                                    postUrl={`/quotations/${quotation.id}/stamp`}
                                    applied={stamp.applied}
                                    appliedAt={stamp.appliedAt}
                                    canStamp={can.stamp}
                                />
                            </div>
                        </section>
                    )}

                    {history.length > 0 && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Historique</h2>
                            <ul className="mt-2 space-y-2 text-sm">
                                {history.map((entry) => (
                                    <li key={entry.id} className="flex flex-col">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-ink-muted">{entry.label}</span>
                                            {entry.id === quotation.id ? (
                                                <span className="font-medium text-ink">{entry.quotation_number ?? 'Brouillon'}</span>
                                            ) : (
                                                <Link href={`/quotations/${entry.id}`} className="font-medium text-ink underline">
                                                    {entry.quotation_number ?? 'Brouillon'}
                                                </Link>
                                            )}
                                        </div>
                                        <span className="text-xs text-ink-faint">
                                            {entry.is_current ? 'Version actuelle' : label(quotationStatusLabel, entry.status)}
                                            {entry.issued_at && ` · émise le ${formatDate(entry.issued_at)}`}
                                        </span>
                                        {entry.reason && <span className="text-xs text-ink-muted">Motif : {entry.reason}</span>}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-3 text-xs text-ink-faint">
                                Chaque version émise conserve son propre PDF. Ouvrez une version pour consulter son document.
                            </p>
                        </section>
                    )}

                    {editable && (
                        <section className="rounded-card border border-line bg-surface p-5">
                            <h2 className="text-sm font-semibold text-ink">Informations variables</h2>
                            <form onSubmit={saveHeader} className="mt-3 space-y-3 text-sm">
                                <div className="grid grid-cols-2 gap-2">
                                    <Field label="Date du devis">
                                        <input type="date" value={header.data.quotation_date} onChange={(e) => header.setData('quotation_date', e.target.value)} className={input} />
                                    </Field>
                                    <Field label="Valable jusqu’au">
                                        <input type="date" value={header.data.valid_until} onChange={(e) => header.setData('valid_until', e.target.value)} className={input} />
                                    </Field>
                                </div>
                                <Field label="Représentant">
                                    <input value={header.data.representative_name} onChange={(e) => header.setData('representative_name', e.target.value)} className={input} />
                                </Field>
                                <Field label="Nom / raison sociale du client">
                                    <input value={header.data.customer_name} onChange={(e) => header.setData('customer_name', e.target.value)} className={input} />
                                </Field>
                                <Field label="Société">
                                    <input value={header.data.customer_company} onChange={(e) => header.setData('customer_company', e.target.value)} className={input} />
                                </Field>
                                <Field label="ICE client">
                                    <input value={header.data.customer_tax_identifier} onChange={(e) => header.setData('customer_tax_identifier', e.target.value)} className={input} />
                                </Field>
                                <div className="grid grid-cols-2 gap-2">
                                    <Field label="Email">
                                        <input type="email" value={header.data.customer_email} onChange={(e) => header.setData('customer_email', e.target.value)} className={input} />
                                    </Field>
                                    <Field label="Téléphone">
                                        <input value={header.data.customer_phone} onChange={(e) => header.setData('customer_phone', e.target.value)} className={input} />
                                    </Field>
                                </div>
                                <Field label="Adresse de facturation">
                                    <textarea value={header.data.billing_address} onChange={(e) => header.setData('billing_address', e.target.value)} className={input} />
                                </Field>
                                <Field label="Notes">
                                    <textarea value={header.data.notes} onChange={(e) => header.setData('notes', e.target.value)} className={input} />
                                </Field>
                                <Field label="Conditions">
                                    <textarea value={header.data.terms} onChange={(e) => header.setData('terms', e.target.value)} className={input} />
                                </Field>
                                {Object.values(header.errors).map((err) => err && <p key={err} className="text-sm text-danger">{err}</p>)}
                                <Button type="submit" variant="secondary" loading={header.processing} loadingText="Enregistrement…">
                                    Enregistrer le brouillon
                                </Button>
                            </form>
                        </section>
                    )}
                </aside>
            </div>
        </SalesLayout>
    );
}

const input = 'w-full rounded-field border border-line-strong px-3 py-2';

function SummaryRow({ term, value }: { term: string; value: string }) {
    return (
        <div className="flex justify-between text-ink-muted">
            <span>{term}</span>
            <span className="tabular-nums text-ink">{value}</span>
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
    quotationId,
    sharing,
    canEmail,
    mailConfigured,
    canConfigureMail,
}: {
    quotationId: number;
    sharing: Sharing;
    canEmail: boolean;
    mailConfigured: boolean;
    canConfigureMail: boolean;
}) {
    const [tab, setTab] = useState<'email' | 'whatsapp'>('email');
    const [waPhone, setWaPhone] = useState(sharing.phone ?? '');
    const [waMessage, setWaMessage] = useState(`${sharing.whatsappMessage}\nPDF : ${sharing.pdfUrl}`);
    const [emailModalOpen, setEmailModalOpen] = useState(false);

    const openWhatsApp = () => {
        const digits = waPhone.replace(/\D/g, '');
        const base = digits ? `https://wa.me/${digits}` : 'https://wa.me/';
        window.open(`${base}?text=${encodeURIComponent(waMessage)}`, '_blank', 'noopener,noreferrer');
    };

    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <h2 className="text-sm font-semibold text-ink">Envoyer le devis</h2>
            <div role="tablist" className="mt-3 inline-flex rounded-field border border-line-strong p-0.5 text-sm">
                {(['email', 'whatsapp'] as const).map((key) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={tab === key}
                        onClick={() => setTab(key)}
                        className={`rounded-[7px] px-3 py-1 font-medium transition-soft ${tab === key ? 'bg-primary text-primary-fg' : 'text-ink-muted hover:text-ink'}`}
                    >
                        {key === 'email' ? 'Email' : 'WhatsApp'}
                    </button>
                ))}
            </div>

            {tab === 'email' && (
                <div className="mt-3 space-y-2 text-sm">
                    {!mailConfigured && (
                        <p className="text-xs text-warning">
                            Configuration e-mail requise avant l’envoi.
                            {canConfigureMail && (
                                <>
                                    {' '}
                                    <Link href="/email-settings" className="font-medium underline">
                                        Configurer
                                    </Link>
                                </>
                            )}
                        </p>
                    )}
                    {!canEmail && <p className="text-xs text-ink-muted">Vous n’avez pas la permission d’envoyer des devis par email.</p>}
                    <Button type="button" variant="secondary" disabled={!canEmail} onClick={() => setEmailModalOpen(true)}>
                        Envoyer le devis par e-mail
                    </Button>
                    <SendDocumentEmailModal
                        open={emailModalOpen}
                        onClose={() => setEmailModalOpen(false)}
                        postUrl={`/quotations/${quotationId}/email`}
                        title="Envoyer le devis par e-mail"
                        defaultTo={sharing.email ?? ''}
                        defaultSubject={sharing.defaultSubject}
                        defaultMessage={`Bonjour,\n\nVeuillez trouver ci-joint notre devis.\n\nCordialement.`}
                        attachmentName={sharing.attachmentName}
                        canSend={canEmail}
                        mailConfigured={mailConfigured}
                        canConfigureMail={canConfigureMail}
                    />
                </div>
            )}

            {tab === 'whatsapp' && (
                <div className="mt-3 space-y-2 text-sm">
                    <Field label="Téléphone">
                        <input value={waPhone} onChange={(e) => setWaPhone(e.target.value)} placeholder="+212 6 12 34 56 78" className={input} />
                    </Field>
                    <Field label="Message">
                        <textarea rows={5} value={waMessage} onChange={(e) => setWaMessage(e.target.value)} className={input} />
                    </Field>
                    <p className="text-xs text-ink-faint">
                        Le lien PDF est temporaire et sécurisé. WhatsApp n’attache pas le fichier automatiquement — le message s’ouvre, vous l’envoyez depuis WhatsApp.
                    </p>
                    <Button type="button" variant="secondary" onClick={openWhatsApp}>
                        Ouvrir dans WhatsApp
                    </Button>
                </div>
            )}
        </section>
    );
}
