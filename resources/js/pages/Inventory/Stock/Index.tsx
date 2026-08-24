import InventoryLayout from '@/layouts/InventoryLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type Variant = { id: number; label: string | null; sku: string; product: { id: number; name: string } };
type Balance = { id: number; on_hand: string; reserved: string; available: string; warehouse: Warehouse; product_variant: Variant };
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    balances: { data: Balance[]; links: LinkData[] };
    filters: { search?: string; warehouse?: number };
    warehouses: Warehouse[];
    variants: Variant[];
    can: { opening: boolean; adjust: boolean };
};

export default function StockIndex({ balances, filters, warehouses, variants, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const opening = useForm({ warehouse_id: '', product_variant_id: '', quantity: '', reason: '', reference: '' });
    const adjustment = useForm({ warehouse_id: '', product_variant_id: '', type: 'adjustment_in', quantity: '', reason: '', reference: '' });

    function filter(event: FormEvent) {
        event.preventDefault();
        router.get('/inventory/stock', { ...filters, search: search || undefined }, { preserveState: true, replace: true });
    }

    return (
        <InventoryLayout>
            <Head title="Stock" />
            <h2 className="mb-5 text-xl font-semibold">Stock overview</h2>
            <form onSubmit={filter} className="mb-6 flex flex-wrap gap-3 rounded-lg bg-slate-50 p-4">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Product, variant or SKU" className="min-w-64 rounded border px-3 py-2" />
                <select value={filters.warehouse ?? ''} onChange={(e) => router.get('/inventory/stock', { ...filters, warehouse: e.target.value || undefined }, { preserveState: true })} className="rounded border px-3 py-2"><option value="">All warehouses</option>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select>
                <button className="rounded border px-4 py-2">Search</button>
            </form>

            <div className="mb-8 overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm">
                <thead className="bg-slate-50"><tr><th className="p-3">Product</th><th className="p-3">Variant / SKU</th><th className="p-3">Warehouse</th><th className="p-3 text-right">On hand</th><th className="p-3 text-right">Reserved</th><th className="p-3 text-right">Available</th></tr></thead>
                <tbody>{balances.data.map((balance) => <tr key={balance.id} className="border-t"><td className="p-3 font-medium">{balance.product_variant.product.name}</td><td className="p-3">{balance.product_variant.label ?? 'Default'} · {balance.product_variant.sku}</td><td className="p-3">{balance.warehouse.name}</td><td className="p-3 text-right tabular-nums">{balance.on_hand}</td><td className="p-3 text-right tabular-nums">{balance.reserved}</td><td className="p-3 text-right font-medium tabular-nums">{balance.available}</td></tr>)}</tbody>
            </table></div>
            <div className="mb-8 flex flex-wrap gap-2">{balances.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={`rounded border px-3 py-1 ${link.active ? 'bg-slate-900 text-white' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : null)}</div>

            <div className="grid gap-6 lg:grid-cols-2">
                {can.opening && <form onSubmit={(e) => { e.preventDefault(); opening.post('/inventory/opening-stock', { preserveScroll: true, onSuccess: () => opening.reset() }); }} className="space-y-3 rounded-lg border p-5">
                    <h3 className="font-semibold">Add opening stock</h3>
                    <select required value={opening.data.warehouse_id} onChange={(e) => opening.setData('warehouse_id', e.target.value)} className="w-full rounded border px-3 py-2"><option value="">Warehouse</option>{warehouses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
                    <select required value={opening.data.product_variant_id} onChange={(e) => opening.setData('product_variant_id', e.target.value)} className="w-full rounded border px-3 py-2"><option value="">Product / variant</option>{variants.map((item) => <option key={item.id} value={item.id}>{item.product.name} · {item.label ?? 'Default'} · {item.sku}</option>)}</select>
                    <input required inputMode="decimal" value={opening.data.quantity} onChange={(e) => opening.setData('quantity', e.target.value)} placeholder="Quantity" className="w-full rounded border px-3 py-2" />
                    <input value={opening.data.reason} onChange={(e) => opening.setData('reason', e.target.value)} placeholder="Reason (optional)" className="w-full rounded border px-3 py-2" />
                    <input value={opening.data.reference} onChange={(e) => opening.setData('reference', e.target.value)} placeholder="Reference (optional)" className="w-full rounded border px-3 py-2" />
                    {Object.values(opening.errors).map((error, index) => <p key={index} className="text-sm text-red-600">{error}</p>)}
                    <button disabled={opening.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Add opening stock</button>
                </form>}

                {can.adjust && <form onSubmit={(e) => { e.preventDefault(); adjustment.post('/inventory/adjustments', { preserveScroll: true, onSuccess: () => adjustment.reset() }); }} className="space-y-3 rounded-lg border p-5">
                    <h3 className="font-semibold">Stock adjustment</h3>
                    <select required value={adjustment.data.warehouse_id} onChange={(e) => adjustment.setData('warehouse_id', e.target.value)} className="w-full rounded border px-3 py-2"><option value="">Warehouse</option>{warehouses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
                    <select required value={adjustment.data.product_variant_id} onChange={(e) => adjustment.setData('product_variant_id', e.target.value)} className="w-full rounded border px-3 py-2"><option value="">Product / variant</option>{variants.map((item) => <option key={item.id} value={item.id}>{item.product.name} · {item.label ?? 'Default'} · {item.sku}</option>)}</select>
                    <select value={adjustment.data.type} onChange={(e) => adjustment.setData('type', e.target.value)} className="w-full rounded border px-3 py-2"><option value="adjustment_in">Adjustment in</option><option value="adjustment_out">Adjustment out</option></select>
                    <input required inputMode="decimal" value={adjustment.data.quantity} onChange={(e) => adjustment.setData('quantity', e.target.value)} placeholder="Quantity" className="w-full rounded border px-3 py-2" />
                    <input required value={adjustment.data.reason} onChange={(e) => adjustment.setData('reason', e.target.value)} placeholder="Reason" className="w-full rounded border px-3 py-2" />
                    <input value={adjustment.data.reference} onChange={(e) => adjustment.setData('reference', e.target.value)} placeholder="Reference (optional)" className="w-full rounded border px-3 py-2" />
                    {Object.values(adjustment.errors).map((error, index) => <p key={index} className="text-sm text-red-600">{error}</p>)}
                    <button disabled={adjustment.processing} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">Apply adjustment</button>
                </form>}
            </div>
        </InventoryLayout>
    );
}
