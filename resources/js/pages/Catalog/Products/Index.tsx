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
    useEffect(() => { if (search === (filters.search ?? '')) return; const timer = window.setTimeout(() => visit({ ...filters, search: search || undefined, page: undefined }), 300); return () => window.clearTimeout(timer); }, [search]);
    const activeFilters = Boolean(search || filters.status || filters.brand || filters.category);
    const reset = () => { setSearch(''); visit({}); };
    const firstVariant = (product: Product) => product.variants[0];

    return <CatalogLayout>
        <Head title="Produits" />
        <PageHeader title="Produits" description="Gérez les produits disponibles dans votre catalogue." actions={<>{can.import && <ButtonLink href="/catalog/products/import" variant="secondary">Importer</ButtonLink>}{can.create && <ButtonLink href="/catalog/products/create">+ Ajouter un produit</ButtonLink>}</>} />
        <div className="mb-6 flex flex-wrap gap-2">
            <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Nom, SKU, référence ou code-barres" />
            <FilterSelect aria-label="Statut" value={filters.status ?? ''} onChange={event => visit({ ...filters, search: search || undefined, status: event.target.value || undefined, page: undefined })}><option value="">Tous les statuts</option><option value="active">Actifs</option><option value="inactive">Inactifs</option></FilterSelect>
            <FilterSelect aria-label="Catégorie" value={filters.category ?? ''} onChange={event => visit({ ...filters, search: search || undefined, category: event.target.value ? Number(event.target.value) : undefined, page: undefined })}><option value="">Toutes les catégories</option>{categories.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</FilterSelect>
            <FilterSelect aria-label="Marque" value={filters.brand ?? ''} onChange={event => visit({ ...filters, search: search || undefined, brand: event.target.value ? Number(event.target.value) : undefined, page: undefined })}><option value="">Toutes les marques</option>{brands.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</FilterSelect>
            {activeFilters && <button type="button" onClick={reset} className="px-3 text-sm text-slate-600 hover:text-slate-950">Réinitialiser</button>}
        </div>
        {products.data.length === 0 ? <EmptyState title={activeFilters ? 'Aucun produit trouvé' : 'Votre catalogue est vide'} description={activeFilters ? 'Modifiez votre recherche ou réinitialisez les filtres.' : 'Commencez par importer vos produits depuis un fichier ou ajoutez votre premier produit manuellement.'} actions={!activeFilters && <>{can.import && <ButtonLink href="/catalog/products/import" variant="secondary">Importer des produits</ButtonLink>}{can.create && <ButtonLink href="/catalog/products/create">Ajouter un produit</ButtonLink>}</>} /> : <>
            <div className="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white md:block"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="p-3">Produit</th><th className="p-3">Identifiants</th><th className="p-3">Marque</th><th className="p-3">Disponibilité</th><th className="p-3 text-right">Prix de vente</th><th className="p-3">Statut</th><th className="p-3"><span className="sr-only">Actions</span></th></tr></thead><tbody>
                {products.data.map(product => { const variant = firstVariant(product); return <tr key={product.id} className="border-t"><td className="p-3"><div className="flex items-center gap-3"><div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-50"><ProductImage name={product.name} imageUrl={product.image_url} className="h-full w-full p-2" roundedClassName="rounded-none" /></div><div><Link href={`/catalog/products/${product.id}`} className="font-semibold hover:underline">{product.name}</Link>{product.description && <p className="mt-0.5 max-w-xs truncate text-xs text-slate-500">{product.description}</p>}</div></div></td><td className="p-3"><div className="space-y-1">{variant?.sku && <p>SKU: <span className="font-medium">{variant.sku}</span></p>}{variant?.reference && <p className="text-xs text-slate-500">Réf: {variant.reference}</p>}{!variant?.sku && !variant?.reference && <p className="text-xs text-slate-500">Aucun identifiant secondaire</p>}</div></td><td className="p-3">{product.brand?.name ?? 'Sans marque'}</td><td className="p-3">{can.inventoryView ? <span className="font-medium">{formatQuantity(product.availability_total)}</span> : <span className="text-slate-400">—</span>}</td><td className="p-3 text-right font-medium">{variant?.promotional_sale_price ? <><span>{formatMoney(variant.promotional_sale_price)}</span><span className="ml-2 text-xs font-normal text-slate-400 line-through">{formatMoney(variant.regular_sale_price)}</span></> : variant?.regular_sale_price ? formatMoney(variant.regular_sale_price) : '—'}</td><td className="p-3"><StatusBadge status={product.status} /></td><td className="p-3 text-right"><Link href={`/catalog/products/${product.id}`} className="text-sm font-medium">Ouvrir</Link></td></tr>; })}
            </tbody></table></div>
            <div className="grid gap-3 md:hidden">{products.data.map(product => <Link key={product.id} href={`/catalog/products/${product.id}`} className="rounded-xl border bg-white p-4"><div className="flex gap-3"><div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-50"><ProductImage name={product.name} imageUrl={product.image_url} className="h-full w-full p-2" roundedClassName="rounded-none" /></div><div className="min-w-0 flex-1"><div className="flex justify-between gap-3"><div><p className="font-semibold">{product.name}</p><p className="mt-1 text-sm text-slate-500">{product.brand?.name ?? 'Sans marque'}</p>{firstVariant(product)?.sku && <p className="text-xs text-slate-500">SKU: {firstVariant(product)?.sku}</p>}{firstVariant(product)?.reference && <p className="text-xs text-slate-500">Réf: {firstVariant(product)?.reference}</p>}</div><StatusBadge status={product.status} /></div><div className="mt-3 flex items-center justify-between gap-3"><span className="font-medium">{firstVariant(product)?.default_sale_price ? formatMoney(firstVariant(product)?.default_sale_price) : '—'}</span>{can.inventoryView && <span className="text-xs text-slate-500">Disponible: {formatQuantity(product.availability_total)}</span>}</div></div></div></Link>)}</div>
            <Pagination links={products.links} />
        </>}
    </CatalogLayout>;
}
