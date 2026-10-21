import EmptyState from '@/components/ui/EmptyState';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import InventoryLayout from '@/layouts/InventoryLayout';
import { useI18n } from '@/i18n';
import { formatDateTime, formatSignedQuantity } from '@/utils/format';
import { Head, router } from '@inertiajs/react';

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
type Props = { movements: { data: Movement[]; links: LinkData[] }; filters: { warehouse?: number }; warehouses: Warehouse[] };

export default function MovementIndex({ movements, filters, warehouses }: Props) {
    const { t } = useI18n();
    const updateWarehouse = (warehouse: string) => {
        router.get('/inventory/movements', { warehouse: warehouse || undefined }, { preserveState: true, preserveScroll: true });
    };

    return (
        <InventoryLayout wide>
            <Head title={t('inventory.movements.title')} />
            <PageHeader
                title={t('inventory.movements.title')}
                description={t('inventory.movements.description')}
            />

            <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <label className="block max-w-sm flex-1">
                        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-faint">{t('inventory.warehouse')}</span>
                        <FilterSelect value={filters.warehouse ?? ''} onChange={(event) => updateWarehouse(event.target.value)}>
                            <option value="">{t('inventory.allLocations')}</option>
                            {warehouses.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code} · {item.name}
                                </option>
                            ))}
                        </FilterSelect>
                    </label>
                    <p className="text-sm text-ink-muted">
                        {movements.data.length} {t('inventory.movements.shown')}
                    </p>
                </div>
            </section>

            {movements.data.length === 0 ? (
                <EmptyState title={t('inventory.movements.empty')} description={t('inventory.movements.emptyHelp')} />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface shadow-soft md:block">
                        <table className="erp-table w-full min-w-[980px] text-start text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-3">{t('common.date')}</th><th className="px-4 py-3">{t('inventory.product')}</th>
                                    <th className="px-4 py-3">{t('common.reference')} / SKU</th><th className="px-4 py-3">{t('inventory.variant')}</th>
                                    <th className="px-4 py-3">{t('inventory.warehouse')}</th><th className="px-4 py-3">{t('inventory.type')}</th>
                                    <th className="px-4 py-3 text-end">{t('inventory.quantity')}</th>
                                    <th className="px-4 py-3">{t('inventory.sourceReference')}</th><th className="px-4 py-3">{t('inventory.user')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {movements.data.map((movement) => (
                                    <tr key={movement.id} className="transition-soft hover:bg-raised/70">
                                        <td className="whitespace-nowrap px-4 py-3 text-ink-muted">{formatDateTime(movement.created_at)}</td>
                                        <td className="px-4 py-3 font-semibold text-ink">{movement.product_variant.product.name}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-ink-muted">{movement.product_variant.sku || '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{movement.product_variant.label ?? t('inventory.mainVariant')}</td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-ink">{movement.warehouse.name}</p>
                                            <p className="text-xs text-ink-faint">{movement.warehouse.code}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <MovementType value={movement.movement_type} />
                                        </td>
                                        <td className="px-4 py-3 text-end">
                                            <QuantityBadge quantity={movement.quantity} />
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{movement.reference ?? movement.reason ?? '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{movement.performed_by?.name ?? t('inventory.system')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden">
                        {movements.data.map((movement) => (
                            <li key={movement.id} className="rounded-card border border-line bg-surface p-4 shadow-soft">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold text-ink">{movement.product_variant.product.name}</p>
                                        <p className="truncate text-sm text-ink-muted">
                                            {movement.product_variant.label ?? t('inventory.mainVariant')} · <bdi>{movement.product_variant.sku || '—'}</bdi>
                                        </p>
                                    </div>
                                    <QuantityBadge quantity={movement.quantity} />
                                </div>
                                <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-2 text-[13px]">
                                    <Info label={t('common.date')} value={formatDateTime(movement.created_at)} />
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.type')}</dt>
                                        <dd className="mt-1">
                                            <MovementType value={movement.movement_type} />
                                        </dd>
                                    </div>
                                    <Info label={t('inventory.warehouse')} value={movement.warehouse.name} />
                                    <Info label={t('inventory.user')} value={movement.performed_by?.name ?? t('inventory.system')} />
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-ink-faint">{t('inventory.sourceReference')}</dt>
                                        <dd className="truncate text-ink-muted">{movement.reference ?? movement.reason ?? '—'}</dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={movements.links} />
        </InventoryLayout>
    );
}

function MovementType({ value }: { value: string }) {
    return <span className="inline-flex rounded-full bg-sage px-2.5 py-1 text-xs font-semibold text-ink">{value.replaceAll('_', ' ')}</span>;
}

function QuantityBadge({ quantity }: { quantity: string }) {
    const numeric = Number(quantity);
    const positive = numeric >= 0;

    return (
        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums ${positive ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'}`}>
            {formatSignedQuantity(quantity)}
        </span>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-ink-faint">{label}</dt>
            <dd className="truncate text-ink-muted">{value}</dd>
        </div>
    );
}
