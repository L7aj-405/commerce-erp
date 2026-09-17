import ProductImage from '@/components/catalog/ProductImage';
import { Money, NumericInput, Quantity, SegmentedControl } from '@/components/pos/primitives';
import { Button } from '@/components/ui/Button';
import { InlineLoader, Spinner } from '@/components/ui/Spinner';
import { useEffect, useRef, useState } from 'react';
import type { PosPending } from './pos-shared';
import PosProcurementPanel, { type ProcurementSubmit } from './PosProcurementPanel';
import type { ActiveSale, CartLine, Supplier } from './types';

type Props = {
    sale: ActiveSale | null;
    currencyCode: string;
    pending: PosPending;
    canApplyDiscount: boolean;
    heldCount: number;
    suppliers: Supplier[];
    canManageProcurement: boolean;
    procurementBusy: boolean;
    onProcurementSubmit: (payload: ProcurementSubmit) => Promise<void>;
    onQuantityChange: (line: CartLine, quantity: number) => void;
    onDiscountChange: (line: CartLine, type: CartLine['discount_type'], value: string) => void;
    onRemove: (line: CartLine) => void;
    onGlobalDiscountChange: (type: 'none' | 'fixed' | 'percentage', value: string) => void;
    onHold: () => void;
    onNewSale: () => void;
    onOpenHeld: () => void;
    onCheckout: () => void;
    /** Present only in the mobile full-screen cart overlay: returns to the catalogue. */
    onMobileBack?: () => void;
};

type DiscountUnit = 'percentage' | 'fixed';

function LineDiscount({ line, pending, onChange }: { line: CartLine; pending: boolean; onChange: (type: CartLine['discount_type'], value: string) => void }) {
    const serverValue = line.discount_value === '0.0000' ? '' : line.discount_value;
    const [unit, setUnit] = useState<DiscountUnit>(line.discount_type === 'fixed' ? 'fixed' : 'percentage');
    const [raw, setRaw] = useState(serverValue);

    // Keep local state aligned when the server line changes (e.g. after a reprice).
    useEffect(() => {
        setRaw(serverValue);
        if (line.discount_type !== 'none') setUnit(line.discount_type);
    }, [line.discount_type, serverValue]);

    const commit = (nextUnit: DiscountUnit, nextRaw: string) => {
        const positive = nextRaw !== '' && Number(nextRaw) > 0;
        onChange(positive ? nextUnit : 'none', positive ? nextRaw : '0');
    };

    return (
        <div className="mt-2 flex items-center gap-1.5">
            <span className="flex items-center gap-1 text-[11px] text-ink-muted">Remise {pending && <Spinner size="xs" />}</span>
            <NumericInput
                value={raw}
                onValueChange={setRaw}
                onCommit={(next) => commit(unit, next)}
                placeholder="0"
                className="h-7 w-14 rounded-field border border-line-strong bg-surface px-2 text-[12px] outline-none focus:border-primary"
            />
            <SegmentedControl
                size="sm"
                ariaLabel="Type de remise"
                value={unit}
                onChange={(next) => {
                    setUnit(next);
                    commit(next, raw);
                }}
                options={[
                    { value: 'percentage', label: '%' },
                    { value: 'fixed', label: 'DH' },
                ]}
            />
        </div>
    );
}

function GlobalDiscount({ sale, pending, onChange }: { sale: ActiveSale; pending: boolean; onChange: (type: 'none' | 'fixed' | 'percentage', value: string) => void }) {
    const serverValue = sale.checkout.global_discount_value === '0.0000' ? '' : sale.checkout.global_discount_value;
    const [unit, setUnit] = useState<DiscountUnit>(sale.checkout.global_discount_type === 'fixed' ? 'fixed' : 'percentage');
    const [raw, setRaw] = useState(serverValue);

    useEffect(() => {
        setRaw(serverValue);
        if (sale.checkout.global_discount_type !== 'none') setUnit(sale.checkout.global_discount_type);
    }, [sale.checkout.global_discount_type, serverValue]);

    const commit = (nextUnit: DiscountUnit, nextRaw: string) => {
        const positive = nextRaw !== '' && Number(nextRaw) > 0;
        onChange(positive ? nextUnit : 'none', positive ? nextRaw : '0');
    };

    return (
        <div className="mb-3 flex items-center gap-2">
            <span className="text-[12px] text-ink-muted">Remise globale</span>
            <SegmentedControl
                size="sm"
                ariaLabel="Type de remise globale"
                value={unit}
                onChange={(next) => {
                    setUnit(next);
                    commit(next, raw);
                }}
                options={[
                    { value: 'percentage', label: '%' },
                    { value: 'fixed', label: 'DH' },
                ]}
            />
            <NumericInput
                value={raw}
                onValueChange={setRaw}
                onCommit={(next) => commit(unit, next)}
                placeholder="0"
                className="h-7 w-16 rounded-field border border-line-strong bg-surface px-2 text-[12px] outline-none focus:border-primary"
            />
            {pending && <Spinner size="xs" className="text-ink-muted" />}
        </div>
    );
}

