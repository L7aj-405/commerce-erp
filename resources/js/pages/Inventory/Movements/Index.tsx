import EmptyState from '@/components/ui/EmptyState';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import InventoryLayout from '@/layouts/InventoryLayout';
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
    const updateWarehouse = (warehouse: string) => {
        router.get('/inventory/movements', { warehouse: warehouse || undefined }, { preserveState: true, preserveScroll: true });
    };

    return (
        <InventoryLayout wide>
            <Head title="Mouvements de stock" />
            <PageHeader
                title="Mouvements de stock"
                description="Journal opérationnel des entrées, sorties, ajustements et transferts du magasin actif."
            />

            <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <label className="block max-w-sm flex-1">
                        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-ink-faint">Entrepôt</span>
                        <FilterSelect value={filters.warehouse ?? ''} onChange={(event) => updateWarehouse(event.target.value)}>
                            <option value="">Tous les emplacements</option>
                            {warehouses.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.code} · {item.name}
                                </option>
                            ))}
                        </FilterSelect>
                    </label>
                    <p className="text-sm text-ink-muted">
                        {movements.data.length} mouvement{movements.data.length > 1 ? 's' : ''} affiché{movements.data.length > 1 ? 's' : ''}
                    </p>
                </div>
            </section>

            {movements.data.length === 0 ? (
                <EmptyState title="Aucun mouvement de stock" description="Les mouvements apparaîtront ici dès qu’une opération d’inventaire est enregistrée." />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface shadow-soft md:block">
                        <table className="w-full min-w-[980px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-3">Date</th>
                                    <th className="px-4 py-3">Produit</th>
                                    <th className="px-4 py-3">Référence / SKU</th>
                                    <th className="px-4 py-3">Variante</th>
                                    <th className="px-4 py-3">Entrepôt</th>
                                    <th className="px-4 py-3">Type</th>
                                    <th className="px-4 py-3 text-right">Quantité</th>
                                    <th className="px-4 py-3">Source / référence</th>
                                    <th className="px-4 py-3">Utilisateur</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {movements.data.map((movement) => (
                                    <tr key={movement.id} className="transition-soft hover:bg-raised/70">
                                        <td className="whitespace-nowrap px-4 py-3 text-ink-muted">{formatDateTime(movement.created_at)}</td>
                                        <td className="px-4 py-3 font-semibold text-ink">{movement.product_variant.product.name}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-ink-muted">{movement.product_variant.sku || '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{movement.product_variant.label ?? 'Variante principale'}</td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-ink">{movement.warehouse.name}</p>
                                            <p className="text-xs text-ink-faint">{movement.warehouse.code}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <MovementType value={movement.movement_type} />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <QuantityBadge quantity={movement.quantity} />
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{movement.reference ?? movement.reason ?? '—'}</td>
                                        <td className="px-4 py-3 text-ink-muted">{movement.performed_by?.name ?? 'Système'}</td>
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
                                            {movement.product_variant.label ?? 'Variante principale'} · {movement.product_variant.sku || '—'}
                                        </p>
                                    </div>
                                    <QuantityBadge quantity={movement.quantity} />
                                </div>
                                <dl className="mt-4 grid grid-cols-2 gap-x-3 gap-y-2 text-[13px]">
                                    <Info label="Date" value={formatDateTime(movement.created_at)} />
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Type</dt>
                                        <dd className="mt-1">
                                            <MovementType value={movement.movement_type} />
                                        </dd>
                                    </div>
                                    <Info label="Entrepôt" value={movement.warehouse.name} />
                                    <Info label="Utilisateur" value={movement.performed_by?.name ?? 'Système'} />
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-ink-faint">Source / référence</dt>
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
