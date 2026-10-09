import { Button, DownloadLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import CatalogLayout from '@/layouts/CatalogLayout';
import { formatMoney, formatQuantity } from '@/utils/format';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useState } from 'react';

type Option = { id: number; name: string };
type Variant = {
    id: number;
    product_id: number;
    label: string | null;
    sku: string | null;
    reference: string | null;
    barcode: string | null;
    purchase_price: string | null;
    current_stock: string;
    status: string;
    product: { name: string; status: string; brand: Option | null; default_category: Option | null };
};
type Props = {
    variants: { data: Variant[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: { search?: string; stock: string; cost: string; brand?: number; category?: number };
    coverage: { in_stock: number; with_cost: number; missing_cost: number; coverage: number };
    brands: Option[];
    categories: Option[];
    can: { export: boolean; import: boolean; manage: boolean };
};

export default function ProductCostIndex({ variants, filters, coverage, brands, categories, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [showImport, setShowImport] = useState(false);
    const importForm = useForm<{ file: File | null }>({ file: null });
    const visit = (next: Record<string, string | number | undefined>) =>
        router.get('/catalog/purchase-prices', next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ ...filters, search: search || undefined, page: undefined }), 300);
        return () => window.clearTimeout(timer);
    }, [search]);

    const exportHref = useMemo(() => {
        const params = new URLSearchParams();
        Object.entries({ ...filters, search: search || undefined }).forEach(([key, value]) => {
            if (value !== undefined && value !== '') params.set(key, String(value));
        });
        return `/catalog/purchase-prices/export?${params.toString()}`;
    }, [filters, search]);
    const upload = (event: FormEvent) => {
        event.preventDefault();
        importForm.post('/catalog/purchase-prices/import', { forceFormData: true });
    };

    return (
        <CatalogLayout>
            <Head title="Prix d'achat" />
            <PageHeader
                title="Prix d'achat"
                description="Renseignez les coûts unitaires d’acquisition HT au niveau de chaque variante."
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.export && <DownloadLink href={exportHref} variant="secondary">Exporter Excel</DownloadLink>}
                        {can.import && <Button type="button" onClick={() => setShowImport((value) => !value)}>Importer Excel</Button>}
                    </div>
                }
            />

            <section className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Summary label="Variantes actives en stock" value={coverage.in_stock.toLocaleString('fr-FR')} />
                <Summary label="Avec prix d'achat" value={coverage.with_cost.toLocaleString('fr-FR')} tone="success" />
                <Summary label="Sans prix d'achat" value={coverage.missing_cost.toLocaleString('fr-FR')} tone="warning" />
                <Summary label="Couverture" value={`${coverage.coverage.toLocaleString('fr-FR')} %`} />
            </section>

            {showImport && can.import && (
                <form onSubmit={upload} className="mb-6 rounded-card border border-line bg-surface p-5 shadow-soft">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end">
                        <div className="min-w-0 flex-1">
                            <label htmlFor="cost-file" className="text-sm font-semibold text-ink">Fichier XLSX ou CSV</label>
                            <p className="mt-1 text-xs text-ink-muted">Utilisez l’export ERP et remplissez uniquement « New Purchase Price HT ». Un prix vide ne change rien.</p>
                            <input
                                id="cost-file"
                                type="file"
                                accept=".xlsx,.csv"
                                onChange={(event) => importForm.setData('file', event.target.files?.[0] ?? null)}
                                className="mt-3 block w-full rounded-field border border-line-strong bg-surface px-3 py-2 text-sm"
                            />
                            {importForm.errors.file && <p className="mt-2 text-sm text-danger">{importForm.errors.file}</p>}
                        </div>
                        <Button type="submit" disabled={!importForm.data.file || importForm.processing}>{importForm.processing ? 'Analyse…' : 'Prévisualiser'}</Button>
                    </div>
                </form>
            )}

            <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="grid gap-2 lg:grid-cols-[minmax(240px,1fr)_160px_180px_190px_190px_auto]">
                    <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Produit, SKU, référence, code-barres…" />
                    <FilterSelect aria-label="Stock" value={filters.stock} onChange={(event) => visit({ ...filters, search: search || undefined, stock: event.target.value, page: undefined })}>
                        <option value="all">Tous les stocks</option><option value="in_stock">En stock</option><option value="out_of_stock">Rupture</option>
                    </FilterSelect>
                    <FilterSelect aria-label="Prix d'achat" value={filters.cost} onChange={(event) => visit({ ...filters, search: search || undefined, cost: event.target.value, page: undefined })}>
                        <option value="all">Tous les prix</option><option value="missing">Prix manquant</option><option value="present">Prix renseigné</option>
                    </FilterSelect>
                    <FilterSelect aria-label="Marque" value={filters.brand ?? ''} onChange={(event) => visit({ ...filters, search: search || undefined, brand: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                        <option value="">Toutes les marques</option>{brands.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </FilterSelect>
                    <FilterSelect aria-label="Catégorie" value={filters.category ?? ''} onChange={(event) => visit({ ...filters, search: search || undefined, category: event.target.value ? Number(event.target.value) : undefined, page: undefined })}>
                        <option value="">Toutes les catégories</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </FilterSelect>
                    <button type="button" onClick={() => { setSearch(''); visit({ stock: 'in_stock', cost: 'missing' }); }} className="rounded-field px-3 text-sm font-medium text-ink-muted hover:bg-sage">Réinitialiser</button>
                </div>
            </section>

            {variants.data.length === 0 ? (
                <EmptyState title="Aucune variante trouvée" description="Modifiez les filtres ou exportez un autre périmètre du catalogue." />
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface shadow-soft md:block">
                        <table className="w-full min-w-[1050px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint"><tr>
                                <th className="px-4 py-3">Produit / variante</th><th className="px-4 py-3">SKU / référence</th><th className="px-4 py-3">Marque</th><th className="px-4 py-3">Catégorie</th><th className="px-4 py-3 text-right">Stock</th><th className="px-4 py-3 text-right">Prix d'achat HT</th><th className="px-4 py-3">Statut</th>{can.manage && <th className="px-4 py-3">Mise à jour</th>}
                            </tr></thead>
                            <tbody className="divide-y divide-line">{variants.data.map((variant) => <tr key={variant.id} className="hover:bg-raised/70">
                                <td className="px-4 py-3"><p className="font-semibold text-ink">{variant.product.name}</p><p className="text-xs text-ink-muted">{variant.label || 'Variante principale'} · ID {variant.id}</p></td>
                                <td className="px-4 py-3"><p>{variant.sku || '—'}</p><p className="text-xs text-ink-faint">{variant.reference || variant.barcode || 'Aucun identifiant secondaire'}</p></td>
                                <td className="px-4 py-3 text-ink-muted">{variant.product.brand?.name ?? '—'}</td><td className="px-4 py-3 text-ink-muted">{variant.product.default_category?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatQuantity(variant.current_stock)}</td><td className="px-4 py-3 text-right font-semibold tabular-nums">{variant.purchase_price === null ? <span className="text-warning">Manquant</span> : formatMoney(variant.purchase_price)}</td>
                                <td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${variant.status === 'active' && variant.product.status === 'active' ? 'bg-success-soft text-success' : 'bg-raised text-ink-muted'}`}>{variant.status === 'active' && variant.product.status === 'active' ? 'Actif' : 'Archivé'}</span></td>{can.manage && <td className="px-4 py-3"><CostEditor variant={variant} /></td>}
                            </tr>)}</tbody>
                        </table>
                    </div>
                    <div className="grid gap-3 md:hidden">{variants.data.map((variant) => <article key={variant.id} className="rounded-card border border-line bg-surface p-4 shadow-soft">
                        <div className="flex items-start justify-between gap-3"><div><p className="font-semibold">{variant.product.name}</p><p className="text-sm text-ink-muted">{variant.label || 'Variante principale'} · {variant.sku || `ID ${variant.id}`}</p></div><p className="font-semibold tabular-nums">{variant.purchase_price === null ? 'Manquant' : formatMoney(variant.purchase_price)}</p></div>
                        <div className="mt-3 flex justify-between border-t border-line pt-3 text-sm"><span className="text-ink-muted">Stock organisation</span><span className="font-medium">{formatQuantity(variant.current_stock)}</span></div>{can.manage && <div className="mt-3"><CostEditor variant={variant} /></div>}
                    </article>)}</div>
                    <Pagination links={variants.links} />
                </>
            )}
        </CatalogLayout>
    );
}

function Summary({ label, value, tone = 'default' }: { label: string; value: string; tone?: 'default' | 'success' | 'warning' }) {
    const color = tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : 'text-ink';
    return <div className="rounded-card border border-line bg-surface p-4 shadow-soft"><p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</p><p className={`mt-2 text-2xl font-semibold tabular-nums ${color}`}>{value}</p></div>;
}

function CostEditor({ variant }: { variant: Variant }) {
    const form = useForm({ purchase_price: variant.purchase_price ?? '' });
    return <form onSubmit={(event) => { event.preventDefault(); form.patch(`/catalog/purchase-prices/${variant.id}`, { preserveScroll: true }); }} className="flex min-w-52 items-start gap-2">
        <div><input aria-label={`Prix d'achat HT de ${variant.product.name}`} inputMode="decimal" value={form.data.purchase_price} onChange={(event) => form.setData('purchase_price', event.target.value)} className="w-28 rounded-field border border-line-strong px-2.5 py-2 text-right text-sm tabular-nums" placeholder="0,0000" />{form.errors.purchase_price && <p className="mt-1 text-xs text-danger">{form.errors.purchase_price}</p>}</div>
        <Button type="submit" size="sm" disabled={form.processing || form.data.purchase_price === ''}>Enregistrer</Button>
    </form>;
}
