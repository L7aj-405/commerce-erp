import DocBadge from '@/components/ui/DocBadge';
import { Button } from '@/components/ui/Button';
import SalesLayout from '@/layouts/SalesLayout';
import OrderLineGrid, { type OLine } from './OrderLineGrid';
import { formatMoney } from '@/utils/format';
import { label, orderStatusLabel, orderStatusTone } from '@/utils/labels';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';

type TaxRate = { id: number; name: string; rate: string };
type Order = {
    id: number;
    order_number: string;
    customer_id: number | null;
    customer_name: string | null;
    customer_company: string | null;
    sale_date: string;
    currency_code: string;
    notes: string | null;
    status: string;
    subtotal_excl_tax: string;
    discount_total: string;
    discount_total_ttc?: string;
    tax_total: string;
    total_incl_tax: string;
    lines: OLine[];
};
type OriginatingQuotation = { id: number; number: string | null; status: string; revision_number: number };
type Correction = { revision_number: number; reason: string; initiated_at: string | null };
type Props = {
    order: Order;
    taxRates: TaxRate[];
    lineSearchUrl: string;
    customerSearchUrl: string;
    isEditable: boolean;
    correction: Correction | null;
    procurementUnderCovered: number;
    originatingQuotation: OriginatingQuotation | null;
    can: { update: boolean; confirm: boolean; overridePrice: boolean; applyDiscount: boolean };
};

