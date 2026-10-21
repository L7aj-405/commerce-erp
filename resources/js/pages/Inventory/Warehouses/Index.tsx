import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import InventoryLayout from '@/layouts/InventoryLayout';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import { formatEditableNumber, formatQuantity } from '@/utils/format';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

function ReplenishmentForm({ warehouse }: { warehouse: { id: number; replenishment: { auto_replenish: boolean; default_minimum_quantity: string } } }) {
    const { t } = useI18n();
    const form = useForm({
        auto_replenish: warehouse.replenishment.auto_replenish,
        default_minimum_quantity: formatEditableNumber(warehouse.replenishment.default_minimum_quantity),
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.patch(`/inventory/warehouses/${warehouse.id}/replenishment`, { preserveScroll: true });
            }}
            className="mt-4 rounded-field border border-line bg-raised p-4 text-sm"
        >
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{t('inventory.warehouses.autoReplenishment')}</p>
            <label className="mt-2.5 flex items-center gap-2 text-ink">
                <input
                    type="checkbox"
                    checked={form.data.auto_replenish}
                    onChange={(e) => form.setData('auto_replenish', e.target.checked)}
                    className="size-4 rounded border-line-strong text-primary focus:ring-primary"
                />
                {t('inventory.warehouses.enableAutoRequests')}
            </label>
            <label className="mt-2.5 block text-ink-muted">
                {t('inventory.warehouses.defaultMinimum')}
                <input
                    inputMode="decimal"
                    value={form.data.default_minimum_quantity}
                    onChange={(e) => form.setData('default_minimum_quantity', e.target.value)}
                    className="control-input mt-1 w-28"
                />
            </label>
            {form.errors.default_minimum_quantity && <p className="mt-1 text-[13px] text-danger">{form.errors.default_minimum_quantity}</p>}
            <Button type="submit" variant="secondary" size="sm" loading={form.processing} loadingText={t('common.loading')} className="mt-3">
                {t('common.save')}
            </Button>
        </form>
    );
}

type Warehouse = {
    id: number;
    name: string;
    code: string;
    description: string | null;
    status: 'active' | 'inactive';
    product_count: number;
    unit_count: string;
    replenishment: { auto_replenish: boolean; default_minimum_quantity: string };
};
type Props = {
    warehouses: Warehouse[];
    can: { create: boolean; update: boolean; replenishment: boolean };
};
type SharedProps = { flash?: { warehouseCreatedId?: number | null } };

