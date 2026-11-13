import ProductImage from '@/components/catalog/ProductImage';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { rowLink, TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import { useI18n } from '@/i18n';
import CatalogLayout from '@/layouts/CatalogLayout';
import { formatMoney, formatQuantity } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type Option = { id: number; name: string };
type Variant = { id: number; sku: string; reference: string | null; barcode: string | null; regular_sale_price: string; promotional_sale_price: string | null; default_sale_price: string };
type Product = { id: number; name: string; description: string | null; image_url: string | null; status: string; brand: Option | null; default_category: Option | null; variants: Variant[]; availability_total: string | null };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    products: { data: Product[]; links: PageLink[]; total: number; from?: number | null; to?: number | null };
    filters: { search?: string; status?: string; brand?: number | string; category?: number | string };
    brands: Option[];
    categories: Option[];
    can: { create: boolean; import: boolean; inventoryView: boolean };
};

// Exactly the params ProductController@index validates.
const FILTER_KEYS = ['search', 'status', 'brand', 'category'] as const;

export default function ProductIndex({ products, filters, brands, categories, can }: Props) {
    const { t } = useI18n();
    const current = {
        search: filters.search,
        status: filters.status,
        brand: filters.brand != null ? String(filters.brand) : undefined,
        category: filters.category != null ? String(filters.category) : undefined,
    };
    const f = useQueryFilters('/catalog/products', current, { keys: [...FILTER_KEYS] });

    const statusOptions = [
        { value: 'active', label: t('products.active') },
        { value: 'inactive', label: t('products.inactive') },
    ];
    const toOptions = (items: Option[]) => items.map((item) => ({ value: String(item.id), label: item.name }));
    const nameOf = (items: Option[], id?: string) => items.find((item) => String(item.id) === id)?.name ?? id ?? '';

    const chips: FilterChip[] = [];
    if (current.search) chips.push({ key: 'search', label: t('filters.search'), value: current.search, onRemove: () => f.remove('search') });
    if (current.status)
        chips.push({ key: 'status', label: t('common.status'), value: statusOptions.find((o) => o.value === current.status)?.label ?? current.status, onRemove: () => f.remove('status') });
    if (current.category) chips.push({ key: 'category', label: t('products.category'), value: nameOf(categories, current.category), onRemove: () => f.remove('category') });
    if (current.brand) chips.push({ key: 'brand', label: t('products.brand'), value: nameOf(brands, current.brand), onRemove: () => f.remove('brand') });

    const firstVariant = (product: Product) => product.variants[0];
    const availability = (product: Product) => {
        if (!can.inventoryView || product.availability_total === null) return { label: t('products.notTracked'), className: 'bg-raised text-ink-muted' };
        return Number(product.availability_total) > 0
            ? { label: t('products.inStock'), className: 'bg-success-soft text-success' }
            : { label: t('products.outOfStock'), className: 'bg-danger-soft text-danger' };
    };
    const price = (variant?: Variant) =>
        variant?.promotional_sale_price ? (
            <>
                <span className="block font-semibold text-ink">{formatMoney(variant.promotional_sale_price)}</span>
                <span className="block text-xs text-ink-muted line-through">{formatMoney(variant.regular_sale_price)}</span>
            </>
        ) : variant?.regular_sale_price ? (
            <span className="font-semibold text-ink">{formatMoney(variant.regular_sale_price)}</span>
        ) : (
            '—'
        );
    const thumb = (product: Product, size: string) => (
        <div className={`flex ${size} shrink-0 items-center justify-center overflow-hidden rounded-xl border border-line bg-raised`}>
            <ProductImage name={product.name} imageUrl={product.image_url} className="h-full w-full p-1.5" roundedClassName="rounded-none" />
        </div>
    );

    const filtered = f.activeCount > 0;
    const headerActions = (
        <div className="flex flex-wrap gap-2">
            {can.import && (
                <ButtonLink href="/catalog/products/import" variant="secondary">
                    {t('products.import')}
                </ButtonLink>
            )}
            {can.create && <ButtonLink href="/catalog/products/create">+ {t('products.add')}</ButtonLink>}
        </div>
    );
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : (
        <>
            {can.import && (
                <ButtonLink href="/catalog/products/import" variant="secondary">
                    {t('products.importProducts')}
                </ButtonLink>
            )}
            {can.create && <ButtonLink href="/catalog/products/create">{t('products.add')}</ButtonLink>}
        </>
    );
    const emptyTitle = filtered ? t('products.emptySearch') : t('products.emptyCatalog');
    const emptyDescription = filtered ? t('products.adjustSearch') : t('products.startCatalog');
    const pager = <TablePager links={products.links} from={products.from} to={products.to} total={products.total} />;

    return (
        <CatalogLayout>
            <Head title={t('products.title')} />
            <PageHeader title={t('products.title')} description={t('products.description')} actions={headerActions} />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('products.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter label={t('common.status')} options={statusOptions} value={current.status} onChange={(value) => f.set('status', value)} />
                    {categories.length > 0 && (
                        <ChoiceFilter label={t('products.category')} options={toOptions(categories)} value={current.category} onChange={(value) => f.set('category', value)} searchable={categories.length > 8} />
                    )}
                    {brands.length > 0 && (
                        <ChoiceFilter label={t('products.brand')} options={toOptions(brands)} value={current.brand} onChange={(value) => f.set('brand', value)} searchable={brands.length > 8} />
                    )}
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('products.title')}
                state={products.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden lg:block"
                footer={products.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[980px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('products.product')}</th>
                            <th className="px-4 py-2.5">{t('common.reference')} / SKU</th>
                            <th className="px-4 py-2.5">{t('products.category')}</th>
                            <th className="num px-4 py-2.5">{t('products.price')}</th>
                            <th className="px-4 py-2.5">{t('products.stock')}</th>
                            <th className="px-4 py-2.5">{t('common.status')}</th>
                            <th className="px-4 py-2.5">
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {products.data.map((product) => {
                            const variant = firstVariant(product);
                            const stock = availability(product);
                            const row = rowLink(`/catalog/products/${product.id}`);
                            return (
                                <tr key={product.id} onClick={row.onClick} className={row.className}>
                                    <td className="px-4 py-2.5">
                                        <div className="flex items-center gap-3">
                                            {thumb(product, 'size-12')}
                                            <div className="min-w-0">
                                                <Link href={`/catalog/products/${product.id}`} className="line-clamp-2 font-semibold text-ink hover:text-accent hover:underline">
                                                    {product.name}
                                                </Link>
                                                <p className="mt-0.5 truncate text-xs text-ink-muted">
                                                    {product.brand?.name ?? t('products.noBrand')}
                                                    {product.variants.length > 1 ? ` · ${product.variants.length} ${t('products.variants')}` : ''}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <span className="block font-medium text-ink">
                                            <bdi dir="ltr">{variant?.sku || t('products.noSku')}</bdi>
                                        </span>
                                        <span className="block text-xs text-ink-muted">
                                            {variant?.reference ? (
                                                <>
                                                    {t('common.reference')} <bdi dir="ltr">{variant.reference}</bdi>
                                                </>
                                            ) : variant?.barcode ? (
                                                <bdi dir="ltr">{variant.barcode}</bdi>
                                            ) : (
                                                t('products.noReference')
                                            )}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2.5 text-ink-muted">{product.default_category?.name ?? '—'}</td>
                                    <td className="num px-4 py-2.5" dir="ltr">{price(variant)}</td>
                                    <td className="px-4 py-2.5">
                                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${stock.className}`}>{stock.label}</span>
                                        {can.inventoryView && product.availability_total !== null && (
                                            <span className="mt-1 block text-xs text-ink-muted">
                                                {formatQuantity(product.availability_total)} {t('products.available')}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <StatusBadge status={product.status} />
                                    </td>
                                    <td className="px-4 py-2.5 text-end">
                                        <ButtonLink href={`/catalog/products/${product.id}`} size="sm" variant="outline">
                                            {t('common.open')}
                                            <span className="sr-only"> {product.name}</span>
                                        </ButtonLink>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile/tablet: stacked cards (filter bar above is shared). */}
            <div className="lg:hidden" aria-busy={f.loading || undefined}>
                {products.data.length === 0 ? (
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                ) : (
                    <ul className={`grid gap-3 transition-opacity sm:grid-cols-2 ${f.loading ? 'opacity-55' : ''}`}>
                        {products.data.map((product) => {
                            const variant = firstVariant(product);
                            const stock = availability(product);
                            return (
                                <li key={product.id}>
                                    <Link href={`/catalog/products/${product.id}`} className="surface-card flex h-full gap-3 p-4 transition-soft hover:border-line-strong">
                                        {thumb(product, 'size-16')}
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="line-clamp-2 font-semibold text-ink">{product.name}</p>
                                                <StatusBadge status={product.status} />
                                            </div>
                                            <p className="mt-0.5 truncate text-[13px] text-ink-muted">
                                                {product.brand?.name ?? t('products.noBrand')} · <bdi dir="ltr">{variant?.sku || t('products.noSku')}</bdi>
                                            </p>
                                            <div className="mt-2.5 flex items-center justify-between gap-3">
                                                <span className="metric-value font-semibold text-ink">{variant?.default_sale_price ? formatMoney(variant.default_sale_price) : '—'}</span>
                                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${stock.className}`}>{stock.label}</span>
                                            </div>
                                        </div>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <div className="mt-4">{pager}</div>
            </div>
        </CatalogLayout>
    );
}
