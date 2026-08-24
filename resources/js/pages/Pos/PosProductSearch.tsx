import type { FormEvent } from 'react';
import { useState } from 'react';
import type { ProductResult } from './types';

type Props = {
    warehouseId: number | null;
    onAdd: (product: ProductResult) => void;
};

export default function PosProductSearch({ warehouseId, onAdd }: Props) {
    const [search, setSearch] = useState('');
    const [barcode, setBarcode] = useState('');
    const [results, setResults] = useState<ProductResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    async function lookup(params: { search?: string; barcode?: string }) {
        if (!warehouseId) {
            setError('Select a Warehouse before searching products.');
            return;
        }

        setLoading(true);
        setError('');

        try {
            const query = new URLSearchParams({ warehouse_id: String(warehouseId), ...params });
            const response = await fetch(`/pos/products?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(response.status === 403 ? 'You are not authorized to search POS products.' : 'Product search failed.');
            const payload = await response.json() as { data: ProductResult[] };
            setResults(payload.data);
            if (params.barcode && payload.data.length === 1) {
                onAdd(payload.data[0]);
                setBarcode('');
            } else if (params.barcode) {
                setError('No accessible active product matches this barcode.');
            }
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Product search failed.');
        } finally {
            setLoading(false);
        }
    }

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        if (search.trim()) void lookup({ search: search.trim() });
    }

    function submitBarcode(event: FormEvent) {
        event.preventDefault();
        if (barcode.trim()) void lookup({ barcode: barcode.trim() });
    }

    return (
        <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <h2 className="font-semibold">Scan or search products</h2>
                <p className="text-xs text-slate-500">Exact barcode scans add immediately. Search results are limited to 20.</p>
            </div>
            <form onSubmit={submitBarcode} className="flex gap-2">
                <input autoFocus value={barcode} onChange={(event) => setBarcode(event.target.value)} placeholder="Scan barcode, then Enter" className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-3 font-mono focus:border-slate-700 focus:outline-none" />
                <button disabled={loading || !warehouseId} className="rounded-lg bg-slate-900 px-4 py-2 text-white disabled:opacity-40">Add</button>
            </form>
            <form onSubmit={submitSearch} className="flex gap-2">
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, SKU, reference or barcode" className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 focus:border-slate-700 focus:outline-none" />
                <button disabled={loading || !warehouseId} className="rounded-lg border border-slate-300 px-4 py-2 disabled:opacity-40">Search</button>
            </form>
            {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {results.map((product) => (
                    <button key={product.id} type="button" onClick={() => onAdd(product)} className="rounded-lg border border-slate-200 p-3 text-left hover:border-slate-500 hover:bg-slate-50">
                        <strong className="block truncate">{product.product_name}</strong>
                        <span className="block truncate text-xs text-slate-500">{product.variant_name ?? 'Default'} · {product.sku}</span>
                        <span className="mt-2 flex justify-between text-sm"><span>{product.default_sale_price}</span><span className={Number(product.stock.available) > 0 ? 'text-emerald-700' : 'text-red-700'}>Available {product.stock.available}</span></span>
                    </button>
                ))}
            </div>
        </section>
    );
}