export default function PosCartPanel({
    sale,
    currencyCode,
    pending,
    canApplyDiscount,
    heldCount,
    suppliers,
    canManageProcurement,
    procurementBusy,
    onProcurementSubmit,
    onQuantityChange,
    onDiscountChange,
    onRemove,
    onGlobalDiscountChange,
    onHold,
    onNewSale,
    onOpenHeld,
    onCheckout,
    onMobileBack,
}: Props) {
    const lines = sale?.lines ?? [];
    const summary = sale?.summary;
    const count = lines.reduce((carry, line) => carry + Math.trunc(Number(line.quantity) || 0), 0);
    const blocked = !sale || lines.length === 0 || (sale?.availability_warnings.length ?? 0) > 0;
    const procurementDeficit = sale?.procurement_deficit === true;
    const awaitingProcurement = sale?.awaiting_supplier_procurement === true;
    const [showProcurement, setShowProcurement] = useState(false);

    // Subtle pulse on the cart counter whenever it grows.
    const [bump, setBump] = useState(false);
    const prevCount = useRef(count);
    useEffect(() => {
        if (count > prevCount.current) {
            setBump(true);
            const timer = window.setTimeout(() => setBump(false), 320);
            prevCount.current = count;
            return () => window.clearTimeout(timer);
        }
        prevCount.current = count;
    }, [count]);

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {/* Header */}
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-line px-4 py-3">
                <div className="flex min-w-0 items-center gap-1.5">
                    {onMobileBack && (
                        <button
                            type="button"
                            onClick={onMobileBack}
                            aria-label="Retour au catalogue"
                            className="flex size-9 shrink-0 items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 6 8 12l6 6" /></svg>
                        </button>
                    )}
                    <div className="min-w-0">
                        <h2 className="text-sm font-semibold text-ink">
                            Panier{' '}
                            {count > 0 && (
                                <span className={`inline-block text-ink-muted transition-transform duration-200 ${bump ? 'scale-125 text-primary' : ''}`}>({count})</span>
                            )}
                        </h2>
                        <p className="truncate text-[12px] text-ink-muted">{sale ? sale.order_number : 'Nouvelle vente'}</p>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    <button
                        type="button"
                        onClick={onOpenHeld}
                        className="rounded-field border border-line-strong px-2.5 py-1.5 text-[12px] font-medium text-ink-muted transition-soft hover:text-ink"
                    >
                        En attente ({heldCount})
                    </button>
                    <button
                        type="button"
                        onClick={onNewSale}
                        className="rounded-field border border-line-strong px-2.5 py-1.5 text-[12px] font-medium text-ink-muted transition-soft hover:text-ink"
                    >
                        Nouvelle
                    </button>
                </div>
            </div>

            {/* Items (internal scroll) */}
            <div className="min-h-0 flex-1 overflow-y-auto">
                {lines.length === 0 ? (
                    <div className="flex h-full flex-col items-center justify-center px-8 text-center">
                        <span className="grid size-11 place-items-center rounded-full bg-sage text-ink-faint">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><path d="M6 6h15l-1.5 9h-12z" /><circle cx="9" cy="20" r="1.4" /><circle cx="18" cy="20" r="1.4" /><path d="M6 6 5 3H3" /></svg>
                        </span>
                        <p className="mt-3 text-sm font-medium text-ink">Le panier est vide</p>
                        <p className="mt-1 text-[13px] text-ink-muted">Cliquez sur un produit pour l’ajouter.</p>
                    </div>
                ) : (
                    <ul className="divide-y divide-line">
                        {lines.map((line) => {
                            const removing = pending.removing(line.id);
                            const linePending = pending.line(line.id);
                            return (
                            <li key={line.id} className={`p-3 transition-opacity ${removing ? 'pointer-events-none opacity-45' : ''}`}>
                                <div className="flex gap-2.5">
                                    <div className="size-11 shrink-0 overflow-hidden rounded-field bg-raised">
                                        <ProductImage name={line.description} imageUrl={line.image_url} className="h-full w-full p-1" roundedClassName="rounded-none" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-[13px] font-semibold text-ink">{line.description}</p>
                                                {(line.sku || line.reference) && (
                                                    <p className="truncate text-[11px] text-ink-muted">{line.sku ?? line.reference}</p>
                                                )}
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => onRemove(line)}
                                                disabled={removing}
                                                aria-busy={removing || undefined}
                                                aria-label="Retirer"
                                                className="shrink-0 rounded p-0.5 text-ink-faint transition-soft hover:text-danger disabled:opacity-50"
                                            >
                                                {removing ? (
                                                    <Spinner size="xs" />
                                                ) : (
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                                )}
                                            </button>
                                        </div>

                                        <div className="mt-2 flex items-center justify-between gap-2">
                                            <div className="inline-flex items-center rounded-full border border-line-strong">
                                                <button
                                                    type="button"
                                                    disabled={removing || Number(line.quantity) <= 1}
                                                    onClick={() => onQuantityChange(line, Number(line.quantity) - 1)}
                                                    aria-label="Diminuer la quantité"
                                                    className="grid size-9 place-items-center rounded-l-full text-ink-muted transition-soft hover:text-ink disabled:opacity-30"
                                                >
                                                    –
                                                </button>
                                                <span className="flex min-w-8 items-center justify-center gap-1 text-center text-[13px] font-semibold text-ink" aria-live="polite" aria-busy={linePending || undefined}>
                                                    {Math.max(1, Math.trunc(Number(line.quantity) || 1))}
                                                    {linePending && <Spinner size="xs" className="text-ink-faint" />}
                                                </span>
                                                <button
                                                    type="button"
                                                    disabled={removing}
                                                    onClick={() => onQuantityChange(line, Number(line.quantity) + 1)}
                                                    aria-label="Augmenter la quantité"
                                                    className="grid size-9 place-items-center rounded-r-full text-ink-muted transition-soft hover:text-ink disabled:opacity-30"
                                                >
                                                    +
                                                </button>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-[11px] text-ink-muted">
                                                    <Money value={line.unit_price_incl_tax ?? line.unit_price_excl_tax} currency={currencyCode} /> TTC / u.
                                                </p>
                                                {line.discount_type !== 'none' && (
                                                    <p className="text-[11px] text-ink-faint line-through"><Money value={line.line_subtotal ?? '0'} currency={currencyCode} /></p>
                                                )}
                                                <p className="text-[13px] font-semibold text-ink"><Money value={line.line_total ?? '0'} currency={currencyCode} /></p>
                                            </div>
                                        </div>

                                        {canApplyDiscount && (
                                            <LineDiscount line={line} pending={linePending} onChange={(type, value) => onDiscountChange(line, type, value)} />
                                        )}

                                        {line.line_type === 'catalog' &&
                                            (Number(line.to_procure ?? '0') > 0 || line.procurement || line.requires_replenishment) && (
                                                <div className="mt-1.5 space-y-0.5 text-[11px]">
                                                    {Number(line.to_procure ?? '0') > 0 && (
                                                        <p className={line.needs_procurement ? 'text-warning' : 'text-ink-muted'}>
                                                            Disponible société : <Quantity value={line.company_covered ?? '0'} />
                                                            {' · À approvisionner : '}
                                                            <Quantity value={line.to_procure ?? '0'} />
                                                        </p>
                                                    )}
                                                    {line.procurement && (
                                                        <p className="text-ink-muted">
                                                            {line.procurement.supplier?.name ?? 'Fournisseur'} · {line.procurement.status_label}
                                                        </p>
                                                    )}
                                                    {!line.procurement && line.needs_procurement && (
                                                        <p className="font-medium text-warning">À approvisionner fournisseur</p>
                                                    )}
                                                    {line.requires_replenishment && Number(line.to_procure ?? '0') <= 0 && (
                                                        <p className="text-warning">
                                                            <Quantity value={line.remote_required ?? '0'} /> depuis un autre entrepôt
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                    </div>
                                </div>
                            </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {/* Supplier special-order sourcing */}
            {sale && showProcurement && procurementDeficit && canManageProcurement && (
                <PosProcurementPanel
                    sale={sale}
                    suppliers={suppliers}
                    busy={procurementBusy}
                    onSubmit={onProcurementSubmit}
                    onClose={() => setShowProcurement(false)}
                />
            )}

            {/* Sticky totals + actions */}
            <div className="shrink-0 border-t border-line bg-raised p-3.5">
                {(procurementDeficit || awaitingProcurement) && (
                    <div className="mb-3 rounded-field border border-warning/30 bg-warning-soft/40 px-3 py-2 text-[12px] text-warning">
                        {procurementDeficit ? (
                            <>
                                Des articles sont en rupture — à commander auprès d’un fournisseur avant l’encaissement.
                                {canManageProcurement && (
                                    <button
                                        type="button"
                                        onClick={() => setShowProcurement((v) => !v)}
                                        className="ml-1 font-semibold underline"
                                    >
                                        {showProcurement ? 'Masquer' : 'Approvisionner auprès d’un fournisseur'}
                                    </button>
                                )}
                            </>
                        ) : (
                            'En attente fournisseur — la marchandise sera réservée à la commande à sa réception.'
                        )}
                    </div>
                )}
                {canApplyDiscount && sale && lines.length > 0 && <GlobalDiscount sale={sale} pending={pending.globalDiscount} onChange={onGlobalDiscountChange} />}

                <dl className={`space-y-1 text-[13px] transition-opacity ${pending.recalculating ? 'opacity-70' : ''}`}>
                    <div className="flex justify-between"><dt className="text-ink-muted">Sous-total HT</dt><dd><Money value={summary?.subtotal_excl_tax ?? '0'} currency={currencyCode} /></dd></div>
                    {Number(summary?.line_discount_total ?? 0) > 0 && (
                        <div className="flex justify-between"><dt className="text-ink-muted">Remises articles</dt><dd className="text-success">- <Money value={summary?.line_discount_total ?? '0'} currency={currencyCode} /></dd></div>
                    )}
                    {Number(summary?.global_discount_amount ?? 0) > 0 && (
                        <div className="flex justify-between"><dt className="text-ink-muted">Remise globale</dt><dd className="text-success">- <Money value={summary?.global_discount_amount ?? '0'} currency={currencyCode} /></dd></div>
                    )}
                    <div className="flex justify-between"><dt className="text-ink-muted">TVA</dt><dd><Money value={summary?.tax_total ?? '0'} currency={currencyCode} /></dd></div>
                    {Number(summary?.shipping_fee ?? 0) > 0 && (
                        <div className="flex justify-between"><dt className="text-ink-muted">Livraison</dt><dd><Money value={summary?.shipping_fee ?? '0'} currency={currencyCode} /></dd></div>
                    )}
                    <div className="flex items-baseline justify-between border-t border-line pt-1.5">
                        <dt className="flex items-center gap-2 text-sm font-semibold text-ink">
                            Total TTC
                            {pending.recalculating && <InlineLoader label="Recalcul…" className="text-[11px] font-normal" />}
                        </dt>
                        <dd className="text-lg font-semibold text-ink"><Money value={summary?.total ?? '0'} currency={currencyCode} /></dd>
                    </div>
                </dl>

                <div className="mt-3 grid grid-cols-[auto_1fr] gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={!sale || lines.length === 0}
                        loading={pending.hold}
                        loadingText="Mise en attente…"
                        onClick={onHold}
                        className="px-3 py-2.5 text-[13px]"
                    >
                        En attente
                    </Button>
                    <button
                        type="button"
                        disabled={blocked || pending.recalculating}
                        onClick={onCheckout}
                        className="rounded-field bg-primary px-4 py-2.5 text-sm font-semibold text-primary-fg transition-soft hover:bg-primary-hover disabled:opacity-50"
                    >
                        Passer au paiement
                    </button>
                </div>
            </div>
        </div>
    );
}
