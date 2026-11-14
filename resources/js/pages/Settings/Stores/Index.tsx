import { Button, ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import StatusBadge from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Location = { id: number; name: string; code: string; status: string };
type StoreRow = {
    id: number;
    name: string;
    code: string;
    status: 'active' | 'inactive';
    default_warehouse_id: number | null;
    members_count: number;
    locations: Location[];
};
type Warehouse = { id: number; name: string; code: string; status: string; store_id: number | null; allow_direct_sales: boolean };
type Props = {
    stores: StoreRow[];
    warehouses: Warehouse[];
    usersAccessUrl: string;
    can: { create: boolean; update: boolean; manageAccess: boolean; manageWarehouses: boolean };
};

/**
 * Paramètres › Magasins — the commercial points de vente. A Store owns sales,
 * carts and its team; stock lives on inventory locations (Inventaire ›
 * Emplacements), some of which belong to a Store. Members are managed on the
 * existing Utilisateurs & accès screen.
 */
export default function StoresIndex({ stores, warehouses, usersAccessUrl, can }: Props) {
    const { t } = useI18n();
    const createForm = useForm({ name: '', code: '' });

    const create = (event: FormEvent) => {
        event.preventDefault();
        createForm.post('/stores', { preserveScroll: true, onSuccess: () => createForm.reset() });
    };

    return (
        <ApplicationShell>
            <Head title={t('stores.title')} />
            <PageHeader
                title={t('stores.title')}
                description={t('stores.description')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.manageAccess && <ButtonLink href={usersAccessUrl} variant="secondary">{t('stores.manageTeam')}</ButtonLink>}
                        {can.manageWarehouses && <ButtonLink href="/inventory/warehouses" variant="secondary">{t('stores.manageLocations')}</ButtonLink>}
                    </div>
                }
            />

            <section className="grid max-w-5xl gap-4">
                {stores.length === 0 && <EmptyState compact title={t('stores.title')} description={t('stores.description')} />}
                {stores.map((store) => (
                    <StoreCard key={store.id} store={store} warehouses={warehouses} canUpdate={can.update} />
                ))}
            </section>

            {can.create && (
                <section className="surface-card mt-8 max-w-5xl p-6">
                    <h2 className="text-base font-semibold text-ink">{t('stores.add')}</h2>
                    <form onSubmit={create} className="mt-4 grid gap-4 md:grid-cols-[1fr_12rem_auto] md:items-end">
                        <label className="text-[13px] font-medium text-ink">
                            {t('stores.name')} *
                            <input required value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} className="control-input mt-1.5 w-full" />
                            {createForm.errors.name && <span className="mt-1 block text-[13px] text-danger">{createForm.errors.name}</span>}
                        </label>
                        <label className="text-[13px] font-medium text-ink">
                            {t('stores.code')} *
                            <input required value={createForm.data.code} onChange={(e) => createForm.setData('code', e.target.value.toUpperCase())} className="control-input mt-1.5 w-full" />
                            {createForm.errors.code && <span className="mt-1 block text-[13px] text-danger">{createForm.errors.code}</span>}
                        </label>
                        <Button type="submit" loading={createForm.processing}>{t('stores.create')}</Button>
                    </form>
                </section>
            )}
        </ApplicationShell>
    );
}

function StoreCard({ store, warehouses, canUpdate }: { store: StoreRow; warehouses: Warehouse[]; canUpdate: boolean }) {
    const { t } = useI18n();
    const form = useForm({
        name: store.name,
        code: store.code,
        default_warehouse_id: store.default_warehouse_id as number | null,
    });
    const toggleStatus = () =>
        router.patch(`/stores/${store.id}/status`, { status: store.status === 'active' ? 'inactive' : 'active' }, { preserveScroll: true });
    // A Store sells from its own locations or a storage-only depot — never another Store's location.
    const candidates = warehouses.filter((w) => w.status === 'active' && (w.store_id === null || w.store_id === store.id));
    const kind = (w: Warehouse) => (w.store_id === null ? t('pos.locationKind.depot') : t('pos.locationKind.store'));

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/stores/${store.id}`, { preserveScroll: true });
    };

    return (
        <article className="surface-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-ink">{store.name}</h2>
                    <p className="mt-0.5 font-mono text-[12px] font-medium text-ink-faint" dir="ltr">{store.code}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2 text-[13px] text-ink-muted">
                    <span>{t('stores.members').replace('{count}', String(store.members_count))}</span>
                    <StatusBadge status={store.status} />
                </div>
            </div>

            <div className="mt-3 text-[13px] text-ink-muted">
                <span className="font-medium text-ink">{t('stores.ownLocations')} : </span>
                {store.locations.length === 0
                    ? t('stores.noOwnLocation')
                    : store.locations.map((l) => `${l.name} · ${l.code}${l.status !== 'active' ? ` (${t('stores.inactive')})` : ''}`).join(', ')}
            </div>

            {canUpdate && (
                <form onSubmit={save} className="mt-4 grid gap-3 rounded-field bg-raised p-4 md:grid-cols-2">
                    <label className="text-[13px] font-medium text-ink">
                        {t('stores.name')}
                        <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="control-input mt-1.5 w-full" />
                        {form.errors.name && <span className="mt-1 block text-[13px] text-danger">{form.errors.name}</span>}
                    </label>
                    <label className="text-[13px] font-medium text-ink">
                        {t('stores.code')}
                        <input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="control-input mt-1.5 w-full" />
                        {form.errors.code && <span className="mt-1 block text-[13px] text-danger">{form.errors.code}</span>}
                    </label>
                    <label className="text-[13px] font-medium text-ink">
                        {t('stores.defaultLocation')}
                        <select
                            value={form.data.default_warehouse_id ?? ''}
                            onChange={(e) => form.setData('default_warehouse_id', e.target.value ? Number(e.target.value) : null)}
                            className="control-select mt-1.5 w-full"
                        >
                            <option value="">{t('stores.noDefaultLocation')}</option>
                            {candidates.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name} · {w.code} — {kind(w)}
                                </option>
                            ))}
                        </select>
                        <span className="mt-1 block text-[12px] font-normal text-ink-faint">{t('stores.defaultLocationHelp')}</span>
                        {form.errors.default_warehouse_id && <span className="mt-1 block text-[13px] text-danger">{form.errors.default_warehouse_id}</span>}
                    </label>
                    <div className="flex flex-wrap gap-2 md:col-span-2">
                        <Button type="submit" size="sm" loading={form.processing} disabled={!form.isDirty}>
                            {t('common.save')}
                        </Button>
                        <Button type="button" size="sm" variant="secondary" onClick={toggleStatus}>
                            {store.status === 'active' ? t('stores.deactivate') : t('stores.activate')}
                        </Button>
                    </div>
                </form>
            )}
        </article>
    );
}
