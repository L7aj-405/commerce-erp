import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDateTime, formatSignedQuantity } from '@/utils/format';
import { Head, router } from '@inertiajs/react';

type Warehouse = { id: number; name: string; code: string };
type Movement = { id: number; movement_type: string; quantity: string; reason: string | null; reference: string | null; created_at: string; warehouse: Warehouse; product_variant: { label: string | null; sku: string; product: { name: string } }; performed_by: { name: string } | null };
type LinkData = { url: string | null; label: string; active: boolean };
type Props = { movements: { data: Movement[]; links: LinkData[] }; filters: { warehouse?: number }; warehouses: Warehouse[] };

export default function MovementIndex({ movements, filters, warehouses }: Props) {
    return (
        <InventoryLayout>
            <Head title="Mouvements" />
            <PageHeader
                title="Mouvements"
                description="Suivez les entrees, sorties, ajustements et transferts qui alimentent votre stock."
                actions={<FilterSelect value={filters.warehouse ?? ''} onChange={event => router.get('/inventory/movements', { warehouse: event.target.value || undefined }, { preserveState: true, preserveScroll: true })}><option value="">Tous les emplacements</option>{warehouses.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</FilterSelect>}
            />
            <div className="overflow-hidden rounded-2xl border bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="p-3">Date</th>
                            <th className="p-3">Produit</th>
                            <th className="p-3">Type</th>
                            <th className="p-3">Emplacement</th>
                            <th className="p-3 text-right">Quantite</th>
                            <th className="p-3">Reference</th>
                            <th className="p-3">Utilisateur</th>
                        </tr>
                    </thead>
                    <tbody>
                        {movements.data.map(movement => (
                            <tr key={movement.id} className="border-t">
                                <td className="whitespace-nowrap p-3">{formatDateTime(movement.created_at)}</td>
                                <td className="p-3">
                                    <div className="font-semibold">{movement.product_variant.product.name}</div>
                                    <div className="text-xs text-slate-500">{movement.product_variant.label ?? 'Variante principale'} · {movement.product_variant.sku}</div>
                                </td>
                                <td className="p-3 capitalize">{movement.movement_type.replaceAll('_', ' ')}</td>
                                <td className="p-3">{movement.warehouse.name}</td>
                                <td className="p-3 text-right font-semibold">{formatSignedQuantity(movement.quantity)}</td>
                                <td className="p-3">{movement.reference ?? movement.reason ?? '—'}</td>
                                <td className="p-3">{movement.performed_by?.name ?? 'Systeme'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={movements.links} />
        </InventoryLayout>
    );
}
