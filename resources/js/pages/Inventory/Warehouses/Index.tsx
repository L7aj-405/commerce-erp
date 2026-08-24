import InventoryLayout from '@/layouts/InventoryLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Warehouse = { id: number; name: string; code: string; description: string | null; status: 'active' | 'inactive' };
type SharedProps = { tenant: { permissions: string[] }; [key: string]: unknown };

export default function WarehouseIndex({ warehouses }: { warehouses: Warehouse[] }) {
    const permissions = usePage<SharedProps>().props.tenant.permissions;
    const createForm = useForm({ name: '', code: '', description: '', status: 'active' });
    const canCreate = permissions.includes('warehouses.create');
    const canUpdate = permissions.includes('warehouses.update');

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
            <Head title="Warehouses" />
            <h2 className="mb-5 text-xl font-semibold">Warehouses</h2>

            {canCreate && (
                <form onSubmit={create} className="mb-8 grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-4">
                    <div><label className="text-sm font-medium">Name</label><input required value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" />{createForm.errors.name && <p className="text-sm text-red-600">{createForm.errors.name}</p>}</div>
                    <div><label className="text-sm font-medium">Code</label><input required value={createForm.data.code} onChange={(e) => createForm.setData('code', e.target.value.toUpperCase())} className="mt-1 w-full rounded border px-3 py-2" />{createForm.errors.code && <p className="text-sm text-red-600">{createForm.errors.code}</p>}</div>
                    <div><label className="text-sm font-medium">Description</label><input value={createForm.data.description} onChange={(e) => createForm.setData('description', e.target.value)} className="mt-1 w-full rounded border px-3 py-2" /></div>
                    <div className="flex items-end"><button disabled={createForm.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Create warehouse</button></div>
                </form>
            )}

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50"><tr><th className="p-3">Name</th><th className="p-3">Code</th><th className="p-3">Description</th><th className="p-3">Status</th><th className="p-3"></th></tr></thead>
                    <tbody>{warehouses.map((warehouse) => <tr key={warehouse.id} className="border-t">
                        <td className="p-3 font-medium">{warehouse.name}</td><td className="p-3">{warehouse.code}</td><td className="p-3">{warehouse.description ?? '—'}</td><td className="p-3 capitalize">{warehouse.status}</td>
                        <td className="p-3 text-right">{canUpdate && <button type="button" onClick={() => toggle(warehouse)} className="rounded border px-3 py-1">Mark {warehouse.status === 'active' ? 'inactive' : 'active'}</button>}</td>
                    </tr>)}</tbody>
                </table>
            </div>
        </InventoryLayout>
    );
}
