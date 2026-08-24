import InventoryLayout from '@/layouts/InventoryLayout';
import { Head, Link, router } from '@inertiajs/react';

type Warehouse = { id: number; name: string; code: string };
type Movement = { id: number; movement_type: string; quantity: string; quantity_before: string; quantity_after: string; reason: string | null; reference: string | null; created_at: string; warehouse: Warehouse; product_variant: { label: string | null; sku: string; product: { name: string } }; performed_by: { name: string } | null };
type LinkData = { url: string | null; label: string; active: boolean };
type Props = { movements: { data: Movement[]; links: LinkData[] }; filters: { warehouse?: number }; warehouses: Warehouse[] };

export default function MovementIndex({ movements, filters, warehouses }: Props) {
    return <InventoryLayout>
        <Head title="Inventory movements" />
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3"><h2 className="text-xl font-semibold">Movement history</h2><select value={filters.warehouse ?? ''} onChange={(e) => router.get('/inventory/movements', { warehouse: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2"><option value="">All warehouses</option>{warehouses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></div>
        <div className="overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">Date</th><th className="p-3">Warehouse</th><th className="p-3">Product / variant</th><th className="p-3">Type</th><th className="p-3 text-right">Quantity</th><th className="p-3 text-right">Before</th><th className="p-3 text-right">After</th><th className="p-3">Reason / reference</th><th className="p-3">Actor</th></tr></thead>
            <tbody>{movements.data.map((movement) => <tr key={movement.id} className="border-t"><td className="whitespace-nowrap p-3">{new Date(movement.created_at).toLocaleString()}</td><td className="p-3">{movement.warehouse.name}</td><td className="p-3">{movement.product_variant.product.name} · {movement.product_variant.label ?? 'Default'} · {movement.product_variant.sku}</td><td className="p-3">{movement.movement_type.replaceAll('_', ' ')}</td><td className="p-3 text-right tabular-nums">{movement.quantity}</td><td className="p-3 text-right tabular-nums">{movement.quantity_before}</td><td className="p-3 text-right tabular-nums">{movement.quantity_after}</td><td className="p-3">{movement.reason ?? movement.reference ?? '—'}</td><td className="p-3">{movement.performed_by?.name ?? 'System'}</td></tr>)}</tbody>
        </table></div>
        <div className="mt-5 flex flex-wrap gap-2">{movements.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded border px-3 py-1 ${link.active ? 'bg-slate-900 text-white' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div>
    </InventoryLayout>;
}
