import { ButtonLink } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
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
    const totalUnits = balances.data.reduce((carry, row) => carry + Number(row.summary.available), 0);
    const lowStockCount = balances.data.filter((row) => Number(row.summary.available) > 0 && Number(row.summary.available) <= 5).length;

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
            <Head title="Etat du stock" />
            <PageHeader
                title="Etat du stock"
                description="Suivez la disponibilite de vos produits par emplacement."
                actions={can.transfer ? <ButtonLink href="/inventory/transfers/create">Nouveau transfert</ButtonLink> : undefined}
            />

            {warehouses.length === 0 ? (
                <EmptyState
                    title="Aucun emplacement de stock."
                    description="Creez votre premier emplacement pour commencer a gerer le stock."
                    actions={<ButtonLink href="/inventory/warehouses">+ Ajouter un emplacement</ButtonLink>}
                />
            ) : (
                <>
                    <section className="mb-6 grid gap-4 md:grid-cols-3">
                        <div className="rounded-2xl border bg-white p-5">
                            <p className="text-sm text-slate-500">Produits visibles</p>
                            <p className="mt-2 text-3xl font-semibold">{formatInteger(productCount)}</p>
                        </div>
                        <div className="rounded-2xl border bg-white p-5">
                            <p className="text-sm text-slate-500">Unites disponibles</p>
                            <p className="mt-2 text-3xl font-semibold">{formatQuantity(totalUnits)}</p>
                        </div>
                        <div className="rounded-2xl border bg-white p-5">
                            <p className="text-sm text-slate-500">Stock faible</p>
                            <p className="mt-2 text-3xl font-semibold">{formatInteger(lowStockCount)}</p>
                        </div>
                    </section>

                    <div className="mb-6 flex flex-wrap gap-2">
                        <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Produit, SKU, reference, code-barres ou marque" />
                        <FilterSelect aria-label="Emplacement" value={filters.warehouse ?? ''} onChange={event => visit({ ...filters, search: search || undefined, warehouse: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                            <option value="">Tous les emplacements</option>
                            {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                        </FilterSelect>
                        <FilterSelect aria-label="Marque" value={filters.brand ?? ''} onChange={event => visit({ ...filters, search: search || undefined, brand: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                            <option value="">Toutes les marques</option>
                            {brands.map(brand => <option key={brand.id} value={brand.id}>{brand.name}</option>)}
                        </FilterSelect>
                        <FilterSelect aria-label="Categorie" value={filters.category ?? ''} onChange={event => visit({ ...filters, search: search || undefined, category: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                            <option value="">Toutes les categories</option>
                            {categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
                        </FilterSelect>
                        <FilterSelect aria-label="Disponibilite" value={filters.availability ?? ''} onChange={event => visit({ ...filters, search: search || undefined, availability: event.target.value || undefined, page: undefined })}>
                            <option value="">Toute disponibilite</option>
                            <option value="in_stock">En stock</option>
                            <option value="out_of_stock">Rupture</option>
                            <option value="low_stock">Stock faible</option>
                        </FilterSelect>
                        {hasFilters && <button type="button" onClick={resetFilters} className="px-3 text-sm text-slate-600 hover:text-slate-950">Reinitialiser</button>}
                    </div>

                    {activeWarehouse && (
                        <div className="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                            Emplacement actif : <strong>{activeWarehouse.name}</strong>. Les disponibilites ci-dessous correspondent a cet emplacement.
                        </div>
                    )}

                    {balances.data.length === 0 ? (
                        <EmptyState
                            title={filters.warehouse ? 'Aucun produit disponible dans cet emplacement.' : 'Aucun stock a afficher'}
                            description={filters.warehouse ? 'Essayez un autre emplacement, ajoutez un stock initial ou reinitialisez les filtres.' : 'Ajoutez un stock initial ou modifiez vos filtres pour commencer.'}
                            actions={
                                <>
                                    <ButtonLink href="/inventory/warehouses" variant="secondary">Voir les emplacements</ButtonLink>
                                    {can.transfer && warehouses.length > 1 && <ButtonLink href="/inventory/transfers/create">Creer un transfert</ButtonLink>}
                                </>
                            }
                        />
                    ) : (
                        <>
                            <div className="overflow-hidden rounded-2xl border bg-white">
                                <div className="hidden overflow-x-auto lg:block">
                                    <table className="w-full min-w-[1100px] text-left text-sm">
                                        <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th className="p-3">Produit</th>
                                                <th className="p-3">SKU / Reference</th>
                                                <th className="p-3">Marque</th>
                                                {!filters.warehouse && <th className="p-3">Par emplacement</th>}
                                                <th className="p-3 text-right">Total</th>
                                                <th className="p-3 text-right">Disponible</th>
                                                <th className="p-3 text-right"><span className="sr-only">Actions</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {balances.data.map(row => (
                                                <tr key={row.id} className="border-t align-top">
                                                    <td className="p-3">
                                                        <div className="flex items-center gap-3">
                                                            {row.product.image_url ? <img src={row.product.image_url} alt="" className="h-11 w-11 rounded-lg object-cover" referrerPolicy="no-referrer" /> : <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-slate-100 font-semibold text-slate-500">{row.product.name.slice(0, 2).toUpperCase()}</div>}
                                                            <div>
                                                                <Link href={`/catalog/products/${row.product.id}`} className="font-semibold hover:underline">{row.product.name}</Link>
                                                                <p className="text-xs text-slate-500">{row.label ?? 'Variante principale'}</p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        <p>{row.sku}</p>
                                                        <p className="text-xs text-slate-500">{row.reference ?? row.barcode ?? 'Sans reference'}</p>
                                                    </td>
                                                    <td className="p-3">{row.product.brand?.name ?? '—'}</td>
                                                    {!filters.warehouse && (
                                                        <td className="p-3">
                                                            <div className="space-y-1">
                                                                {row.warehouses.map(warehouse => (
                                                                    <div key={warehouse.id} className="flex justify-between gap-3 text-xs text-slate-600">
                                                                        <span>{warehouse.name}</span>
                                                                        <span>{formatQuantity(warehouse.available)}</span>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        </td>
                                                    )}
                                                    <td className="p-3 text-right font-medium tabular-nums">{formatQuantity(row.summary.on_hand)}</td>
                                                    <td className="p-3 text-right font-semibold tabular-nums">{formatQuantity(row.summary.available)}</td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex justify-end gap-2">
                                                            {can.transfer && <Link href={`/inventory/transfers/create?variant_id=${row.id}`} className="text-sm font-medium text-slate-900 hover:underline">Transferer</Link>}
                                                            <Link href={`/catalog/products/${row.product.id}`} className="text-sm text-slate-600 hover:underline">Historique</Link>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="grid gap-3 p-4 lg:hidden">
                                    {balances.data.map(row => (
                                        <article key={row.id} className="rounded-xl border border-slate-200 p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <Link href={`/catalog/products/${row.product.id}`} className="font-semibold hover:underline">{row.product.name}</Link>
                                                    <p className="text-sm text-slate-500">{row.sku} · {row.label ?? 'Variante principale'}</p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-xs uppercase tracking-wide text-slate-400">Disponible</p>
                                                    <p className="text-lg font-semibold">{formatQuantity(row.summary.available)}</p>
                                                </div>
                                            </div>
                                            <div className="mt-3 space-y-1 text-sm text-slate-600">
                                                {(filters.warehouse ? row.warehouses.slice(0, 1) : row.warehouses).map(warehouse => (
                                                    <div key={warehouse.id} className="flex justify-between gap-3">
                                                        <span>{warehouse.name}</span>
                                                        <span>{formatQuantity(warehouse.available)}</span>
                                                    </div>
                                                ))}
                                            </div>
                                            <div className="mt-4 flex gap-3 text-sm">
                                                {can.transfer && <Link href={`/inventory/transfers/create?variant_id=${row.id}`} className="font-medium text-slate-900 hover:underline">Transferer</Link>}
                                                <Link href={`/catalog/products/${row.product.id}`} className="text-slate-600 hover:underline">Voir le produit</Link>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </div>
                            <Pagination links={balances.links} />
                        </>
                    )}

                    {(can.opening || can.adjust) && (
                        <section className="mt-8 grid gap-6 lg:grid-cols-2">
                            {can.opening && (
                                <form onSubmit={submitOpening} className="space-y-3 rounded-2xl border bg-white p-5">
                                    <h2 className="font-semibold">Stock initial</h2>
                                    <select required value={opening.data.warehouse_id} onChange={event => opening.setData('warehouse_id', event.target.value)} className="w-full rounded-lg border px-3 py-2">
                                        <option value="">Selectionner un emplacement</option>
                                        {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                    </select>
                                    <select required value={opening.data.product_variant_id} onChange={event => opening.setData('product_variant_id', event.target.value)} className="w-full rounded-lg border px-3 py-2">
                                        <option value="">Selectionner un produit</option>
                                        {variants.map(variant => <option key={variant.id} value={variant.id}>{variant.product.name} · {variant.label ?? 'Principale'} · {variant.sku}</option>)}
                                    </select>
                                    <input required inputMode="decimal" value={opening.data.quantity} onChange={event => opening.setData('quantity', event.target.value)} placeholder="Quantite" className="w-full rounded-lg border px-3 py-2" />
                                    <input value={opening.data.reason} onChange={event => opening.setData('reason', event.target.value)} placeholder="Motif (optionnel)" className="w-full rounded-lg border px-3 py-2" />
                                    <input value={opening.data.reference} onChange={event => opening.setData('reference', event.target.value)} placeholder="Reference (optionnelle)" className="w-full rounded-lg border px-3 py-2" />
                                    {Object.values(opening.errors).map((error, index) => <p key={index} className="text-sm text-red-600">{error}</p>)}
                                    <button disabled={opening.processing} aria-busy={opening.processing || undefined} className="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{opening.processing && <Spinner size="sm" />}{opening.processing ? 'Enregistrement...' : 'Ajouter le stock initial'}</button>
                                </form>
                            )}

                            {can.adjust && (
                                <form onSubmit={submitAdjustment} className="space-y-3 rounded-2xl border bg-white p-5">
                                    <h2 className="font-semibold">Ajustement de stock</h2>
                                    <select required value={adjustment.data.warehouse_id} onChange={event => adjustment.setData('warehouse_id', event.target.value)} className="w-full rounded-lg border px-3 py-2">
                                        <option value="">Selectionner un emplacement</option>
                                        {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                    </select>
                                    <select required value={adjustment.data.product_variant_id} onChange={event => adjustment.setData('product_variant_id', event.target.value)} className="w-full rounded-lg border px-3 py-2">
                                        <option value="">Selectionner un produit</option>
                                        {variants.map(variant => <option key={variant.id} value={variant.id}>{variant.product.name} · {variant.label ?? 'Principale'} · {variant.sku}</option>)}
                                    </select>
                                    <select value={adjustment.data.type} onChange={event => adjustment.setData('type', event.target.value)} className="w-full rounded-lg border px-3 py-2">
                                        <option value="adjustment_in">Entree</option>
                                        <option value="adjustment_out">Sortie</option>
                                    </select>
                                    <input required inputMode="decimal" value={adjustment.data.quantity} onChange={event => adjustment.setData('quantity', event.target.value)} placeholder="Quantite" className="w-full rounded-lg border px-3 py-2" />
                                    <input required value={adjustment.data.reason} onChange={event => adjustment.setData('reason', event.target.value)} placeholder="Motif" className="w-full rounded-lg border px-3 py-2" />
                                    <input value={adjustment.data.reference} onChange={event => adjustment.setData('reference', event.target.value)} placeholder="Reference (optionnelle)" className="w-full rounded-lg border px-3 py-2" />
                                    {Object.values(adjustment.errors).map((error, index) => <p key={index} className="text-sm text-red-600">{error}</p>)}
                                    <button disabled={adjustment.processing} aria-busy={adjustment.processing || undefined} className="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{adjustment.processing && <Spinner size="sm" />}{adjustment.processing ? 'Ajustement...' : 'Appliquer l ajustement'}</button>
                                </form>
                            )}
                        </section>
                    )}
                </>
            )}
        </InventoryLayout>
    );
}
