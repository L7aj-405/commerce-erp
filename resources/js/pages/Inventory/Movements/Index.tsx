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
            {movements.data.length === 0 ? (
                <div className="rounded-2xl border border-dashed bg-slate-50 p-8 text-center text-sm text-slate-500">Aucun mouvement.</div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-2xl border bg-white md:block">
                        <table className="w-full min-w-[860px] text-left text-sm">
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

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {movements.data.map(movement => (
                            <li key={movement.id} className="rounded-2xl border bg-white p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold">{movement.product_variant.product.name}</p>
                                        <p className="truncate text-[13px] text-slate-500">
                                            {movement.product_variant.label ?? 'Variante principale'} · {movement.product_variant.sku}
                                        </p>
                                    </div>
                                    <span className="shrink-0 text-right font-semibold">{formatSignedQuantity(movement.quantity)}</span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Date</dt>
                                        <dd>{formatDateTime(movement.created_at)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Type</dt>
                                        <dd className="capitalize">{movement.movement_type.replaceAll('_', ' ')}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Emplacement</dt>
                                        <dd className="truncate">{movement.warehouse.name}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Utilisateur</dt>
                                        <dd className="truncate">{movement.performed_by?.name ?? 'Systeme'}</dd>
                                    </div>
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-slate-400">Reference</dt>
                                        <dd className="truncate">{movement.reference ?? movement.reason ?? '—'}</dd>
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
