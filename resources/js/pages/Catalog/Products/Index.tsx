import ProductImage from '@/components/catalog/ProductImage';
import { ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import StatusBadge from '@/components/ui/StatusBadge';
import CatalogLayout from '@/layouts/CatalogLayout';
import { formatMoney, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Option = { id: number; name: string };
type Variant = { id: number; sku: string; reference: string | null; barcode: string | null; regular_sale_price: string; promotional_sale_price: string | null; default_sale_price: string };
type Product = { id: number; name: string; description: string | null; image_url: string | null; status: string; brand: Option | null; default_category: Option | null; variants: Variant[]; availability_total: string | null };
type LinkData = { url: string | null; label: string; active: boolean };
type Props = { products: { data: Product[]; links: LinkData[]; total: number }; filters: { search?: string; status?: string; brand?: number; category?: number }; brands: Option[]; categories: Option[]; can: { create: boolean; import: boolean; inventoryView: boolean } };

export default function ProductIndex({ products, filters, brands, categories, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const visit = (next: Record<string, string | number | undefined>) => router.get('/catalog/products', next, { preserveState: true, preserveScroll: true, replace: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ ...filters, search: search || undefined, page: undefined }), 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    const activeFilters = Boolean(search || filters.status || filters.brand || filters.category);
    const reset = () => { setSearch(''); visit({}); };
    const firstVariant = (product: Product) => product.variants[0];
    const availability = (product: Product) => {
        if (!can.inventoryView || product.availability_total === null) return { label: 'Non suivi', className: 'bg-raised text-ink-faint' };
        const value = Number(product.availability_total);

        return value > 0
            ? { label: 'En stock', className: 'bg-success-soft text-success' }
            : { label: 'Rupture', className: 'bg-danger-soft text-danger' };
    };

    return (
        <CatalogLayout>
            <Head title="Produits" />
            <PageHeader
                title="Produits"
                description="Gérez votre catalogue de produits et variantes."
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.import && <ButtonLink href="/catalog/products/import" variant="secondary">Importer</ButtonLink>}
                        {can.create && <ButtonLink href="/catalog/products/create">+ Ajouter un produit</ButtonLink>}
                    </div>
                }
            />

            <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="grid gap-2 lg:grid-cols-[minmax(260px,1fr)_180px_200px_200px_auto]">
                    <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Rechercher produit, référence, SKU..." />
                    <FilterSelect aria-label="Statut" value={filters.status ?? ''} onChange={event => visit({ ...filters, search: search || undefined, status: event.target.value || undefined, page: undefined })}>
                        <option value="">Tous les statuts</option>
                        <option value="active">Actifs</option>
                        <option value="inactive">Inactifs</option>
                    </FilterSelect>
                    <FilterSelect aria-label="Catégorie" value={filters.category ?? ''} onChange={event => visit({ ...filters, search: search || undefined, category: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                        <option value="">Toutes les catégories</option>
                        {categories.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </FilterSelect>
                    <FilterSelect aria-label="Marque" value={filters.brand ?? ''} onChange={event => visit({ ...filters, search: search || undefined, brand: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                        <option value="">Toutes les marques</option>
                        {brands.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </FilterSelect>
                    {activeFilters && (
                        <button type="button" onClick={reset} className="min-h-10 rounded-field px-3 text-sm font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink">
                            Réinitialiser
                        </button>
                    )}
                </div>
            </section>

            {products.data.length === 0 ? (
                <EmptyState
                    title={activeFilters ? 'Aucun produit trouvé' : 'Votre catalogue est vide'}
                    description={activeFilters ? 'Modifiez votre recherche ou réinitialisez les filtres.' : 'Commencez par importer vos produits depuis un fichier ou ajoutez votre premier produit manuellement.'}
                    actions={!activeFilters && <>{can.import && <ButtonLink href="/catalog/products/import" variant="secondary">Importer des produits</ButtonLink>}{can.create && <ButtonLink href="/catalog/products/create">Ajouter un produit</ButtonLink>}</>}
                />
            ) : (
                <>
                    <div className="hidden overflow-hidden rounded-card border border-line bg-surface shadow-soft md:block">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] text-left text-sm">
                                <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                    <tr>
                                        <th className="px-4 py-3">Produit</th>
                                        <th className="px-4 py-3">Référence / SKU</th>
                                        <th className="px-4 py-3">Variante</th>
                                        <th className="px-4 py-3">Catégorie</th>
                                        <th className="px-4 py-3 text-right">Prix</th>
                                        <th className="px-4 py-3">Stock</th>
                                        <th className="px-4 py-3">Statut</th>
                                        <th className="px-4 py-3 text-right"><span className="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {products.data.map(product => {
                                        const variant = firstVariant(product);
                                        const stock = availability(product);

                                        return (
                                            <tr key={product.id} className="align-middle transition-soft hover:bg-raised/70">
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-line bg-raised">
                                                            <ProductImage name={product.name} imageUrl={product.image_url} className="h-full w-full p-2" roundedClassName="rounded-none" />
                                                        </div>
                                                        <div className="min-w-0">
                                                            <Link href={`/catalog/products/${product.id}`} className="font-semibold text-ink hover:underline">{product.name}</Link>
                                                            <p className="mt-0.5 truncate text-xs text-ink-faint">{product.brand?.name ?? 'Sans marque'}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-ink">{variant?.sku || 'SKU non renseigné'}</p>
                                                    <p className="text-xs text-ink-faint">{variant?.reference ? `Réf. ${variant.reference}` : variant?.barcode ? `Code-barres ${variant.barcode}` : 'Aucune référence'}</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="inline-flex rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">
                                                        {product.variants.length > 1 ? `${product.variants.length} variantes` : 'Variante principale'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-ink-muted">{product.default_category?.name ?? '—'}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {variant?.promotional_sale_price ? (
                                                        <>
                                                            <p className="font-semibold text-ink">{formatMoney(variant.promotional_sale_price)}</p>
                                                            <p className="text-xs text-ink-faint line-through">{formatMoney(variant.regular_sale_price)}</p>
                                                        </>
                                                    ) : variant?.regular_sale_price ? (
                                                        <span className="font-semibold text-ink">{formatMoney(variant.regular_sale_price)}</span>
                                                    ) : '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${stock.className}`}>{stock.label}</span>
                                                    {can.inventoryView && product.availability_total !== null && <p className="mt-1 text-xs text-ink-faint">{formatQuantity(product.availability_total)} disponible</p>}
                                                </td>
                                                <td className="px-4 py-3"><StatusBadge status={product.status} /></td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/catalog/products/${product.id}`} className="rounded-field px-3 py-2 text-sm font-medium text-ink transition-soft hover:bg-sage">Ouvrir</Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="grid gap-3 md:hidden">
                        {products.data.map(product => {
                            const variant = firstVariant(product);
                            const stock = availability(product);

                            return (
                                <Link key={product.id} href={`/catalog/products/${product.id}`} className="rounded-card border border-line bg-surface p-4 shadow-soft transition-soft hover:bg-raised">
                                    <div className="flex gap-3">
                                        <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-line bg-raised">
                                            <ProductImage name={product.name} imageUrl={product.image_url} className="h-full w-full p-2" roundedClassName="rounded-none" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate font-semibold text-ink">{product.name}</p>
                                                    <p className="mt-1 text-sm text-ink-muted">{product.brand?.name ?? 'Sans marque'}</p>
                                                    <p className="text-xs text-ink-faint">{variant?.sku || 'SKU non renseigné'}{variant?.reference ? ` · Réf. ${variant.reference}` : ''}</p>
                                                </div>
                                                <StatusBadge status={product.status} />
                                            </div>
                                            <div className="mt-3 flex items-center justify-between gap-3">
                                                <span className="font-semibold tabular-nums text-ink">{variant?.default_sale_price ? formatMoney(variant.default_sale_price) : '—'}</span>
                                                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${stock.className}`}>{stock.label}</span>
                                            </div>
                                        </div>
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                    <Pagination links={products.links} />
                </>
            )}
        </CatalogLayout>
    );
}
