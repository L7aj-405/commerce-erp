import { Sheet } from '@/components/ui/Sheet';
import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import InventoryLayout from '@/layouts/InventoryLayout';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import { formatEditableNumber, formatQuantity } from '@/utils/format';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
            <p className="text-[13px] font-medium text-ink-muted">{t('inventory.warehouses.autoReplenishment')}</p>
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
    /** The Store this location belongs to; null = storage-only depot. */
    store_id: number | null;
    allow_direct_sales: boolean;
    product_count: number;
    unit_count: string;
    replenishment: { auto_replenish: boolean; default_minimum_quantity: string };
};
type StoreOption = { id: number; name: string; code: string; status: string; default_warehouse_id: number | null };
type Props = {
    warehouses: Warehouse[];
    stores: StoreOption[];
    can: { create: boolean; update: boolean; replenishment: boolean };
};

/** Name, code, Store association and the depot-only direct-sales exception. */
function LocationForm({ warehouse, stores }: { warehouse: Warehouse; stores: StoreOption[] }) {
    const { t } = useI18n();
    const form = useForm({
        name: warehouse.name,
        code: warehouse.code,
        description: warehouse.description ?? '',
        status: warehouse.status,
        store_id: warehouse.store_id,
        allow_direct_sales: warehouse.allow_direct_sales,
    });

    const [open, setOpen] = useState(false);
    const formId = `warehouse-edit-${warehouse.id}`;
    const close = () => {
        setOpen(false);
        form.reset();
        form.clearErrors();
    };

    return (
        <>
            <Button type="button" variant="secondary" size="sm" className="mt-4" onClick={() => setOpen(true)}>
                {t('inventory.warehouses.edit')}
            </Button>
            <Sheet
                open={open}
                onClose={close}
                title={t('inventory.warehouses.edit')}
                description={`${warehouse.name} · ${warehouse.code}`}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={close}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" form={formId} loading={form.processing} disabled={!form.isDirty}>
                            {t('common.save')}
                        </Button>
                    </div>
                }
            >
            <form
                id={formId}
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(`/inventory/warehouses/${warehouse.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                }}
                className="grid gap-4 px-4 py-5 text-sm sm:px-6"
            >
                <label className="text-[13px] font-medium text-ink">
                    {t('inventory.warehouses.name')}
                    <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="control-input mt-1 w-full" />
                    {form.errors.name && <span className="mt-1 block text-[13px] text-danger">{form.errors.name}</span>}
                </label>
                <label className="text-[13px] font-medium text-ink">
                    {t('inventory.warehouses.code')}
                    <input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="control-input mt-1 w-full" />
                    {form.errors.code && <span className="mt-1 block text-[13px] text-danger">{form.errors.code}</span>}
                </label>
                <label className="text-[13px] font-medium text-ink">
                    {t('inventory.warehouses.store')}
                    <select
                        value={form.data.store_id ?? ''}
                        onChange={(e) => form.setData('store_id', e.target.value ? Number(e.target.value) : null)}
                        className="control-select mt-1 w-full"
                    >
                        <option value="">{t('inventory.warehouses.storageOnly')}</option>
                        {stores.map((store) => (
                            <option key={store.id} value={store.id}>{store.name} · {store.code}</option>
                        ))}
                    </select>
                    {form.errors.store_id && <span className="mt-1 block text-[13px] text-danger">{form.errors.store_id}</span>}
                </label>
                {form.data.store_id === null && (
                    <label className="flex items-start gap-2 text-[13px] text-ink">
                        <input
                            type="checkbox"
                            checked={form.data.allow_direct_sales}
                            onChange={(e) => form.setData('allow_direct_sales', e.target.checked)}
                            className="mt-0.5 size-4 rounded border-line-strong text-primary focus:ring-primary"
                        />
                        <span>
                            {t('inventory.warehouses.allowDirectSales')}
                            <span className="block text-[12px] text-ink-faint">{t('inventory.warehouses.allowDirectSalesHelp')}</span>
                        </span>
                    </label>
                )}
            </form>
            </Sheet>
        </>
    );
}
type SharedProps = { flash?: { warehouseCreatedId?: number | null } };

export default function WarehouseIndex({ warehouses, stores, can }: Props) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const createForm = useForm({ name: '', code: '', description: '', store_id: null as number | null });
    const storeName = (id: number | null) => stores.find((s) => s.id === id)?.name ?? '';
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
            store_id: warehouse.store_id,
            allow_direct_sales: warehouse.allow_direct_sales,
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
                                    <p className="mt-0.5 text-[13px] font-medium text-ink-muted">{warehouse.code}</p>
                                    <p className="mt-1 text-[12px] text-ink-muted">
                                        {warehouse.store_id !== null
                                            ? t('inventory.warehouses.kindStore').replace('{store}', storeName(warehouse.store_id))
                                            : t('inventory.warehouses.kindDepot')}
                                        {warehouse.store_id === null && warehouse.allow_direct_sales ? ` · ${t('inventory.warehouses.directSales')}` : ''}
                                    </p>
                                </div>
                                <StatusBadge status={warehouse.status} />
                            </div>
                            <p className="mt-3.5 min-h-10 text-[13px] text-ink-muted">{warehouse.description ?? t('inventory.warehouses.noDescription')}</p>
                            <dl className="mt-4 grid grid-cols-2 gap-3 rounded-field bg-raised p-4 text-sm">
                                <div>
                                    <dt className="text-[13px] text-ink-muted font-medium">{t('inventory.transfers.products')}</dt>
                                    <dd className="mt-1 text-lg font-semibold text-ink">{warehouse.product_count}</dd>
                                </div>
                                <div>
                                    <dt className="text-[13px] text-ink-muted font-medium">{t('inventory.warehouses.stockUnits')}</dt>
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
                            {can.update && <LocationForm warehouse={warehouse} stores={stores} />}
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
                <section id="warehouse-create" className="mt-8 surface-card p-6">
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
                        <label className="text-[13px] font-medium text-ink">
                            {t('inventory.warehouses.store')}
                            <select
                                value={createForm.data.store_id ?? ''}
                                onChange={event => createForm.setData('store_id', event.target.value ? Number(event.target.value) : null)}
                                className="control-select mt-1.5 w-full"
                            >
                                <option value="">{t('inventory.warehouses.storageOnly')}</option>
                                {stores.map(store => <option key={store.id} value={store.id}>{store.name} · {store.code}</option>)}
                            </select>
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
