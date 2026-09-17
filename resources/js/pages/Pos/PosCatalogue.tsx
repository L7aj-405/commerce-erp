import ProductImage from '@/components/catalog/ProductImage';
import { Money, Popover, ProductCardSkeleton, Quantity, SegmentedControl } from '@/components/pos/primitives';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import { MAX_COLUMNS, MIN_COLUMNS, usePosPreferences } from '@/components/pos/usePosPreferences';
import { InlineLoader, Spinner } from '@/components/ui/Spinner';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { CatalogueFilters } from './PosFilters';
import PosProductCard from './PosProductCard';
import type { ProductResult } from './types';

type Props = {
    warehouseId: number | null;
    filters: CatalogueFilters;
    /** True while this product variant is being added to the cart. */
    isAdding: (productId: number) => boolean;
    /** True briefly right after this product was added (success pulse). */
    justAdded: (productId: number) => boolean;
    onAdd: (product: ProductResult) => void;
};

type Payload = {
    data: ProductResult[];
    meta: { current_page: number; has_more: boolean; next_page: number | null };
};

const columnChoices = Array.from({ length: MAX_COLUMNS - MIN_COLUMNS + 1 }, (_, index) => MIN_COLUMNS + index);

export default function PosCatalogue({ warehouseId, filters, isAdding, justAdded, onAdd }: Props) {
    const { preferences, update } = usePosPreferences();
    const { viewMode, cardSize, columns, showImages, showLocalStock, showCompanyStock } = preferences;

    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ProductResult[]>([]);
    const [nextPage, setNextPage] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [displayOpen, setDisplayOpen] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const trimmed = query.trim();
    const debouncedQuery = useDebouncedValue(trimmed, 250);

    const filterKey = useMemo(
        () => JSON.stringify([warehouseId, filters]),
        [warehouseId, filters],
    );
    const debouncedFilterKey = useDebouncedValue(filterKey, 200);

    async function lookup(page = 1, append = false, barcode?: string) {
        if (!warehouseId) {
            setResults([]);
            setError('Sélectionnez un entrepôt opérationnel.');
            return [];
        }

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        setLoading(true);
        setError('');

        const params = new URLSearchParams({ warehouse_id: String(warehouseId), page: String(page), availability: filters.availability });
        if (debouncedQuery) params.set('search', debouncedQuery);
        if (filters.brandId) params.set('brand_id', String(filters.brandId));
        if (filters.categoryId) params.set('category_id', String(filters.categoryId));
        if (filters.priceMin) params.set('price_min', filters.priceMin);
        if (filters.priceMax) params.set('price_max', filters.priceMax);
        if (barcode) params.set('barcode', barcode);

        try {
            const response = await fetch(`/pos/products?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error(response.status === 403 ? 'Accès au catalogue non autorisé.' : 'Le chargement du catalogue a échoué.');
            }
            const payload = (await response.json()) as Payload;
            setResults((current) => (append ? [...current, ...payload.data] : payload.data));
            setNextPage(payload.meta.next_page);
            return payload.data;
        } catch (reason) {
            if ((reason as Error).name === 'AbortError') return [];
            setError(reason instanceof Error ? reason.message : 'Le chargement du catalogue a échoué.');
            return [];
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        void lookup(1, false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedFilterKey, debouncedQuery]);

    const initialLoading = loading && results.length === 0;
    const refreshing = loading && results.length > 0;

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col rounded-card border border-line bg-surface">
            {/* Search + display controls */}
            <div className="shrink-0 space-y-3 border-b border-line p-3.5">
                <div className="flex items-center gap-2">
                    <div className="relative min-w-0 flex-1">
                        <svg className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
                        </svg>
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={async (event) => {
                                if (event.key !== 'Enter' || !trimmed) return;
                                event.preventDefault();
                                const matches = await lookup(1, false, trimmed);
                                // Zero company stock no longer blocks a scan-to-add — the
                                // line becomes a supplier special order in the cart.
                                if (matches.length === 1 && !matches[0].tax_config_missing) {
                                    onAdd(matches[0]);
                                    setQuery('');
                                }
                            }}
                            placeholder="Rechercher un produit, SKU, référence, code-barres…"
                            className="h-11 w-full rounded-field border border-line-strong bg-surface pl-9 pr-3 text-sm outline-none transition-soft focus:border-primary focus:ring-4 focus:ring-primary/10"
                        />
                    </div>

                    <SegmentedControl
                        size="sm"
                        ariaLabel="Mode d’affichage"
                        value={viewMode}
                        onChange={(next) => update({ viewMode: next })}
                        options={[
                            { value: 'table', label: 'Liste' },
                            { value: 'grid', label: 'Grille' },
                        ]}
                    />

                    <div className="relative">
                        <button
                            type="button"
                            onClick={() => setDisplayOpen((value) => !value)}
                            aria-expanded={displayOpen}
                            className="grid size-9 place-items-center rounded-field border border-line-strong text-ink-muted transition-soft hover:text-ink"
                            aria-label="Options d’affichage"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="12" r="3" /><path d="M19 12a7 7 0 0 0-.1-1l2-1.6-2-3.4-2.4 1a7 7 0 0 0-1.7-1l-.4-2.5H10l-.4 2.5a7 7 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.6a7 7 0 0 0 0 2l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 1.7 1l.4 2.5h4l.4-2.5a7 7 0 0 0 1.7-1l2.4 1 2-3.4-2-1.6c.06-.33.1-.66.1-1Z" /></svg>
                        </button>
                        <Popover open={displayOpen} onClose={() => setDisplayOpen(false)}>
                            <p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Affichage</p>
                            <div className="mt-2 flex items-center justify-between py-1.5 text-[13px] text-ink-muted">
                                Densité
                                <SegmentedControl
                                    size="sm"
                                    value={cardSize}
                                    onChange={(next) => update({ cardSize: next })}
                                    options={[
                                        { value: 'compact', label: 'S' },
                                        { value: 'normal', label: 'M' },
                                        { value: 'large', label: 'L' },
                                    ]}
                                />
                            </div>
                            {viewMode === 'grid' && (
                                <label className="flex items-center justify-between py-1.5 text-[13px] text-ink-muted">
                                    Colonnes
                                    <select
                                        value={columns}
                                        onChange={(event) => update({ columns: Number(event.target.value) })}
                                        className="rounded-lg border border-line-strong px-2 py-1 text-sm"
                                    >
                                        {columnChoices.map((count) => (
                                            <option key={count} value={count}>{count}</option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <div className="mt-1 border-t border-line pt-1">
                                {[
                                    ['Images', showImages, (v: boolean) => update({ showImages: v })],
                                    ['Stock local', showLocalStock, (v: boolean) => update({ showLocalStock: v })],
                                    ['Stock société', showCompanyStock, (v: boolean) => update({ showCompanyStock: v })],
                                ].map(([label, checked, setter]) => (
                                    <label key={label as string} className="flex items-center justify-between py-1.5 text-[13px] text-ink-muted">
                                        {label as string}
                                        <input
                                            type="checkbox"
                                            checked={checked as boolean}
                                            onChange={(event) => (setter as (v: boolean) => void)(event.target.checked)}
                                            className="size-4 rounded border-line-strong accent-primary"
                                        />
                                    </label>
                                ))}
                            </div>
                        </Popover>
                    </div>
                </div>
            </div>

            {/* Results */}
            <div className="relative min-h-0 flex-1 overflow-y-auto p-3.5">
                {refreshing && (
                    <div className="pointer-events-none absolute right-3.5 top-3 z-10">
                        <InlineLoader label="Actualisation…" className="rounded-full bg-surface/90 px-2 py-1 text-[11px] shadow-card" />
                    </div>
                )}
                {error && <p className="mb-3 rounded-field bg-danger-soft px-3.5 py-2.5 text-[13px] text-danger">{error}</p>}

                {initialLoading && viewMode === 'grid' && (
                    <div
                        className="grid gap-3 max-[479px]:!grid-cols-2 min-[480px]:max-sm:!grid-cols-3"
                        style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}
                    >
                        {Array.from({ length: columns * 3 }).map((_, index) => <ProductCardSkeleton key={index} size={cardSize} />)}
                    </div>
                )}
                {initialLoading && viewMode === 'table' && (
                    <div className="space-y-1.5">
                        {Array.from({ length: 10 }).map((_, index) => <div key={index} className="h-11 animate-pulse rounded-field bg-sage" />)}
                    </div>
                )}

                {!initialLoading && results.length === 0 && !error && (
                    <div className="rounded-card border border-dashed border-line-strong bg-raised px-6 py-14 text-center text-sm text-ink-muted">
                        Aucun produit ne correspond aux filtres.
                    </div>
                )}

                {!initialLoading && viewMode === 'grid' && results.length > 0 && (
                    <div
                        className={`grid gap-3 transition-opacity max-[479px]:!grid-cols-2 min-[480px]:max-sm:!grid-cols-3 ${refreshing ? 'opacity-60' : ''}`}
                        style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}
                    >
                        {results.map((product) => (
                            <PosProductCard
                                key={product.id}
                                product={product}
                                onAdd={onAdd}
                                pending={isAdding(product.id)}
                                added={justAdded(product.id)}
                                size={cardSize}
                                showImage={showImages}
                                showLocalStock={showLocalStock}
                                showCompanyStock={showCompanyStock}
                            />
                        ))}
                    </div>
                )}

                {!initialLoading && viewMode === 'table' && results.length > 0 && (
                    <div className={`overflow-x-auto rounded-card border border-line transition-opacity ${refreshing ? 'opacity-60' : ''}`}>
                        <table className="min-w-[640px] w-full divide-y divide-line text-sm">
                            <thead className="bg-raised text-left text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    {showImages && <th className="w-12 px-3 py-2.5" />}
                                    <th className="px-3 py-2.5">Produit</th>
                                    <th className="px-3 py-2.5">SKU</th>
                                    <th className="px-3 py-2.5">Marque</th>
                                    <th className="px-3 py-2.5 text-right">Prix</th>
                                    {showLocalStock && <th className="px-3 py-2.5 text-right">Ici</th>}
                                    {showCompanyStock && <th className="px-3 py-2.5 text-right">Société</th>}
                                    <th className="px-3 py-2.5" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line bg-surface">
                                {results.map((product) => {
                                    const total = Number(product.stock.total_available);
                                    const local = Number(product.stock.available);
                                    const pad = cardSize === 'compact' ? 'py-1.5' : 'py-2.5';
                                    const adding = isAdding(product.id);
                                    const added = justAdded(product.id);
                                    return (
                                        <tr key={product.id} className={`transition-soft hover:bg-raised ${added ? 'bg-success-soft/60' : ''}`}>
                                            {showImages && (
                                                <td className={`px-3 ${pad}`}>
                                                    <div className="size-9 overflow-hidden rounded-lg bg-raised">
                                                        <ProductImage name={product.product_name} imageUrl={product.image_url} className="h-full w-full" roundedClassName="rounded-none" />
                                                    </div>
                                                </td>
                                            )}
                                            <td className={`px-3 ${pad}`}>
                                                <p className="font-medium text-ink">{product.product_name}</p>
                                                {product.variant_name && <p className="text-xs text-ink-muted">{product.variant_name}</p>}
                                            </td>
                                            <td className={`px-3 ${pad} text-ink-muted`}>{product.sku ?? '—'}</td>
                                            <td className={`px-3 ${pad} text-ink-muted`}>{product.brand?.name ?? '—'}</td>
                                            <td className={`px-3 ${pad} text-right font-medium text-ink`}><Money value={product.unit_price_incl_tax} /></td>
                                            {showLocalStock && <td className={`px-3 ${pad} text-right ${local > 0 ? 'text-success' : 'text-warning'}`}><Quantity value={product.stock.available} /></td>}
                                            {showCompanyStock && (
                                                <td className={`px-3 ${pad} text-right ${total > 0 ? 'text-ink-muted' : 'text-warning'}`}>
                                                    {total > 0 ? <Quantity value={product.stock.total_available} /> : '0 · Sur commande'}
                                                </td>
                                            )}
                                            <td className={`px-3 ${pad} text-right`}>
                                                <button
                                                    type="button"
                                                    disabled={adding || product.tax_config_missing === true}
                                                    aria-busy={adding || undefined}
                                                    onClick={() => onAdd(product)}
                                                    title={product.tax_config_missing ? 'Aucune taxe par défaut n’est configurée pour ce magasin.' : undefined}
                                                    className="inline-flex min-w-[4.75rem] items-center justify-center gap-1.5 rounded-field bg-primary px-3 py-1.5 text-xs font-medium text-primary-fg transition-soft hover:bg-primary-hover disabled:opacity-40"
                                                >
                                                    {adding ? (
                                                        <Spinner size="xs" />
                                                    ) : added ? (
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" aria-hidden="true"><path d="m5 13 4 4L19 7" /></svg>
                                                    ) : null}
                                                    {adding ? 'Ajout…' : added ? 'Ajouté' : product.tax_config_missing ? 'Taxe ?' : total > 0 ? 'Ajouter' : 'Commander'}
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {nextPage && !initialLoading && (
                    <div className="mt-4 flex justify-center">
                        <button
                            type="button"
                            disabled={loading}
                            onClick={() => void lookup(nextPage, true)}
                            className="rounded-field border border-line-strong px-4 py-2 text-[13px] font-medium text-ink-muted transition-soft hover:text-ink disabled:opacity-50"
                        >
                            {loading ? 'Chargement…' : 'Charger plus'}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