export default function WarehouseIndex({ warehouses, can }: Props) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const createForm = useForm({ name: '', code: '', description: '' });
    const justCreatedId = page.props.flash?.warehouseCreatedId ?? null;

    function create(event: FormEvent) {
        event.preventDefault();
        createForm.post('/inventory/warehouses', { preserveScroll: true, onSuccess: () => createForm.reset() });
    }

    function toggle(warehouse: Warehouse) {
        router.patch(`/inventory/warehouses/${warehouse.id}`, {
            name: warehouse.name,
            code: warehouse.code,
            description: warehouse.description,
            status: warehouse.status === 'active' ? 'inactive' : 'active',
        }, { preserveScroll: true });
    }

    return (
        <InventoryLayout>
            <Head title={t('inventory.warehouses.title')} />
            <PageHeader
                title={t('inventory.warehouses.title')}
                description={t('inventory.warehouses.description')}
                actions={can.create ? <a href="#warehouse-create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg transition-soft hover:bg-primary-hover">+ {t('inventory.warehouses.add')}</a> : undefined}
            />

            {warehouses.length === 0 ? (
                <EmptyState
                    title={t('inventory.warehouses.empty')}
                    description={t('inventory.warehouses.emptyHelp')}
                    actions={can.create ? <a href="#warehouse-create" className="inline-flex min-h-10 items-center justify-center rounded-field bg-primary px-4 text-sm font-medium text-primary-fg transition-soft hover:bg-primary-hover">+ {t('inventory.warehouses.add')}</a> : undefined}
                />
            ) : (
                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {warehouses.map(warehouse => (
                        <article key={warehouse.id} className={`rounded-card border bg-surface p-5 transition-soft ${warehouse.id === justCreatedId ? 'border-success/40 shadow-sm' : 'border-line'}`}>
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-ink">{warehouse.name}</h2>
                                    <p className="mt-0.5 text-[12px] font-medium uppercase tracking-wide text-ink-faint">{warehouse.code}</p>
                                </div>
                                <StatusBadge status={warehouse.status} />
                            </div>
                            <p className="mt-3.5 min-h-10 text-[13px] text-ink-muted">{warehouse.description ?? t('inventory.warehouses.noDescription')}</p>
                            <dl className="mt-4 grid grid-cols-2 gap-3 rounded-field bg-raised p-4 text-sm">
                                <div>
                                    <dt className="text-[11px] uppercase tracking-wide text-ink-faint">{t('inventory.transfers.products')}</dt>
                                    <dd className="mt-1 text-lg font-semibold text-ink">{warehouse.product_count}</dd>
                                </div>
                                <div>
                                    <dt className="text-[11px] uppercase tracking-wide text-ink-faint">{t('inventory.warehouses.stockUnits')}</dt>
                                    <dd className="mt-1 text-lg font-semibold text-ink">{formatQuantity(warehouse.unit_count)}</dd>
                                </div>
                            </dl>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <ButtonLink href={`/inventory/stock?warehouse=${warehouse.id}`} variant="secondary" size="sm">{t('inventory.warehouses.viewStock')}</ButtonLink>
                                {can.update && (
                                    <button
                                        type="button"
                                        onClick={() => toggle(warehouse)}
                                        className="inline-flex min-h-8 items-center justify-center rounded-field border border-line-strong px-3 text-xs font-medium text-ink transition-soft hover:bg-raised"
                                    >
                                        {warehouse.status === 'active' ? t('inventory.warehouses.disable') : t('inventory.warehouses.enable')}
                                    </button>
                                )}
                            </div>
                            {can.replenishment && <ReplenishmentForm warehouse={warehouse} />}
                            {warehouse.id === justCreatedId && (
                                <div className="mt-4 rounded-field border border-success/30 bg-success-soft p-4 text-[13px] text-success">
                                    <p className="font-medium">{t('inventory.warehouses.created')}</p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <ButtonLink href={`/inventory/stock?warehouse=${warehouse.id}`} variant="secondary" size="sm">{t('inventory.warehouses.viewStock')}</ButtonLink>
                                        <ButtonLink href="/inventory/transfers/create" variant="secondary" size="sm">{t('inventory.transfers.new')}</ButtonLink>
                                    </div>
                                </div>
                            )}
                        </article>
                    ))}
                </section>
            )}

            {can.create && (
                <section id="warehouse-create" className="mt-8 rounded-card border border-line bg-surface p-6">
                    <h2 className="text-base font-semibold text-ink">{t('inventory.warehouses.add')}</h2>
                    <p className="mt-1 text-[13px] text-ink-muted">{t('inventory.warehouses.addHelp')}</p>
                    <form onSubmit={create} className="mt-5 grid gap-4 md:grid-cols-3">
                        <label className="text-[13px] font-medium text-ink">
                            {t('inventory.warehouses.name')} *
                            <input required value={createForm.data.name} onChange={event => createForm.setData('name', event.target.value)} className="control-input mt-1.5 w-full" />
                            {createForm.errors.name && <p className="mt-1 text-[13px] text-danger">{createForm.errors.name}</p>}
                        </label>
                        <label className="text-[13px] font-medium text-ink">
                            {t('inventory.warehouses.code')} *
                            <input required value={createForm.data.code} onChange={event => createForm.setData('code', event.target.value.toUpperCase())} className="control-input mt-1.5 w-full" />
                            {createForm.errors.code && <p className="mt-1 text-[13px] text-danger">{createForm.errors.code}</p>}
                        </label>
                        <label className="text-[13px] font-medium text-ink md:col-span-3">
                            {t('inventory.warehouses.addressDescription')}
                            <textarea value={createForm.data.description} onChange={event => createForm.setData('description', event.target.value)} rows={3} className="control-input mt-1.5 w-full" />
                        </label>
                        <div className="flex flex-wrap gap-2 md:col-span-3">
                            <Button type="submit" loading={createForm.processing} loadingText={t('inventory.warehouses.creating')}>
                                {t('inventory.warehouses.create')}
                            </Button>
                            <ButtonLink href="/inventory/stock" variant="secondary">{t('inventory.warehouses.viewState')}</ButtonLink>
                        </div>
                    </form>
                </section>
            )}
        </InventoryLayout>
    );
}
