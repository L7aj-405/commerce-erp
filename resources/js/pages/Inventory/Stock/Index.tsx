import ProductImage from '@/components/catalog/ProductImage';
import SandboxStockBadge from '@/components/SandboxStockBadge';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import InventoryLayout from '@/layouts/InventoryLayout';
import { useI18n } from '@/i18n';
import { formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

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
    balances: { data: StockRow[]; links: LinkData[]; total: number; from?: number | null; to?: number | null };
    filters: { search?: string; warehouse?: number | string; brand?: number | string; category?: number | string; availability?: string };
    warehouses: Warehouse[];
    brands: Array<{ id: number; name: string }>;
    categories: Array<{ id: number; name: string }>;
    variants: VariantLookup[];
    can: { opening: boolean; adjust: boolean; transfer: boolean };
};

export default function StockIndex({ balances, filters, warehouses, brands, categories, variants, can }: Props) {
    const { t } = useI18n();
    const str = (value?: number | string) => (value != null && value !== '' ? String(value) : undefined);
    const current = { search: filters.search, warehouse: str(filters.warehouse), brand: str(filters.brand), category: str(filters.category), availability: filters.availability };
    // Exactly the params InventoryStockController@index validates.
    const f = useQueryFilters('/inventory/stock', current, { keys: ['search', 'warehouse', 'brand', 'category', 'availability'] });
    const opening = useForm({ warehouse_id: String(filters.warehouse ?? ''), product_variant_id: '', quantity: '', reason: '', reference: '' });
    const adjustment = useForm({ warehouse_id: String(filters.warehouse ?? ''), product_variant_id: '', type: 'adjustment_in', quantity: '', reason: '', reference: '' });

    const activeWarehouse = warehouses.find((warehouse) => String(warehouse.id) === current.warehouse);
    const toOptions = (items: Array<{ id: number; name: string }>) => items.map((item) => ({ value: String(item.id), label: item.name }));
    const nameOf = (items: Array<{ id: number; name: string }>, id?: string) => items.find((item) => String(item.id) === id)?.name ?? id ?? '';
    const availabilityOptions = [
        { value: 'in_stock', label: t('inventory.stock.inStock') },
        { value: 'out_of_stock', label: t('inventory.stock.outOfStock') },
        { value: 'low_stock', label: t('inventory.stock.lowStock') },
    ];
    const chips: FilterChip[] = [];
    if (current.search) chips.push({ key: 'search', label: t('filters.search'), value: current.search, onRemove: () => f.remove('search') });
    if (current.warehouse) chips.push({ key: 'warehouse', label: t('inventory.stock.warehouse'), value: nameOf(warehouses, current.warehouse), onRemove: () => f.remove('warehouse') });
    if (current.category) chips.push({ key: 'category', label: t('inventory.stock.category'), value: nameOf(categories, current.category), onRemove: () => f.remove('category') });
    if (current.brand) chips.push({ key: 'brand', label: t('inventory.stock.brand'), value: nameOf(brands, current.brand), onRemove: () => f.remove('brand') });
    if (current.availability)
        chips.push({ key: 'availability', label: t('inventory.stock.availability'), value: availabilityOptions.find((o) => o.value === current.availability)?.label ?? current.availability, onRemove: () => f.remove('availability') });
    const productCount = balances.total;
    const totalAvailable = balances.data.reduce((carry, row) => carry + Number(row.summary.available), 0);
    const totalReserved = balances.data.reduce((carry, row) => carry + Number(row.summary.reserved), 0);
    const outOfStockCount = balances.data.filter((row) => Number(row.summary.available) <= 0).length;
    const stockState = (row: StockRow) => Number(row.summary.available) > 0
        ? { label: t('inventory.stock.inStock'), className: 'bg-success-soft text-success' }
        : { label: t('inventory.stock.outOfStock'), className: 'bg-danger-soft text-danger' };

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
            <Head title={t('inventory.stock.title')} />
            <PageHeader
                title={t('inventory.stock.title')}
                description={t('inventory.stock.description')}
                actions={can.transfer ? <ButtonLink href="/inventory/transfers/create">{t('inventory.stock.newTransfer')}</ButtonLink> : undefined}
            />
            <SandboxStockBadge />

            {warehouses.length === 0 ? (
                <EmptyState
                    title={t('inventory.stock.noWarehouse')}
                    description={t('inventory.stock.noWarehouseHelp')}
                    actions={<ButtonLink href="/inventory/warehouses">{t('inventory.stock.addWarehouse')}</ButtonLink>}
                />
            ) : (
                <>
                    <section className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard label={t('inventory.stock.productsVariants')} value={formatInteger(productCount)} />
                        <MetricCard label={t('inventory.stock.available')} value={formatQuantity(totalAvailable)} />
                        <MetricCard label={t('inventory.stock.reserved')} value={formatQuantity(totalReserved)} />
                        <MetricCard label={t('inventory.stock.visibleOutages')} value={formatInteger(outOfStockCount)} tone={outOfStockCount > 0 ? 'danger' : 'neutral'} />
                    </section>

                    <div className="mb-4">
                        <AdvancedFilterBar
                            search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('inventory.stock.searchPlaceholder')} />}
                            chips={chips}
                            activeCount={f.activeCount}
                            loading={f.loading && !f.searching}
                            onReset={f.reset}
                        >
                            <ChoiceFilter label={t('inventory.stock.warehouse')} allLabel={t('inventory.stock.allWarehouses')} options={toOptions(warehouses)} value={current.warehouse} onChange={(value) => f.set('warehouse', value)} />
                            <ChoiceFilter label={t('inventory.stock.availability')} options={availabilityOptions} value={current.availability} onChange={(value) => f.set('availability', value)} />
                            {categories.length > 0 && (
                                <ChoiceFilter label={t('inventory.stock.category')} allLabel={t('inventory.stock.allCategories')} options={toOptions(categories)} value={current.category} onChange={(value) => f.set('category', value)} searchable={categories.length > 8} />
                            )}
                            {brands.length > 0 && (
                                <ChoiceFilter label={t('inventory.stock.brand')} allLabel={t('inventory.stock.allBrands')} options={toOptions(brands)} value={current.brand} onChange={(value) => f.set('brand', value)} searchable={brands.length > 8} />
                            )}
                        </AdvancedFilterBar>
                    </div>

                    {activeWarehouse && (
                        <div className="mb-4 rounded-card border border-line bg-sage px-4 py-3 text-sm text-ink-muted" role="status">
                            {t('inventory.stock.activeWarehouse').replace('{warehouse}', activeWarehouse.name)}
                        </div>
                    )}

                    {balances.data.length === 0 ? (
                        <EmptyState
                            title={f.activeCount > 0 && !current.warehouse ? t('filters.noMatch') : current.warehouse ? t('inventory.stock.noProductWarehouse') : t('inventory.stock.noStock')}
                            description={f.activeCount > 0 && !current.warehouse ? t('filters.noMatchHelp') : current.warehouse ? t('inventory.stock.noProductWarehouseHelp') : t('inventory.stock.noStockHelp')}
                            actions={
                                f.activeCount > 0 ? (
                                    <Button variant="secondary" onClick={f.reset}>
                                        {t('filters.reset')}
                                    </Button>
                                ) : (
                                    <>
                                        <ButtonLink href="/inventory/warehouses" variant="secondary">{t('inventory.stock.viewWarehouses')}</ButtonLink>
                                        {can.transfer && warehouses.length > 1 && <ButtonLink href="/inventory/transfers/create">{t('inventory.stock.createTransfer')}</ButtonLink>}
                                    </>
                                )
                            }
                        />
                    ) : (
                        <>
                            <TableShell
                                label={t('inventory.stock.title')}
                                busy={f.loading}
                                className="hidden lg:block"
                                footer={<TablePager links={balances.links} from={balances.from} to={balances.to} total={balances.total} />}
                            >
                                    <table className="erp-table min-w-[1120px] text-sm">
                                        <thead>
                                            <tr>
                                                <th className="px-4 py-3">{t('inventory.stock.product')}</th>
                                                <th className="px-4 py-3">{t('inventory.stock.referenceSku')}</th>
                                                <th className="px-4 py-3">{t('inventory.stock.variant')}</th>
                                                {!current.warehouse && <th className="px-4 py-3">{t('inventory.stock.warehouses')}</th>}
                                                <th className="num px-4 py-3">{t('inventory.stock.onHand')}</th>
                                                <th className="num px-4 py-3">{t('inventory.stock.reserved')}</th>
                                                <th className="num px-4 py-3">{t('inventory.stock.available')}</th>
                                                <th className="px-4 py-3">{t('inventory.stock.state')}</th>
                                                <th className="px-4 py-3"><span className="sr-only">{t('common.actions')}</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {balances.data.map(row => {
                                                const state = stockState(row);

                                                return (
                                                    <tr key={row.id} className="align-top">
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-3">
                                                                <ProductImage name={row.product.name} imageUrl={row.image_url ?? row.product.image_url} className="h-12 w-12 border border-line" />
                                                                <div className="min-w-0">
                                                                    <Link href={`/catalog/products/${row.product.id}`} className="font-semibold text-ink hover:underline">{row.product.name}</Link>
                                                                    <p className="text-xs text-ink-muted">{row.product.brand?.name ?? t('inventory.stock.noBrand')}</p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <bdi className="font-medium text-ink">{row.sku}</bdi>
                                                            <span className="block text-xs text-ink-muted"><bdi>{row.reference ?? row.barcode ?? t('inventory.stock.noReference')}</bdi></span>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className="inline-flex rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">{row.label ?? t('inventory.stock.mainVariant')}</span>
                                                        </td>
                                                        {!current.warehouse && (
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
                                                        <td className="num px-4 py-3 font-medium text-ink">{formatQuantity(row.summary.on_hand)}</td>
                                                        <td className="num px-4 py-3 text-ink-muted">{formatQuantity(row.summary.reserved)}</td>
                                                        <td className="num px-4 py-3 font-semibold text-ink">{formatQuantity(row.summary.available)}</td>
                                                        <td className="px-4 py-3"><span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${state.className}`}>{state.label}</span></td>
                                                        <td className="px-4 py-3 text-end">
                                                            <div className="flex justify-end gap-2">
                                                                {can.transfer && <Link href={`/inventory/transfers/create?variant_id=${row.id}`} className="rounded-field px-3 py-2 text-sm font-medium text-ink transition-soft hover:bg-sage">{t('inventory.stock.transfer')}</Link>}
                                                                <Link href={`/catalog/products/${row.product.id}`} className="rounded-field px-3 py-2 text-sm text-ink-muted transition-soft hover:bg-sage hover:text-ink">{t('inventory.stock.product')}</Link>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                            </TableShell>

                                <div className={`grid gap-3 transition-opacity sm:grid-cols-2 lg:hidden ${f.loading ? 'opacity-55' : ''}`} aria-busy={f.loading || undefined}>
                                    {balances.data.map(row => {
                                        const state = stockState(row);

                                        return (
                                            <article key={row.id} className="surface-card p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex min-w-0 gap-3">
                                                        <ProductImage name={row.product.name} imageUrl={row.image_url ?? row.product.image_url} className="h-12 w-12 shrink-0 border border-line" />
                                                        <div className="min-w-0">
                                                            <Link href={`/catalog/products/${row.product.id}`} className="font-semibold text-ink hover:underline">{row.product.name}</Link>
                                                            <p className="text-sm text-ink-muted"><bdi>{row.sku}</bdi> · {row.label ?? t('inventory.stock.mainVariant')}</p>
                                                        </div>
                                                    </div>
                                                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${state.className}`}>{state.label}</span>
                                                </div>
                                                <dl className="mt-4 grid grid-cols-3 gap-3 rounded-field bg-raised p-3 text-sm">
                                                    <div>
                                                        <dt className="text-xs text-ink-muted">{t('inventory.stock.onHand')}</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(row.summary.on_hand)}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs text-ink-muted">{t('inventory.stock.reserved')}</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(row.summary.reserved)}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs text-ink-muted">{t('inventory.stock.available')}</dt>
                                                        <dd className="mt-1 font-semibold tabular-nums text-ink">{formatQuantity(row.summary.available)}</dd>
                                                    </div>
                                                </dl>
                                                <div className="mt-3 space-y-1 text-sm text-ink-muted">
                                                    {(current.warehouse ? row.warehouses.slice(0, 1) : row.warehouses).map(warehouse => (
                                                        <div key={warehouse.id} className="flex justify-between gap-3">
                                                            <span>{warehouse.name}</span>
                                                            <span className="tabular-nums text-ink">{formatQuantity(warehouse.available)}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                                <div className="mt-4 flex gap-3 text-sm">
                                                    {can.transfer && <Link href={`/inventory/transfers/create?variant_id=${row.id}`} className="font-medium text-ink hover:underline">{t('inventory.stock.transfer')}</Link>}
                                                    <Link href={`/catalog/products/${row.product.id}`} className="text-ink-muted hover:text-ink hover:underline">{t('inventory.stock.viewProduct')}</Link>
                                                </div>
                                            </article>
                                        );
                                    })}
                                </div>
                                <div className="mt-4 lg:hidden">
                                    <TablePager links={balances.links} from={balances.from} to={balances.to} total={balances.total} />
                                </div>
                        </>
                    )}

                    {(can.opening || can.adjust) && (
                        <section className="mt-8 grid gap-6 lg:grid-cols-2">
                            {can.opening && (
                                <form onSubmit={submitOpening} className="space-y-3 surface-card p-5">
                                    <h2 className="font-semibold text-ink">{t('inventory.stock.opening')}</h2>
                                    <select required aria-label={t('inventory.stock.selectWarehouse')} value={opening.data.warehouse_id} onChange={event => opening.setData('warehouse_id', event.target.value)} className="control-select w-full">
                                        <option value="">{t('inventory.stock.selectWarehouse')}</option>
                                        {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                    </select>
                                    <select required aria-label={t('inventory.stock.selectProduct')} value={opening.data.product_variant_id} onChange={event => opening.setData('product_variant_id', event.target.value)} className="control-select w-full">
                                        <option value="">{t('inventory.stock.selectProduct')}</option>
                                        {variants.map(variant => <option key={variant.id} value={variant.id}>{variant.product.name} · {variant.label ?? t('inventory.stock.main')} · {variant.sku}</option>)}
                                    </select>
                                    <input required inputMode="decimal" value={opening.data.quantity} onChange={event => opening.setData('quantity', event.target.value)} placeholder={t('inventory.stock.quantity')} className="control-input w-full" />
                                    <input value={opening.data.reason} onChange={event => opening.setData('reason', event.target.value)} placeholder={t('inventory.stock.reasonOptional')} className="control-input w-full" />
                                    <input value={opening.data.reference} onChange={event => opening.setData('reference', event.target.value)} placeholder={t('inventory.stock.referenceOptional')} className="control-input w-full" />
                                    {Object.values(opening.errors).map((error, index) => <p key={index} className="text-sm text-danger">{error}</p>)}
                                    <Button type="submit" loading={opening.processing} loadingText={t('inventory.stock.recording')}>{t('inventory.stock.addOpening')}</Button>
                                </form>
                            )}

                            {can.adjust && (
                                <form onSubmit={submitAdjustment} className="space-y-3 surface-card p-5">
                                    <h2 className="font-semibold text-ink">{t('inventory.stock.adjustment')}</h2>
                                    <select required aria-label={t('inventory.stock.selectWarehouse')} value={adjustment.data.warehouse_id} onChange={event => adjustment.setData('warehouse_id', event.target.value)} className="control-select w-full">
                                        <option value="">{t('inventory.stock.selectWarehouse')}</option>
                                        {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                    </select>
                                    <select required aria-label={t('inventory.stock.selectProduct')} value={adjustment.data.product_variant_id} onChange={event => adjustment.setData('product_variant_id', event.target.value)} className="control-select w-full">
                                        <option value="">{t('inventory.stock.selectProduct')}</option>
                                        {variants.map(variant => <option key={variant.id} value={variant.id}>{variant.product.name} · {variant.label ?? t('inventory.stock.main')} · {variant.sku}</option>)}
                                    </select>
                                    <select aria-label={t('inventory.stock.adjustment')} value={adjustment.data.type} onChange={event => adjustment.setData('type', event.target.value)} className="control-select w-full">
                                        <option value="adjustment_in">{t('inventory.stock.adjustmentIn')}</option>
                                        <option value="adjustment_out">{t('inventory.stock.adjustmentOut')}</option>
                                    </select>
                                    <input required inputMode="decimal" value={adjustment.data.quantity} onChange={event => adjustment.setData('quantity', event.target.value)} placeholder={t('inventory.stock.quantity')} className="control-input w-full" />
                                    <input required value={adjustment.data.reason} onChange={event => adjustment.setData('reason', event.target.value)} placeholder={t('inventory.stock.reason')} className="control-input w-full" />
                                    <input value={adjustment.data.reference} onChange={event => adjustment.setData('reference', event.target.value)} placeholder={t('inventory.stock.referenceOptional')} className="control-input w-full" />
                                    {Object.values(adjustment.errors).map((error, index) => <p key={index} className="text-sm text-danger">{error}</p>)}
                                    <Button type="submit" loading={adjustment.processing} loadingText={t('inventory.stock.adjusting')}>{t('inventory.stock.applyAdjustment')}</Button>
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
        <div className="surface-card p-5 shadow-soft">
            <p className="text-[13px] font-medium text-ink-muted">{label}</p>
            <p className={`metric-value mt-1.5 text-2xl font-bold tracking-[-0.02em] ${tone === 'danger' ? 'text-danger' : 'text-ink'}`}>{value}</p>
        </div>
    );
}
