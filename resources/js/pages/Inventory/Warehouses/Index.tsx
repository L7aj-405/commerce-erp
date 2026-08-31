import { ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatQuantity } from '@/utils/format';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Warehouse = {
    id: number;
    name: string;
    code: string;
    description: string | null;
    status: 'active' | 'inactive';
    product_count: number;
    unit_count: string;
};
type Props = {
    warehouses: Warehouse[];
    can: { create: boolean; update: boolean };
};
type SharedProps = { flash?: { warehouseCreatedId?: number | null } };

export default function WarehouseIndex({ warehouses, can }: Props) {
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
            <Head title="Emplacements" />
            <PageHeader
                title="Emplacements"
                description="Gerez les lieux ou votre stock est conserve."
                actions={can.create ? <a href="#warehouse-create" className="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white">+ Ajouter un emplacement</a> : undefined}
            />

            {warehouses.length === 0 ? (
                <EmptyState
                    title="Aucun emplacement de stock."
                    description="Creez votre premier emplacement pour commencer a gerer le stock."
                    actions={can.create ? <a href="#warehouse-create" className="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white">+ Ajouter un emplacement</a> : undefined}
                />
            ) : (
                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {warehouses.map(warehouse => (
                        <article key={warehouse.id} className={`rounded-2xl border bg-white p-5 ${warehouse.id === justCreatedId ? 'border-emerald-300 shadow-sm shadow-emerald-100' : ''}`}>
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">{warehouse.name}</h2>
                                    <p className="mt-1 text-sm font-medium uppercase tracking-wide text-slate-500">{warehouse.code}</p>
                                </div>
                                <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${warehouse.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{warehouse.status === 'active' ? 'Actif' : 'Inactif'}</span>
                            </div>
                            <p className="mt-4 min-h-12 text-sm text-slate-600">{warehouse.description ?? 'Aucune description renseignee pour cet emplacement.'}</p>
                            <dl className="mt-5 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-4 text-sm">
                                <div>
                                    <dt className="text-slate-500">Produits</dt>
                                    <dd className="mt-1 text-lg font-semibold">{warehouse.product_count}</dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">Unites</dt>
                                    <dd className="mt-1 text-lg font-semibold">{formatQuantity(warehouse.unit_count)}</dd>
                                </div>
                            </dl>
                            <div className="mt-5 flex flex-wrap gap-2">
                                <ButtonLink href={`/inventory/stock?warehouse=${warehouse.id}`} variant="secondary">Voir le stock</ButtonLink>
                                {can.update && <button type="button" onClick={() => toggle(warehouse)} className="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50">{warehouse.status === 'active' ? 'Desactiver' : 'Activer'}</button>}
                            </div>
                            {warehouse.id === justCreatedId && (
                                <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                                    <p className="font-medium">Emplacement cree avec succes.</p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <ButtonLink href={`/inventory/stock?warehouse=${warehouse.id}`} variant="secondary">Voir le stock</ButtonLink>
                                        <ButtonLink href="/inventory/transfers/create" variant="secondary">Creer un transfert</ButtonLink>
                                        <a href="#warehouse-create" className="inline-flex min-h-10 items-center justify-center rounded-lg border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-100">Ajouter un autre emplacement</a>
                                    </div>
                                </div>
                            )}
                        </article>
                    ))}
                </section>
            )}

            {can.create && (
                <section id="warehouse-create" className="mt-8 rounded-2xl border bg-white p-6">
                    <h2 className="text-lg font-semibold">Ajouter un emplacement</h2>
                    <p className="mt-1 text-sm text-slate-600">Nom, code et une description facultative suffisent pour demarrer.</p>
                    <form onSubmit={create} className="mt-5 grid gap-4 md:grid-cols-3">
                        <label className="text-sm font-medium text-slate-700">
                            Nom *
                            <input required value={createForm.data.name} onChange={event => createForm.setData('name', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 font-normal" />
                            {createForm.errors.name && <p className="mt-1 text-sm text-red-600">{createForm.errors.name}</p>}
                        </label>
                        <label className="text-sm font-medium text-slate-700">
                            Code *
                            <input required value={createForm.data.code} onChange={event => createForm.setData('code', event.target.value.toUpperCase())} className="mt-1 w-full rounded-lg border px-3 py-2 font-normal" />
                            {createForm.errors.code && <p className="mt-1 text-sm text-red-600">{createForm.errors.code}</p>}
                        </label>
                        <label className="text-sm font-medium text-slate-700 md:col-span-3">
                            Adresse / description
                            <textarea value={createForm.data.description} onChange={event => createForm.setData('description', event.target.value)} rows={3} className="mt-1 w-full rounded-lg border px-3 py-2 font-normal" />
                        </label>
                        <div className="md:col-span-3 flex flex-wrap gap-2">
                            <button disabled={createForm.processing} className="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{createForm.processing ? 'Creation...' : "Creer l'emplacement"}</button>
                            <ButtonLink href="/inventory/stock" variant="secondary">Voir l'etat du stock</ButtonLink>
                        </div>
                    </form>
                </section>
            )}
        </InventoryLayout>
    );
}
