import ProductImage from '@/components/catalog/ProductImage';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type VariantLookup = { id: number; label: string | null; sku: string; product: { id: number; name: string } };
type StockRow = {
    id: number;
    label: string | null;
    sku: string;
    reference: string | null;
    barcode: string | null;
    image_url: string | null;
    product: { id: number; name: string; image_url: string | null; brand: { id: number; name: string } | null; category: { id: number; name: string } | null };
    warehouses: Array<{ id: number; name: string; code: string; on_hand: string; reserved: string; available: string }>;
    summary: { id?: number; name?: string; code?: string; on_hand: string; reserved: string; available: string };
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    balances: { data: StockRow[]; links: LinkData[]; total: number };
    filters: { search?: string; warehouse?: number; brand?: number; category?: number; availability?: string };
    warehouses: Warehouse[];
    brands: Array<{ id: number; name: string }>;
    categories: Array<{ id: number; name: string }>;
    variants: VariantLookup[];
    can: { opening: boolean; adjust: boolean; transfer: boolean };
};

export default function StockIndex({ balances, filters, warehouses, brands, categories, variants, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const opening = useForm({ warehouse_id: String(filters.warehouse ?? ''), product_variant_id: '', quantity: '', reason: '', reference: '' });
    const adjustment = useForm({ warehouse_id: String(filters.warehouse ?? ''), product_variant_id: '', type: 'adjustment_in', quantity: '', reason: '', reference: '' });

    const visit = (next: Record<string, string | number | undefined>) => router.get('/inventory/stock', next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setSearching(true),
        onFinish: () => setSearching(false),
    });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ ...filters, search: search || undefined, page: undefined }), 280);

        return () => window.clearTimeout(timer);
    }, [search]);

    const activeWarehouse = warehouses.find((warehouse) => warehouse.id === filters.warehouse);
    const hasFilters = Boolean(search || filters.warehouse || filters.brand || filters.category || filters.availability);
    const productCount = balances.total;
    const totalAvailable = balances.data.reduce((carry, row) => carry + Number(row.summary.available), 0);
    const totalReserved = balances.data.reduce((carry, row) => carry + Number(row.summary.reserved), 0);
    const outOfStockCount = balances.data.filter((row) => Number(row.summary.available) <= 0).length;
    const stockState = (row: StockRow) => Number(row.summary.available) > 0
        ? { label: 'En stock', className: 'bg-success-soft text-success' }
        : { label: 'Rupture', className: 'bg-danger-soft text-danger' };

    function resetFilters() {
        setSearch('');
        visit({});
    }

    function submitOpening(event: FormEvent) {
        event.preventDefault();
        opening.post('/inventory/opening-stock', {
            preserveScroll: true,
            onSuccess: () => opening.reset('product_variant_id', 'quantity', 'reason', 'reference'),
        });
    }

    function submitAdjustment(event: FormEvent) {
        event.preventDefault();
        adjustment.post('/inventory/adjustments', {
            preserveScroll: true,
            onSuccess: () => adjustment.reset('product_variant_id', 'quantity', 'reason', 'reference'),
        });
    }

    return (
        <InventoryLayout wide>
            <Head title="État du stock" />
            <PageHeader
                title="État du stock"
                description="Vue actuelle des disponibilités par produit et entrepôt."
                actions={can.transfer ? <ButtonLink href="/inventory/transfers/create">Nouveau transfert</ButtonLink> : undefined}
            />

            {warehouses.length === 0 ? (
                <EmptyState
                    title="Aucun entrepôt de stock."
                    description="Créez votre premier entrepôt pour commencer à gérer le stock."
                    actions={<ButtonLink href="/inventory/warehouses">+ Ajouter un entrepôt</ButtonLink>}
                />
            ) : (
                <>
                    <section className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard label="Produits / Variantes" value={formatInteger(productCount)} />
                        <MetricCard label="Disponible" value={formatQuantity(totalAvailable)} />
                        <MetricCard label="Réservé" value={formatQuantity(totalReserved)} />
                        <MetricCard label="Ruptures visibles" value={formatInteger(outOfStockCount)} tone={outOfStockCount > 0 ? 'danger' : 'neutral'} />
                    </section>

                    <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                        <div className="grid gap-2 xl:grid-cols-[minmax(260px,1fr)_190px_180px_190px_190px_auto]">
                            <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Produit, SKU, référence, code-barres ou marque" />
                            <FilterSelect aria-label="Entrepôt" value={filters.warehouse ?? ''} onChange={event => visit({ ...filters, search: search || undefined, warehouse: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                                <option value="">Tous les entrepôts</option>
                                {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                            </FilterSelect>
                            <FilterSelect aria-label="Marque" value={filters.brand ?? ''} onChange={event => visit({ ...filters, search: search || undefined, brand: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                                <option value="">Toutes les marques</option>
                                {brands.map(brand => <option key={brand.id} value={brand.id}>{brand.name}</option>)}
                            </FilterSelect>
                            <FilterSelect aria-label="Catégorie" value={filters.category ?? ''} onChange={event => visit({ ...filters, search: search || undefined, category: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                                <option value="">Toutes les catégories</option>
                                {categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
                            </FilterSelect>
                            <FilterSelect aria-label="Disponibilité" value={filters.availability ?? ''} onChange={event => visit({ ...filters, search: search || undefined, availability: event.target.value || undefined, page: undefined })}>
                                <option value="">Toute disponibilité</option>
                                <option value="in_stock">En stock</option>
                                <option value="out_of_stock">Rupture</option>
                                <option value="low_stock">Stock faible</option>
                            </FilterSelect>
                            {hasFilters && <button type="button" onClick={resetFilters} className="min-h-10 rounded-field px-3 text-sm font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink">Réinitialiser</button>}
                        </div>
                    </section>

                    {activeWarehouse && (
                        <div className="mb-4 rounded-card border border-line bg-sage px-4 py-3 text-sm text-ink-muted">
                            Entrepôt actif : <strong className="text-ink">{activeWarehouse.name}</strong>. Les disponibilités ci-dessous correspondent à cet entrepôt.
                        </div>
                    )}

                    {balances.data.length === 0 ? (
                        <EmptyState
                            title={filters.warehouse ? 'Aucun produit disponible dans cet entrepôt.' : 'Aucun stock à afficher'}
                            description={filters.warehouse ? 'Essayez un autre entrepôt, ajoutez un stock initial ou réinitialisez les filtres.' : 'Ajoutez un stock initial ou modifiez vos filtres pour commencer.'}
                            actions={
                                <>
                                    <ButtonLink href="/inventory/warehouses" variant="secondary">Voir les entrepôts</ButtonLink>
                                    {can.transfer && warehouses.length > 1 && <ButtonLink href="/inventory/transfers/create">Créer un transfert</ButtonLink>}
                                </>
                            }
                        />
                    ) : (
                        <>
                            <div className="overflow-hidden rounded-card border border-line bg-surface shadow-soft">
                                <div className="hidden overflow-x-auto lg:block">
                                    <table className="w-full min-w-[1120px] text-left text-sm">
                                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                            <tr>
                                                <th className="px-4 py-3">Produit</th>
                                                <th className="px-4 py-3">Référence / SKU</th>
                                                <th className="px-4 py-3">Variante</th>
                                                {!filters.warehouse && <th className="px-4 py-3">Entrepôts</th>}
                                                <th className="px-4 py-3 text-right">En stock</th>
                                                <th className="px-4 py-3 text-right">Réservé</th>
                                                <th className="px-4 py-3 text-right">Disponible</th>
                                                <th className="px-4 py-3">État</th>
                                                <th className="px-4 py-3 text-right"><span className="sr-only">Actions</span></th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-line">
                                            {balances.data.map(row => {
                                                const state = stockState(row);

                                                return (
                                                    <tr key={row.id} className="align-top transition-soft hover:bg-raised/70">
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-3">
                                                                <ProductImage name={row.product.name} imageUrl={row.image_url ?? row.product.image_url} className="h-12 w-12 border border-line" />
                                                                <div className="min-w-0">
                                                                    <Link href={`/catalog/products/${row.product.id}`} className="font-semibold text-ink hover:underline">{row.product.name}</Link>
                                                                    <p className="text-xs text-ink-faint">{row.product.brand?.name ?? 'Sans marque'}</p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <p className="font-medium text-ink">{row.sku}</p>
                                                            <p className="text-xs text-ink-faint">{row.reference ?? row.barcode ?? 'Sans référence'}</p>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className="inline-flex rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">{row.label ?? 'Variante principale'}</span>
                                                        </td>
                                                        {!filters.warehouse && (
                                                            <td className="px-4 py-3">
                                                                <div className="space-y-1.5">
                                                                    {row.warehouses.map(warehouse => (
                                                                        <div key={warehouse.id} className="flex justify-between gap-3 text-xs text-ink-muted">
                                                                            <span className="truncate">{warehouse.name}</span>
                                                                            <span className="tabular-nums text-ink">{formatQuantity(warehouse.available)}</span>
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            </td>
                                                        )}
                                                        <td className="px-4 py-3 text-right font-medium tabular-nums text-ink">{formatQuantity(row.summary.on_hand)}</td>
                                                        <td className="px-4 py-3 text-right tabular-nums text-ink-muted">{formatQuantity(row.summary.reserved)}</td>
                                                        <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink">{formatQuantity(row.summary.available)}</td>
                                                        <td className="px-4 py-3"><span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${state.className}`}>{state.label}</span></td>
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex justify-end gap-2">
                                                                {can.transfer && <Link href={`/inventory/transfers/create?variant_id=${row.id}`} className="rounded-field px-3 py-2 text-sm font-medium text-ink transition-soft hover:bg-sage">Transférer</Link>}
                                                                <Link href={`/catalog/products/${row.product.id}`} className="rounded-field px-3 py-2 text-sm text-ink-muted transition-soft hover:bg-sage hover:text-ink">Produit</Link>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="grid gap-3 p-4 lg:hidden">
                                    {balances.data.map(row => {
                                        const state = stockState(row);

                                        return (
                                            <article key={row.id} className="rounded-card border border-line bg-surface p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex min-w-0 gap-3">
                                                        <ProductImage name={row.product.name} imageUrl={row.image_url ?? row.product.image_url} className="h-12 w-12 shrink-0 border border-line" />
                                                        <div className="min-w-0">
                                                            <Link href={`/catalog/products/${row.product.id}`} className="font-semibold text-ink hover:underline">{row.product.name}</Link>
                                                            <p className="text-sm text-ink-muted">{row.sku} · {row.label ?? 'Variante principale'}</p>
                                                        </div>
                                                    </div>
                                                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${state.className}`}>{state.label}</span>
                                                </div>
                                                <dl className="mt-4 grid grid-cols-3 gap-3 rounded-field bg-raised p-3 text-sm">
                                                    <div>
                                                        <dt className="text-xs text-ink-faint">En stock</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(row.summary.on_hand)}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs text-ink-faint">Réservé</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(row.summary.reserved)}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs text-ink-faint">Disponible</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(row.summary.available)}</dd>
                                                    </div>
                                                </dl>
                                                <div className="mt-3 space-y-1 text-sm text-ink-muted">
                                                    {(filters.warehouse ? row.warehouses.slice(0, 1) : row.warehouses).map(warehouse => (
                                                        <div key={warehouse.id} className="flex justify-between gap-3">
                                                            <span>{warehouse.name}</span>
                                                            <span className="tabular-nums text-ink">{formatQuantity(warehouse.available)}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                                <div className="mt-4 flex gap-3 text-sm">
                                                    {can.transfer && <Link href={`/inventory/transfers/create?variant_id=${row.id}`} className="font-medium text-ink hover:underline">Transférer</Link>}
                                                    <Link href={`/catalog/products/${row.product.id}`} className="text-ink-muted hover:text-ink hover:underline">Voir le produit</Link>
                                                </div>
                                            </article>
                                        );
                                    })}
                                </div>
                            </div>
                            <Pagination links={balances.links} />
                        </>
                    )}

                    {(can.opening || can.adjust) && (
                        <section className="mt-8 grid gap-6 lg:grid-cols-2">
                            {can.opening && (
                                <form onSubmit={submitOpening} className="space-y-3 rounded-card border border-line bg-surface p-5 shadow-soft">
                                    <h2 className="font-semibold text-ink">Stock initial</h2>
                                    <select required value={opening.data.warehouse_id} onChange={event => opening.setData('warehouse_id', event.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                        <option value="">Sélectionner un entrepôt</option>
                                        {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                    </select>
                                    <select required value={opening.data.product_variant_id} onChange={event => opening.setData('product_variant_id', event.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                        <option value="">Sélectionner un produit</option>
                                        {variants.map(variant => <option key={variant.id} value={variant.id}>{variant.product.name} · {variant.label ?? 'Principale'} · {variant.sku}</option>)}
                                    </select>
                                    <input required inputMode="decimal" value={opening.data.quantity} onChange={event => opening.setData('quantity', event.target.value)} placeholder="Quantité" className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                                    <input value={opening.data.reason} onChange={event => opening.setData('reason', event.target.value)} placeholder="Motif (optionnel)" className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                                    <input value={opening.data.reference} onChange={event => opening.setData('reference', event.target.value)} placeholder="Référence (optionnelle)" className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                                    {Object.values(opening.errors).map((error, index) => <p key={index} className="text-sm text-danger">{error}</p>)}
                                    <Button type="submit" loading={opening.processing} loadingText="Enregistrement...">Ajouter le stock initial</Button>
                                </form>
                            )}

                            {can.adjust && (
                                <form onSubmit={submitAdjustment} className="space-y-3 rounded-card border border-line bg-surface p-5 shadow-soft">
                                    <h2 className="font-semibold text-ink">Ajustement de stock</h2>
                                    <select required value={adjustment.data.warehouse_id} onChange={event => adjustment.setData('warehouse_id', event.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                        <option value="">Sélectionner un entrepôt</option>
                                        {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                    </select>
                                    <select required value={adjustment.data.product_variant_id} onChange={event => adjustment.setData('product_variant_id', event.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                        <option value="">Sélectionner un produit</option>
                                        {variants.map(variant => <option key={variant.id} value={variant.id}>{variant.product.name} · {variant.label ?? 'Principale'} · {variant.sku}</option>)}
                                    </select>
                                    <select value={adjustment.data.type} onChange={event => adjustment.setData('type', event.target.value)} className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm">
                                        <option value="adjustment_in">Entrée</option>
                                        <option value="adjustment_out">Sortie</option>
                                    </select>
                                    <input required inputMode="decimal" value={adjustment.data.quantity} onChange={event => adjustment.setData('quantity', event.target.value)} placeholder="Quantité" className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                                    <input required value={adjustment.data.reason} onChange={event => adjustment.setData('reason', event.target.value)} placeholder="Motif" className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                                    <input value={adjustment.data.reference} onChange={event => adjustment.setData('reference', event.target.value)} placeholder="Référence (optionnelle)" className="w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm" />
                                    {Object.values(adjustment.errors).map((error, index) => <p key={index} className="text-sm text-danger">{error}</p>)}
                                    <Button type="submit" loading={adjustment.processing} loadingText="Ajustement...">Appliquer l’ajustement</Button>
                                </form>
                            )}
                        </section>
                    )}
                </>
            )}
        </InventoryLayout>
    );
}

function MetricCard({ label, value, tone = 'neutral' }: { label: string; value: string; tone?: 'neutral' | 'danger' }) {
    return (
        <div className="rounded-card border border-line bg-surface p-5 shadow-soft">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p>
            <p className={`mt-2 text-2xl font-semibold tabular-nums ${tone === 'danger' ? 'text-danger' : 'text-ink'}`}>{value}</p>
        </div>
    );
}
