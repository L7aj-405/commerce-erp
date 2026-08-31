import ProductImage from '@/components/catalog/ProductImage';
import { formatMoney, formatQuantity } from '@/utils/format';
import { useMemo, useState } from 'react';
import type { ActiveSale, CartLine } from './types';

type Props = {
    sale: ActiveSale | null;
    currencyCode: string;
    busy: boolean;
    canApplyDiscount: boolean;
    onQuantityChange: (line: CartLine, quantity: number) => void;
    onDiscountChange: (line: CartLine, discountType: CartLine['discount_type'], discountValue: string) => void;
    onRemove: (line: CartLine) => void;
    onHold: () => void;
    onCheckout: () => void;
    onNewSale: () => void;
    onCheckoutFieldChange: (field: string, value: string) => void;
};

export default function PosCart({ sale, currencyCode, busy, canApplyDiscount, onQuantityChange, onDiscountChange, onRemove, onHold, onCheckout, onNewSale, onCheckoutFieldChange }: Props) {
    const [editingLineId, setEditingLineId] = useState<number | null>(null);
    const [discountType, setDiscountType] = useState<CartLine['discount_type']>('none');
    const [discountValue, setDiscountValue] = useState('0');
    const lines = sale?.lines ?? [];
    const summary = sale?.summary;

    const lineCountLabel = useMemo(() => `${lines.length} ligne${lines.length > 1 ? 's' : ''}`, [lines.length]);

    return (
        <aside className="xl:sticky xl:top-6">
            <section className="flex min-h-[48rem] flex-col rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div className="flex items-start justify-between gap-3 border-b border-slate-200 p-5">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-500">Vente en cours</p>
                        <h2 className="mt-1 text-xl font-semibold text-slate-950">{sale ? sale.order_number : 'Nouvelle vente'}</h2>
                        <p className="text-sm text-slate-500">{lineCountLabel}</p>
                    </div>
                    <button type="button" onClick={onNewSale} className="rounded-2xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700">
                        Nouvelle vente
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto">
                    {!sale || lines.length === 0 ? (
                        <div className="px-6 py-14 text-center">
                            <p className="text-base font-medium text-slate-900">Le panier est vide.</p>
                            <p className="mt-2 text-sm text-slate-500">Les produits du showroom apparaissent déjà à gauche. Ajoutez simplement les articles au panier.</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {lines.map((line) => (
                                <article key={line.id} className="space-y-4 p-5">
                                    <div className="flex gap-3">
                                        <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-slate-50">
                                            <ProductImage name={line.description} imageUrl={line.image_url} className="h-full w-full p-2" roundedClassName="rounded-none" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate font-semibold text-slate-950">{line.description}</p>
                                                    <div className="mt-1 space-y-1 text-xs text-slate-500">
                                                        {line.sku && <p>SKU: {line.sku}</p>}
                                                        {line.reference && <p>Réf: {line.reference}</p>}
                                                        {line.brand?.name && <p>{line.brand.name}</p>}
                                                    </div>
                                                </div>
                                                <button type="button" onClick={() => onRemove(line)} className="text-sm text-red-700">Retirer</button>
                                            </div>

                                            <div className="mt-4 flex items-center justify-between gap-3">
                                                <div className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 p-1">
                                                    <button type="button" disabled={busy || Number(line.quantity) <= 1} onClick={() => onQuantityChange(line, Number(line.quantity) - 1)} className="h-9 w-9 rounded-full text-lg text-slate-700 disabled:opacity-40">-</button>
                                                    <span className="min-w-10 text-center text-sm font-semibold">{Math.max(1, Math.trunc(Number(line.quantity) || 1))}</span>
                                                    <button type="button" disabled={busy} onClick={() => onQuantityChange(line, Number(line.quantity) + 1)} className="h-9 w-9 rounded-full text-lg text-slate-700 disabled:opacity-40">+</button>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-sm text-slate-500">{formatMoney(line.unit_price_excl_tax, currencyCode)}</p>
                                                    <p className="font-semibold text-slate-950">{formatMoney(line.line_total ?? '0', currencyCode)}</p>
                                                </div>
                                            </div>

                                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                                                <span className={line.insufficient ? 'font-medium text-red-700' : 'text-slate-500'}>
                                                    {line.available !== undefined ? `Disponible ici: ${formatQuantity(line.available)}` : 'Stock non disponible'}
                                                </span>
                                                {line.insufficient && <span className="rounded-full bg-red-50 px-2 py-1 text-red-700">Disponibilité modifiée</span>}
                                            </div>
                                        </div>
                                    </div>

                                    {canApplyDiscount && (
                                        <div className="rounded-2xl bg-slate-50 p-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-medium text-slate-900">Remise ligne</p>
                                                    <p className="text-xs text-slate-500">Prix négocié visible avant checkout.</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setEditingLineId(line.id);
                                                        setDiscountType(line.discount_type);
                                                        setDiscountValue(line.discount_value === '0.0000' ? '0' : line.discount_value);
                                                    }}
                                                    className="text-sm font-medium text-slate-700 underline"
                                                >
                                                    Modifier
                                                </button>
                                            </div>

                                            <div className="mt-2 flex items-center justify-between text-sm">
                                                <span className="text-slate-500">Remise actuelle</span>
                                                <span className="font-medium text-slate-900">{line.discount_type === 'none' ? 'Aucune' : `${line.discount_type === 'percentage' ? `${line.discount_value}%` : formatMoney(line.line_discount ?? '0', currencyCode)}`}</span>
                                            </div>

                                            {editingLineId === line.id && (
                                                <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                                    <select value={discountType} onChange={(event) => setDiscountType(event.target.value as CartLine['discount_type'])} className="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                                        <option value="none">Aucune remise</option>
                                                        <option value="percentage">Pourcentage</option>
                                                        <option value="fixed">Montant fixe</option>
                                                    </select>
                                                    <input value={discountValue} onChange={(event) => setDiscountValue(event.target.value)} inputMode="decimal" className="rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="0" />
                                                    <button
                                                        type="button"
                                                        disabled={busy}
                                                        onClick={() => {
                                                            onDiscountChange(line, discountType, discountType === 'none' ? '0' : discountValue);
                                                            setEditingLineId(null);
                                                        }}
                                                        className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                                    >
                                                        Appliquer
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </div>

                <div className="space-y-4 border-t border-slate-200 bg-slate-50 p-5">
                    {sale && (
                        <div className="rounded-2xl bg-white p-4">
                            <div className="grid gap-3">
                                <div className="grid gap-2 sm:grid-cols-[1fr_1fr]">
                                    <select value={sale.checkout.global_discount_type} onChange={(event) => onCheckoutFieldChange('global_discount_type', event.target.value)} className="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <option value="none">Aucune remise globale</option>
                                        <option value="percentage">Remise %</option>
                                        <option value="fixed">Remise fixe MAD</option>
                                    </select>
                                    <input
                                        value={sale.checkout.global_discount_value === '0.0000' ? '' : sale.checkout.global_discount_value}
                                        onChange={(event) => onCheckoutFieldChange('global_discount_value', event.target.value || '0')}
                                        inputMode="decimal"
                                        className="rounded-xl border border-slate-300 px-3 py-2 text-sm"
                                        placeholder="Valeur de remise"
                                    />
                                </div>

                                <div className="space-y-2 text-sm">
                                    <div className="flex items-center justify-between"><span className="text-slate-500">Sous-total</span><strong>{formatMoney(summary?.merchandise_total ?? '0', currencyCode)}</strong></div>
                                    <div className="flex items-center justify-between"><span className="text-slate-500">Remise lignes</span><span>{formatMoney(summary?.line_discount_total ?? '0', currencyCode)}</span></div>
                                    <div className="flex items-center justify-between"><span className="text-slate-500">Remise globale</span><span>- {formatMoney(summary?.global_discount_amount ?? '0', currencyCode)}</span></div>
                                    <div className="flex items-center justify-between"><span className="text-slate-500">Livraison</span><span>{formatMoney(summary?.shipping_fee ?? '0', currencyCode)}</span></div>
                                    <div className="flex items-center justify-between border-t border-slate-200 pt-2 text-base"><span className="font-semibold text-slate-900">Total</span><strong className="text-lg text-slate-950">{formatMoney(summary?.total ?? '0', currencyCode)}</strong></div>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="grid gap-3">
                        <button type="button" disabled={busy || !sale || lines.length === 0} onClick={onHold} className="inline-flex min-h-11 items-center justify-center rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800 disabled:opacity-50">
                            Mettre en attente
                        </button>
                        <button type="button" disabled={busy || !sale || lines.length === 0 || sale.availability_warnings.length > 0} onClick={onCheckout} className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-emerald-700 px-4 py-3 text-base font-semibold text-white disabled:opacity-50">
                            Passer au checkout
                        </button>
                    </div>
                </div>
            </section>
        </aside>
    );
}
