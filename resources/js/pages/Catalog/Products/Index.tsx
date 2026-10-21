import ProductImage from '@/components/catalog/ProductImage';
import { ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
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
    const { t } = useI18n();
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
        if (!can.inventoryView || product.availability_total === null) return { label: t('products.notTracked'), className: 'bg-raised text-ink-faint' };
        const value = Number(product.availability_total);

        return value > 0
            ? { label: t('products.inStock'), className: 'bg-success-soft text-success' }
            : { label: t('products.outOfStock'), className: 'bg-danger-soft text-danger' };
    };

    return (
        <CatalogLayout>
            <Head title={t('products.title')} />
            <PageHeader
                title={t('products.title')}
                description={t('products.description')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.import && <ButtonLink href="/catalog/products/import" variant="secondary">{t('products.import')}</ButtonLink>}
                        {can.create && <ButtonLink href="/catalog/products/create">+ {t('products.add')}</ButtonLink>}
                    </div>
                }
            />

            <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="grid gap-2 lg:grid-cols-[minmax(260px,1fr)_180px_200px_200px_auto]">
                    <SearchInput value={search} onChange={setSearch} searching={searching} placeholder={t('products.searchPlaceholder')} />
                    <FilterSelect aria-label={t('common.status')} value={filters.status ?? ''} onChange={event => visit({ ...filters, search: search || undefined, status: event.target.value || undefined, page: undefined })}>
                        <option value="">{t('common.allStatuses')}</option>
                        <option value="active">{t('products.active')}</option>
                        <option value="inactive">{t('products.inactive')}</option>
                    </FilterSelect>
                    <FilterSelect aria-label={t('products.category')} value={filters.category ?? ''} onChange={event => visit({ ...filters, search: search || undefined, category: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                        <option value="">{t('products.allCategories')}</option>
                        {categories.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </FilterSelect>
                    <FilterSelect aria-label={t('products.brand')} value={filters.brand ?? ''} onChange={event => visit({ ...filters, search: search || undefined, brand: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                        <option value="">{t('products.allBrands')}</option>
                        {brands.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </FilterSelect>
                    {activeFilters && (
                        <button type="button" onClick={reset} className="min-h-10 rounded-field px-3 text-sm font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink">
                            {t('common.reset')}
                        </button>
                    )}
                </div>
            </section>

            {products.data.length === 0 ? (
                <EmptyState
                    title={activeFilters ? t('products.emptySearch') : t('products.emptyCatalog')}
                    description={activeFilters ? t('products.adjustSearch') : t('products.startCatalog')}
                    actions={!activeFilters && <>{can.import && <ButtonLink href="/catalog/products/import" variant="secondary">{t('products.importProducts')}</ButtonLink>}{can.create && <ButtonLink href="/catalog/products/create">{t('products.add')}</ButtonLink>}</>}
                />
            ) : (
                <>
                    <div className="hidden overflow-hidden rounded-card border border-line bg-surface shadow-soft md:block">
                        <div className="overflow-x-auto">
                            <table className="erp-table min-w-[980px]">
                                <thead>
                                    <tr>
                                        <th className="px-4 py-3">{t('products.product')}</th>
                                        <th className="px-4 py-3">{t('common.reference')} / SKU</th>
                                        <th className="px-4 py-3">{t('products.variant')}</th>
                                        <th className="px-4 py-3">{t('products.category')}</th>
                                        <th className="px-4 py-3 text-end">{t('products.price')}</th>
                                        <th className="px-4 py-3">{t('products.stock')}</th>
                                        <th className="px-4 py-3">{t('common.status')}</th>
                                        <th className="px-4 py-3 text-end"><span className="sr-only">{t('common.actions')}</span></th>
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
                                                            <p className="mt-0.5 truncate text-xs text-ink-faint">{product.brand?.name ?? t('products.noBrand')}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="bidi-isolate font-medium text-ink" dir="ltr">{variant?.sku || t('products.noSku')}</p>
                                                    <p className="bidi-isolate text-xs text-ink-faint" dir="ltr">{variant?.reference ? `${t('common.reference')} ${variant.reference}` : variant?.barcode ? `Barcode ${variant.barcode}` : t('products.noReference')}</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="inline-flex rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">
                                                        {product.variants.length > 1 ? `${product.variants.length} ${t('products.variants')}` : t('products.mainVariant')}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-ink-muted">{product.default_category?.name ?? '—'}</td>
                                                <td className="metric-value px-4 py-3 text-end tabular-nums" dir="ltr">
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
                                                    {can.inventoryView && product.availability_total !== null && <p className="mt-1 text-xs text-ink-faint">{formatQuantity(product.availability_total)} {t('products.available')}</p>}
                                                </td>
                                                <td className="px-4 py-3"><StatusBadge status={product.status} /></td>
                                                <td className="px-4 py-3 text-end">
                                                    <Link href={`/catalog/products/${product.id}`} className="rounded-field px-3 py-2 text-sm font-medium text-ink transition-soft hover:bg-sage">{t('common.open')}</Link>
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
                                                    <p className="mt-1 text-sm text-ink-muted">{product.brand?.name ?? t('products.noBrand')}</p>
                                                    <p className="bidi-isolate text-xs text-ink-faint" dir="ltr">{variant?.sku || t('products.noSku')}{variant?.reference ? ` · ${t('common.reference')} ${variant.reference}` : ''}</p>
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
