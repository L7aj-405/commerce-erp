import { useEffect, useMemo, useRef, useState } from 'react';
import type { Option, ProductResult } from './types';
import PosProductCard from './PosProductCard';

type Props = {
    warehouseId: number | null;
    brands: Option[];
    categories: Option[];
    onAdd: (product: ProductResult) => void;
};

type Payload = {
    data: ProductResult[];
    meta: { current_page: number; has_more: boolean; next_page: number | null };
};

export default function PosProductSearch({ warehouseId, brands, categories, onAdd }: Props) {
    const [query, setQuery] = useState('');
    const [brandId, setBrandId] = useState<number | ''>('');
    const [categoryId, setCategoryId] = useState<number | ''>('');
    const [availability, setAvailability] = useState<'all' | 'in_stock' | 'out_of_stock'>('all');
    const [results, setResults] = useState<ProductResult[]>([]);
    const [nextPage, setNextPage] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const abortRef = useRef<AbortController | null>(null);
    const trimmed = useMemo(() => query.trim(), [query]);

    async function lookup(page = 1, append = false, params?: { barcode?: string }) {
        if (!warehouseId) {
            setResults([]);
            setError('Sélectionnez un entrepôt opérationnel avant de charger le catalogue.');
            return [];
        }

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        setLoading(true);
        setError('');

        const searchParams = new URLSearchParams({
            warehouse_id: String(warehouseId),
            page: String(page),
            availability,
        });

        if (trimmed) searchParams.set('search', trimmed);
        if (brandId) searchParams.set('brand_id', String(brandId));
        if (categoryId) searchParams.set('category_id', String(categoryId));
        if (params?.barcode) searchParams.set('barcode', params.barcode);

        try {
            const response = await fetch(`/pos/products?${searchParams.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(response.status === 403 ? 'Vous n’êtes pas autorisé à consulter le catalogue POS.' : 'Le chargement du catalogue a échoué.');
            }

            const payload = await response.json() as Payload;
            setResults(current => append ? [...current, ...payload.data] : payload.data);
            setNextPage(payload.meta.next_page);

            return payload.data;
        } catch (reason) {
            if ((reason as Error).name === 'AbortError') {
                return [];
            }

            setError(reason instanceof Error ? reason.message : 'Le chargement du catalogue a échoué.');
            return [];
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        const timer = window.setTimeout(() => {
            void lookup(1, false);
        }, trimmed ? 275 : 0);

        return () => window.clearTimeout(timer);
    }, [warehouseId, trimmed, brandId, categoryId, availability]);

    return (
        <section className="space-y-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">Catalogue showroom</h2>
                    <p className="text-sm text-slate-500">Produits visibles immédiatement, puis filtrés par recherche, SKU, référence, marque ou code-barres.</p>
                </div>
                <div className="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">Recherche serveur · 275 ms</div>
            </div>

            <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_12rem_12rem_11rem]">
                <input
                    autoFocus
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={async (event) => {
                        if (event.key !== 'Enter' || !trimmed) return;
                        event.preventDefault();
                        const matches = await lookup(1, false, { barcode: trimmed });
                        if (matches.length === 1 && Number(matches[0].stock.available) > 0) {
                            onAdd(matches[0]);
                            setQuery('');
                        }
                    }}
                    placeholder="Rechercher produit / SKU / référence / code-barres"
                    className="h-12 rounded-2xl border border-slate-300 px-4 text-base focus:border-slate-700 focus:outline-none"
                />
                <select value={brandId} onChange={(event) => setBrandId(event.target.value ? Number(event.target.value) : '')} className="h-12 rounded-2xl border border-slate-300 px-3 text-sm">
                    <option value="">Toutes les marques</option>
                    {brands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}
                </select>
                <select value={categoryId} onChange={(event) => setCategoryId(event.target.value ? Number(event.target.value) : '')} className="h-12 rounded-2xl border border-slate-300 px-3 text-sm">
                    <option value="">Toutes les catégories</option>
                    {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                </select>
                <select value={availability} onChange={(event) => setAvailability(event.target.value as 'all' | 'in_stock' | 'out_of_stock')} className="h-12 rounded-2xl border border-slate-300 px-3 text-sm">
                    <option value="all">Tous</option>
                    <option value="in_stock">Disponibles</option>
                    <option value="out_of_stock">Rupture</option>
                </select>
            </div>

            {error && <p className="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
            {loading && results.length === 0 && <p className="text-sm text-slate-500">Chargement du catalogue…</p>}

            <div className="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                {results.map((product) => <PosProductCard key={product.id} product={product} onAdd={onAdd} />)}
            </div>

            {!loading && results.length === 0 && !error && (
                <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">
                    Aucun produit ne correspond aux filtres actuels.
                </div>
            )}

            {nextPage && (
                <div className="flex justify-center">
                    <button type="button" disabled={loading} onClick={() => void lookup(nextPage, true)} className="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 disabled:opacity-50">
                        {loading ? 'Chargement…' : 'Charger plus de produits'}
                    </button>
                </div>
            )}
        </section>
    );
}
