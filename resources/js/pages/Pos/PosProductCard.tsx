import ProductImage from '@/components/catalog/ProductImage';
import { Money, Quantity } from '@/components/pos/primitives';
import type { PosCardSize } from '@/components/pos/usePosPreferences';
import { Spinner } from '@/components/ui/Spinner';
import type { ProductResult } from './types';

type Props = {
    product: ProductResult;
    onAdd: (product: ProductResult) => void;
    /** This card's product is being added to the cart right now. */
    pending?: boolean;
    /** Show a brief success pulse (just added). */
    added?: boolean;
    size?: PosCardSize;
    showImage?: boolean;
    showLocalStock?: boolean;
    showCompanyStock?: boolean;
};

const sizeConfig: Record<PosCardSize, { pad: string; aspect: string; name: string; price: string }> = {
    compact: { pad: 'p-2', aspect: 'aspect-square', name: 'text-[12px]', price: 'text-[13px]' },
    normal: { pad: 'p-2.5', aspect: 'aspect-[4/3]', name: 'text-[13px]', price: 'text-sm' },
    large: { pad: 'p-3', aspect: 'aspect-[5/4]', name: 'text-sm', price: 'text-[15px]' },
};

export default function PosProductCard({ product, onAdd, pending = false, added = false, size = 'normal', showImage = true, showLocalStock = true, showCompanyStock = true }: Props) {
    const local = Number(product.stock.available);
    const total = Number(product.stock.total_available);
    const taxMissing = product.tax_config_missing === true;
    // Zero company stock is no longer a blocker — the item can be sold as a
    // supplier special order. Only a missing tax config still prevents selling.
    const sellable = !taxMissing;
    const backorder = total <= 0;
    const remote = local < 1 && total > 0;
    const low = total > 0 && total <= 1;
    const c = sizeConfig[size];

    return (
        <button
            type="button"
            onClick={() => sellable && !pending && onAdd(product)}
            disabled={!sellable || pending}
            aria-busy={pending || undefined}
            title={taxMissing ? 'Aucune taxe par défaut n’est configurée pour ce magasin.' : undefined}
            className={`group relative flex h-full flex-col rounded-card border bg-surface ${c.pad} text-left transition-soft hover:shadow-card focus-visible:outline-2 disabled:cursor-not-allowed ${
                added ? 'border-success ring-2 ring-success/25' : 'border-line hover:border-line-strong'
            } ${!sellable && !pending ? 'opacity-55' : ''}`}
        >
            {/* Pending / added overlay — scoped to THIS card only */}
            {(pending || added) && (
                <span className="absolute inset-0 z-20 grid place-items-center rounded-card bg-surface/70">
                    {pending ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-surface px-2.5 py-1 text-[11px] font-medium text-ink-muted shadow-card">
                            <Spinner size="xs" /> Ajout…
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-2.5 py-1 text-[11px] font-semibold text-success shadow-card">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" aria-hidden="true"><path d="m5 13 4 4L19 7" /></svg>
                            Ajouté
                        </span>
                    )}
                </span>
            )}

            {(taxMissing || remote || low || backorder) && (
                <span
                    className={`absolute right-2 top-2 z-10 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${
                        taxMissing ? 'bg-danger-soft text-danger' : backorder ? 'bg-warning-soft text-warning' : remote ? 'bg-warning-soft text-warning' : 'bg-sage text-ink-muted'
                    }`}
                >
                    {taxMissing ? 'Taxe ?' : backorder ? 'Sur commande' : remote ? 'Interne' : 'Dernier'}
                </span>
            )}

            {showImage && (
                <div className={`${c.aspect} overflow-hidden rounded-field bg-raised`}>
                    <ProductImage name={product.product_name} imageUrl={product.image_url} className="h-full w-full p-1.5" roundedClassName="rounded-none" />
                </div>
            )}

            <div className={`flex flex-1 flex-col ${showImage ? 'mt-2' : ''}`}>
                <p className={`line-clamp-2 font-semibold text-ink ${c.name}`}>{product.product_name}</p>
                {size !== 'compact' && product.variant_name && <p className="text-[11px] text-ink-muted">{product.variant_name}</p>}
                <p className={`mt-1 font-semibold text-ink ${c.price}`}><Money value={product.unit_price_incl_tax} /> <span className="text-[10px] font-normal text-ink-faint">TTC</span></p>
                {(showLocalStock || showCompanyStock) && (
                    <p className={`mt-0.5 text-[11px] ${backorder ? 'text-warning' : 'text-ink-muted'}`}>
                        {backorder ? (
                            'Stock société : 0 · Sur commande fournisseur'
                        ) : (
                            <>
                                {showLocalStock && <><Quantity value={product.stock.available} /> ici</>}
                                {showLocalStock && showCompanyStock && ' · '}
                                {showCompanyStock && <><Quantity value={product.stock.total_available} /> société</>}
                            </>
                        )}
                    </p>
                )}
            </div>
        </button>
    );
}