export default function EditOrder({ order, taxRates, lineSearchUrl, customerSearchUrl, isEditable, correction, procurementUnderCovered, originatingQuotation, can }: Props) {
    const currency = order.currency_code;
    const header = useForm({
        customer_id: order.customer_id,
        sale_date: order.sale_date.slice(0, 10),
        currency_code: order.currency_code,
        notes: order.notes ?? '',
    });
    const [customerLabel, setCustomerLabel] = useState(order.customer_company || order.customer_name || '');
    const confirmation = useForm({});
    const confirmationErrors = Object.values(confirmation.errors as Record<string, string>);
    const net = (Number(order.subtotal_excl_tax) - Number(order.discount_total)).toFixed(4);
    const hasDiscount = Number(order.discount_total_ttc ?? order.discount_total) > 0;

    const saveHeader = (e: FormEvent) => {
        e.preventDefault();
        header.patch(`/sales/orders/${order.id}`, { preserveScroll: true });
    };

    return (
        <SalesLayout>
            <Head title={`Modifier la commande ${order.order_number}`} />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link href="/sales/orders" className="text-sm text-ink-muted">
                        ← Commandes
                    </Link>
                    <div className="mt-1 flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">Modifier la commande {order.order_number}</h1>
                        <DocBadge tone={orderStatusTone(order.status)}>{label(orderStatusLabel, order.status)}</DocBadge>
                    </div>
                    {originatingQuotation && (
                        <p className="mt-1 text-sm text-ink-muted">
                            Créée depuis le devis{' '}
                            <Link href={`/quotations/${originatingQuotation.id}`} className="underline">
                                {originatingQuotation.number ?? 'brouillon'}
                                {originatingQuotation.revision_number > 0 ? ` · Révision ${originatingQuotation.revision_number}` : ''}
                            </Link>
                        </p>
                    )}
                </div>
                <Link
                    href={`/sales/orders/${order.id}`}
                    className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                >
                    Voir la commande
                </Link>
            </div>

            {correction && (
                <section className="mb-6 rounded-card border border-warning/30 bg-warning-soft/50 px-4 py-4">
                    <p className="font-semibold text-ink">Correction de la commande en cours</p>
                    <p className="mt-1 text-sm text-ink-muted">Motif : {correction.reason}</p>
                    <p className="mt-1 text-xs text-ink-faint">Correction {correction.revision_number} — la facture déjà émise reste inchangée jusqu’à la validation.</p>
                </section>
            )}

            {isEditable && procurementUnderCovered > 0 && (
                <div className="mb-6 rounded-card border border-warning/30 bg-warning-soft/50 px-4 py-3 text-sm text-warning">
                    {procurementUnderCovered === 1 ? 'Une ligne dépasse' : `${procurementUnderCovered} lignes dépassent`} le stock société
                    disponible. Approvisionnez la quantité manquante auprès d’un fournisseur depuis la{' '}
                    <Link href={`/sales/orders/${order.id}`} className="font-medium underline">
                        fiche commande
                    </Link>{' '}
                    avant de confirmer.
                </div>
            )}

            {!isEditable && (
                <div className="mb-6 rounded-card border border-warning/30 bg-warning-soft/50 px-4 py-3 text-sm text-warning">
                    Cette commande est {label(orderStatusLabel, order.status).toLowerCase()} et n’est plus modifiable. Les lignes, les
                    prix et les réservations sont figés.{' '}
                    <Link href={`/sales/orders/${order.id}`} className="font-medium underline">
                        Voir la commande
                    </Link>
                    .
                </div>
            )}

            {/* HEADER */}
            <form onSubmit={saveHeader} className="mb-6 rounded-card border border-line bg-surface p-5">
                <div className="grid gap-4 md:grid-cols-3">
                    <label className="block text-sm">
                        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-muted">Client</span>
                        <CustomerPicker
                            searchUrl={customerSearchUrl}
                            disabled={!isEditable}
                            value={customerLabel}
                            onPick={(c) => {
                                header.setData('customer_id', c ? c.id : null);
                                setCustomerLabel(c ? c.company_name || c.display_name : '');
                            }}
                        />
                    </label>
                    <label className="block text-sm">
                        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-muted">Date de vente</span>
                        <input
                            type="date"
                            disabled={!isEditable}
                            value={header.data.sale_date}
                            onChange={(e) => header.setData('sale_date', e.target.value)}
                            className="w-full rounded-field border border-line-strong px-3 py-2 disabled:bg-raised"
                        />
                    </label>
                    <div className="flex items-end text-xs text-ink-faint">Devise : {order.currency_code}</div>
                    <label className="block text-sm md:col-span-3">
                        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-muted">Notes</span>
                        <textarea
                            disabled={!isEditable}
                            value={header.data.notes}
                            onChange={(e) => header.setData('notes', e.target.value)}
                            rows={2}
                            className="w-full rounded-field border border-line-strong px-3 py-2 disabled:bg-raised"
                        />
                    </label>
                </div>
                {Object.values(header.errors).map((err) => err && <p key={err} className="mt-2 text-sm text-danger">{err}</p>)}
                {isEditable && (
                    <div className="mt-3">
                        <Button type="submit" variant="secondary" loading={header.processing} loadingText="Enregistrement…">
                            Enregistrer
                        </Button>
                    </div>
                )}
            </form>

            {/* ARTICLES */}
            <section className="mb-6 rounded-card border border-line bg-surface p-5">
                <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-muted">Articles</h2>
                {isEditable ? (
                    <OrderLineGrid
                        orderId={order.id}
                        currency={currency}
                        lines={order.lines}
                        searchUrl={lineSearchUrl}
                        taxRates={taxRates}
                        canOverridePrice={can.overridePrice}
                        canApplyDiscount={can.applyDiscount}
                    />
                ) : (
                    <ReadOnlyLines lines={order.lines} currency={currency} />
                )}
            </section>

            {/* TOTALS */}
            <div className="ml-auto max-w-xs space-y-1 text-sm">
                <Row term="Total HT" value={formatMoney(hasDiscount ? net : order.subtotal_excl_tax, currency)} />
                <Row term="TVA" value={formatMoney(order.tax_total, currency)} />
                {hasDiscount && <Row term="Remise TTC" value={`- ${formatMoney(order.discount_total_ttc ?? order.discount_total, currency)}`} />}
                <div className="flex justify-between border-t-2 border-line pt-1 text-base font-bold text-ink">
                    <span>TOTAL TTC</span>
                    <span className="tabular-nums">{formatMoney(order.total_incl_tax, currency)}</span>
                </div>
            </div>

            {correction && isEditable && can.confirm && (
                <div className="mt-6 flex flex-col items-stretch gap-2 rounded-card border border-line bg-surface p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-ink-muted">Cette action recalcule les réservations et fige le nouvel état commercial.</p>
                    <Button
                        type="button"
                        loading={confirmation.processing}
                        loadingText="Validation…"
                        onClick={() => confirmation.post(`/sales/orders/${order.id}/confirm`)}
                    >
                        Valider la correction
                    </Button>
                </div>
            )}
            {confirmationErrors.map((error) => error && <p key={error} className="mt-2 text-sm text-danger">{error}</p>)}
        </SalesLayout>
    );
}

function Row({ term, value }: { term: string; value: string }) {
    return (
        <div className="flex justify-between text-ink-muted">
            <span>{term}</span>
            <span className="tabular-nums text-ink">{value}</span>
        </div>
    );
}

