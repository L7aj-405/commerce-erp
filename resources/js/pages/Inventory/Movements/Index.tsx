import SandboxStockBadge from '@/components/SandboxStockBadge';
import { AdvancedFilterBar, ChoiceFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import type { FilterChip } from '@/components/ui/AdvancedFilterBar';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import { TablePager, TableShell } from '@/components/ui/TableShell';
import { useQueryFilters } from '@/hooks/useQueryFilters';
import InventoryLayout from '@/layouts/InventoryLayout';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { formatDateTime, formatSignedQuantity } from '@/utils/format';
import { Head } from '@inertiajs/react';

type Warehouse = { id: number; name: string; code: string };
type Movement = {
    id: number;
    movement_type: string;
    quantity: string;
    reason: string | null;
    reference: string | null;
    created_at: string;
    warehouse: Warehouse;
    product_variant: { label: string | null; sku: string; product: { name: string } };
    performed_by: { name: string } | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    movements: { data: Movement[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string; warehouse?: number | string };
    warehouses: Warehouse[];
};

export default function MovementIndex({ movements, filters, warehouses }: Props) {
    const { t } = useI18n();
    const current = { search: filters.search, warehouse: filters.warehouse != null ? String(filters.warehouse) : undefined };
    // Exactly the params InventoryMovementController@index validates.
    const f = useQueryFilters('/inventory/movements', current, { keys: ['search', 'warehouse'] });

    const warehouseOptions = warehouses.map((item) => ({ value: String(item.id), label: `${item.code} · ${item.name}` }));
    const chips: FilterChip[] = [];
    if (current.search) chips.push({ key: 'search', label: t('filters.search'), value: current.search, onRemove: () => f.remove('search') });
    if (current.warehouse)
        chips.push({
            key: 'warehouse',
            label: t('inventory.warehouse'),
            value: warehouseOptions.find((o) => o.value === current.warehouse)?.label ?? current.warehouse,
            onRemove: () => f.remove('warehouse'),
        });

    const filtered = f.activeCount > 0;
    const emptyTitle = filtered ? t('filters.noMatch') : t('inventory.movements.empty');
    const emptyDescription = filtered ? t('filters.noMatchHelp') : t('inventory.movements.emptyHelp');
    const resetAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t('filters.reset')}
        </Button>
    ) : undefined;
    const pager = <TablePager links={movements.links} from={movements.from} to={movements.to} total={movements.total} />;
    const source = (movement: Movement) => movement.reference ?? movement.reason ?? '—';

    return (
        <InventoryLayout wide>
            <Head title={t('inventory.movements.title')} />
            <PageHeader title={t('inventory.movements.title')} description={t('inventory.movements.description')} />
            <SandboxStockBadge />

            <div className="mb-4">
                <AdvancedFilterBar
                    search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label={t('common.search')} placeholder={t('inventory.movements.searchPlaceholder')} />}
                    chips={chips}
                    activeCount={f.activeCount}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <ChoiceFilter
                        label={t('inventory.warehouse')}
                        allLabel={t('inventory.allLocations')}
                        options={warehouseOptions}
                        value={current.warehouse}
                        onChange={(value) => f.set('warehouse', value)}
                        searchable={warehouses.length > 8}
                    />
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t('inventory.movements.title')}
                state={movements.data.length === 0 ? 'empty' : 'ready'}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={resetAction}
                className="hidden lg:block"
                footer={movements.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[980px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-2.5">{t('common.date')}</th>
                            <th className="px-4 py-2.5">{t('inventory.product')}</th>
                            <th className="px-4 py-2.5">{t('inventory.warehouse')}</th>
                            <th className="px-4 py-2.5">{t('inventory.type')}</th>
                            <th className="num px-4 py-2.5">{t('inventory.quantity')}</th>
                            <th className="px-4 py-2.5">{t('inventory.sourceReference')}</th>
                            <th className="px-4 py-2.5">{t('inventory.user')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {movements.data.map((movement) => (
                            <tr key={movement.id}>
                                <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">{formatDateTime(movement.created_at)}</td>
                                <td className="max-w-80 px-4 py-3">
                                    <span className="block truncate font-semibold text-ink">{movement.product_variant.product.name}</span>
                                    <span className="block truncate text-xs text-ink-muted">
                                        {movement.product_variant.label ?? t('inventory.mainVariant')} · <bdi dir="ltr">{movement.product_variant.sku || '—'}</bdi>
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    <span className="block font-medium text-ink">{movement.warehouse.name}</span>
                                    <span className="block text-xs text-ink-muted">
                                        <bdi>{movement.warehouse.code}</bdi>
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    <MovementType value={movement.movement_type} />
                                </td>
                                <td className="num px-4 py-3">
                                    <QuantityBadge quantity={movement.quantity} />
                                </td>
                                <td className="max-w-64 truncate px-4 py-3 text-ink-muted">
                                    <bdi>{source(movement)}</bdi>
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{movement.performed_by?.name ?? t('inventory.system')}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile/tablet: stacked cards (filter bar above is shared). */}
            <div className="lg:hidden" aria-busy={f.loading || undefined}>
                {movements.data.length === 0 ? (
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={resetAction} />
                ) : (
                    <ul className={`grid gap-3 transition-opacity sm:grid-cols-2 ${f.loading ? 'opacity-55' : ''}`}>
                        {movements.data.map((movement) => (
                            <li key={movement.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold text-ink">{movement.product_variant.product.name}</p>
                                        <p className="truncate text-[13px] text-ink-muted">
                                            {movement.product_variant.label ?? t('inventory.mainVariant')} · <bdi dir="ltr">{movement.product_variant.sku || '—'}</bdi>
                                        </p>
                                    </div>
                                    <QuantityBadge quantity={movement.quantity} />
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[13px]">
                                    <Info label={t('common.date')} value={formatDateTime(movement.created_at)} />
                                    <div className="min-w-0">
                                        <dt className="text-ink-muted">{t('inventory.type')}</dt>
                                        <dd className="mt-0.5">
                                            <MovementType value={movement.movement_type} />
                                        </dd>
                                    </div>
                                    <Info label={t('inventory.warehouse')} value={movement.warehouse.name} />
                                    <Info label={t('inventory.user')} value={movement.performed_by?.name ?? t('inventory.system')} />
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-ink-muted">{t('inventory.sourceReference')}</dt>
                                        <dd className="truncate text-ink">
                                            <bdi>{source(movement)}</bdi>
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4">{pager}</div>
            </div>
        </InventoryLayout>
    );
}

const MOVEMENT_TYPES = ['opening', 'adjustment_in', 'adjustment_out', 'transfer_in', 'transfer_out', 'reservation_consumed', 'customer_return', 'supplier_receipt'] as const;

/** Localised movement type; unknown future types fall back to the raw value. */
function MovementType({ value }: { value: string }) {
    const { t } = useI18n();
    const known = (MOVEMENT_TYPES as readonly string[]).includes(value);
    return (
        <span className="inline-flex rounded-full bg-sage px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap text-ink">
            {known ? t(`inventory.movementType.${value}` as TranslationKey) : value.replaceAll('_', ' ')}
        </span>
    );
}

function QuantityBadge({ quantity }: { quantity: string }) {
    const positive = Number(quantity) >= 0;
    return (
        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums ${positive ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`} dir="ltr">
            {formatSignedQuantity(quantity)}
        </span>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-ink-muted">{label}</dt>
            <dd className="truncate text-ink">{value}</dd>
        </div>
    );
}