function ReadOnlyLines({ lines, currency }: { lines: OLine[]; currency: string }) {
    if (lines.length === 0) return <p className="text-sm text-ink-muted">Aucun article.</p>;
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-sm">
                <thead>
                    <tr className="border-b border-line-strong text-left text-xs uppercase tracking-wide text-ink-muted [&>th]:px-2 [&>th]:py-2 [&>th]:font-medium">
                        <th>Article</th>
                        <th className="text-right">Qté</th>
                        <th className="text-right">PU HT</th>
                        <th className="text-right">Remise TTC</th>
                        <th className="text-right">TVA</th>
                        <th className="text-right">Total TTC</th>
                    </tr>
                </thead>
                <tbody className="[&>tr>td]:border-b [&>tr>td]:border-line [&>tr>td]:px-2 [&>tr>td]:py-2">
                    {lines.map((line) => (
                        <tr key={line.id}>
                            <td>
                                <span className="font-medium text-ink">{line.product_name}</span>
                                {line.variant_name && <span className="block text-xs text-ink-muted">{line.variant_name}</span>}
                            </td>
                            <td className="text-right tabular-nums">{line.quantity.replace(/\.?0+$/, '')}</td>
                            <td className="text-right tabular-nums">{formatMoney(line.unit_price_excl_tax, currency)}</td>
                            <td className="text-right tabular-nums">{Number(line.discount_amount_ttc ?? line.discount_amount) > 0 ? `- ${formatMoney(line.discount_amount_ttc ?? line.discount_amount, currency)}` : '—'}</td>
                            <td className="text-right tabular-nums text-ink-muted">
                                {line.tax_unresolved ? 'À définir' : `${Number(line.tax_rate)} %`}
                            </td>
                            <td className="text-right font-medium tabular-nums text-ink">{formatMoney(line.total_incl_tax, currency)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

type CustomerHit = { id: number; display_name: string; company_name: string | null; phone: string | null; email: string | null };

function CustomerPicker({
    searchUrl,
    value,
    disabled,
    onPick,
}: {
    searchUrl: string;
    value: string;
    disabled: boolean;
    onPick: (c: CustomerHit | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [rows, setRows] = useState<CustomerHit[]>([]);
    const [loading, setLoading] = useState(false);
    const reqId = useRef(0);

    useEffect(() => {
        if (!open) return;
        const ctrl = new AbortController();
        const id = ++reqId.current;
        const h = window.setTimeout(async () => {
            setLoading(true);
            try {
                const url = new URL(searchUrl, window.location.origin);
                if (query.trim() !== '') url.searchParams.set('search', query.trim());
                const res = await fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: ctrl.signal });
                if (res.ok && id === reqId.current) setRows(((await res.json()) as { data: CustomerHit[] }).data);
            } catch {
                /* aborted */
            } finally {
                if (id === reqId.current) setLoading(false);
            }
        }, 250);
        return () => {
            ctrl.abort();
            window.clearTimeout(h);
        };
    }, [query, searchUrl, open]);

    if (!open) {
        return (
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => setOpen(true)}
                    className="flex-1 rounded-field border border-line-strong px-3 py-2 text-left disabled:bg-raised"
                >
                    {value || <span className="text-ink-faint">Client comptoir</span>}
                </button>
                {value && !disabled && (
                    <button type="button" onClick={() => onPick(null)} className="text-xs text-ink-muted hover:underline">
                        Retirer
                    </button>
                )}
            </div>
        );
    }

    return (
        <div className="rounded-field border border-line-strong p-2">
            <input
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Nom, société, téléphone, email…"
                className="w-full rounded-field border border-line-strong px-2 py-1 text-sm"
            />
            <ul className="mt-1 max-h-48 divide-y divide-line overflow-y-auto">
                <li>
                    <button
                        type="button"
                        onClick={() => {
                            onPick(null);
                            setOpen(false);
                        }}
                        className="block w-full px-1 py-1.5 text-left text-xs text-ink-muted hover:bg-raised"
                    >
                        Client comptoir (aucun)
                    </button>
                </li>
                {loading && <li className="px-1 py-1.5 text-xs text-ink-muted">Recherche…</li>}
                {!loading &&
                    rows.map((row) => (
                        <li key={row.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onPick(row);
                                    setOpen(false);
                                }}
                                className="block w-full px-1 py-1.5 text-left hover:bg-raised"
                            >
                                <span className="text-sm font-medium text-ink">{row.company_name || row.display_name}</span>
                                <span className="block text-xs text-ink-faint">{[row.phone, row.email].filter(Boolean).join(' · ') || '—'}</span>
                            </button>
                        </li>
                    ))}
            </ul>
            <button type="button" onClick={() => setOpen(false)} className="mt-1 text-xs text-ink-muted hover:underline">
                Fermer
            </button>
        </div>
    );
}
