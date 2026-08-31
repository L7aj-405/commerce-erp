import { ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import SearchInput from '@/components/ui/SearchInput';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatInteger, formatQuantity } from '@/utils/format';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type SearchResult = {
    id: number;
    label: string | null;
    sku: string;
    reference: string | null;
    barcode: string | null;
    product: { id: number; name: string; brand: { id: number; name: string } | null; category: { id: number; name: string } | null };
    availability: { on_hand: string; reserved: string; available: string };
};
type PreselectedVariant = { id: number; label: string | null; sku: string; reference: string | null; product: { id: number; name: string; brand: { id: number; name: string } | null } } | null;
type Props = {
    filters: { source_warehouse_id?: number; destination_warehouse_id?: number; search?: string; variant_id?: number };
    warehouses: Warehouse[];
    searchResults: SearchResult[];
    preselectedVariant: PreselectedVariant;
};
type SelectedLine = {
    product_variant_id: number;
    product_name: string;
    label: string | null;
    sku: string;
    available: string;
    quantity: string;
};

export default function TransferCreate({ filters, warehouses, searchResults, preselectedVariant }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [selectedLines, setSelectedLines] = useState<SelectedLine[]>([]);
    const form = useForm({
        source_warehouse_id: filters.source_warehouse_id ? String(filters.source_warehouse_id) : '',
        destination_warehouse_id: filters.destination_warehouse_id ? String(filters.destination_warehouse_id) : '',
        reason: '',
        lines: [] as Array<{ product_variant_id: number; quantity: number }>,
    });

    const sourceSelected = form.data.source_warehouse_id !== '';
    const warehouseCount = warehouses.length;

    useEffect(() => {
        if (!preselectedVariant) return;
        setSelectedLines(current => current.some(line => line.product_variant_id === preselectedVariant.id) ? current : [
            ...current,
            {
                product_variant_id: preselectedVariant.id,
                product_name: preselectedVariant.product.name,
                label: preselectedVariant.label,
                sku: preselectedVariant.sku,
                available: '0.0000',
                quantity: '1',
            },
        ]);
    }, [preselectedVariant?.id]);

    useEffect(() => {
        if (!sourceSelected) return;
        const timer = window.setTimeout(() => {
            router.get('/inventory/transfers/create', {
                source_warehouse_id: form.data.source_warehouse_id || undefined,
                destination_warehouse_id: form.data.destination_warehouse_id || undefined,
                search: search || undefined,
                variant_id: filters.variant_id,
            }, {
                only: ['searchResults', 'filters', 'preselectedVariant'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            });
        }, 280);

        return () => window.clearTimeout(timer);
    }, [search, form.data.source_warehouse_id, form.data.destination_warehouse_id]);

    useEffect(() => {
        setSelectedLines(current => current.map(line => {
            const result = searchResults.find(item => item.id === line.product_variant_id);

            return result ? { ...line, available: result.availability.available } : line;
        }));
    }, [searchResults]);

    const selectedWarehouseName = warehouses.find(warehouse => warehouse.id === Number(form.data.source_warehouse_id))?.name;
    const totalUnits = selectedLines.reduce((carry, line) => carry + (Number(line.quantity) || 0), 0);

    function addResult(result: SearchResult) {
        setSelectedLines(current => current.some(line => line.product_variant_id === result.id) ? current : [
            ...current,
            {
                product_variant_id: result.id,
                product_name: result.product.name,
                label: result.label,
                sku: result.sku,
                available: result.availability.available,
                quantity: '1',
            },
        ]);
    }

    function removeLine(productVariantId: number) {
        setSelectedLines(current => current.filter(line => line.product_variant_id !== productVariantId));
    }

    function setQuantity(productVariantId: number, quantity: string) {
        setSelectedLines(current => current.map(line => line.product_variant_id === productVariantId ? { ...line, quantity } : line));
    }

    function submit() {
        router.post('/inventory/transfers', {
            ...form.data,
            lines: selectedLines.map(line => ({
                product_variant_id: line.product_variant_id,
                quantity: Number(line.quantity),
            })),
        }, {
            preserveScroll: true,
        });
    }

    if (warehouseCount < 2) {
        return (
            <InventoryLayout>
                <Head title="Nouveau transfert" />
                <PageHeader title="Transferts" description="Deplacez du stock entre vos emplacements." />
                <EmptyState
                    title="Vous devez creer au moins deux emplacements pour effectuer un transfert."
                    description="Ajoutez un second emplacement, puis revenez ici pour deplacer votre stock."
                    actions={<ButtonLink href="/inventory/warehouses">+ Ajouter un emplacement</ButtonLink>}
                />
            </InventoryLayout>
        );
    }

    return (
        <InventoryLayout wide>
            <Head title="Nouveau transfert" />
            <PageHeader
                title="Transferts"
                description="Deplacez du stock entre vos emplacements."
                actions={<ButtonLink href="/inventory/transfers" variant="secondary">Voir l'historique</ButtonLink>}
            />

            <div className="grid gap-6 xl:grid-cols-[1.3fr_0.7fr]">
                <section className="space-y-6">
                    <div className="rounded-2xl border bg-white p-5">
                        <h2 className="font-semibold">Trajet du transfert</h2>
                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <label className="text-sm font-medium text-slate-700">
                                De
                                <select value={form.data.source_warehouse_id} onChange={event => form.setData('source_warehouse_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 font-normal">
                                    <option value="">Selectionner la source</option>
                                    {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                </select>
                                {form.errors.source_warehouse_id && <p className="mt-1 text-sm text-red-600">{form.errors.source_warehouse_id}</p>}
                            </label>
                            <label className="text-sm font-medium text-slate-700">
                                Vers
                                <select value={form.data.destination_warehouse_id} onChange={event => form.setData('destination_warehouse_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 font-normal">
                                    <option value="">Selectionner la destination</option>
                                    {warehouses.map(warehouse => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                                </select>
                                {form.errors.destination_warehouse_id && <p className="mt-1 text-sm text-red-600">{form.errors.destination_warehouse_id}</p>}
                            </label>
                            <label className="text-sm font-medium text-slate-700 md:col-span-2">
                                Motif
                                <textarea value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} rows={3} className="mt-1 w-full rounded-lg border px-3 py-2 font-normal" placeholder="Ex. reequilibrage showroom / depot" />
                                {form.errors.reason && <p className="mt-1 text-sm text-red-600">{form.errors.reason}</p>}
                            </label>
                        </div>
                    </div>

                    <div className="rounded-2xl border bg-white p-5">
                        <h2 className="font-semibold">Recherche de produits</h2>
                        <p className="mt-1 text-sm text-slate-600">La recherche affiche uniquement la disponibilite du magasin source selectionne.</p>
                        <div className="mt-4">
                            <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Rechercher un produit, SKU, reference ou marque..." />
                        </div>

                        {!sourceSelected ? (
                            <p className="mt-4 text-sm text-slate-500">Selectionnez d'abord l'emplacement source pour charger les disponibilites.</p>
                        ) : searchResults.length === 0 ? (
                            <p className="mt-4 text-sm text-slate-500">{search ? 'Aucun produit disponible ne correspond a cette recherche.' : 'Aucun produit disponible dans cet emplacement.'}</p>
                        ) : (
                            <div className="mt-4 grid gap-3">
                                {searchResults.map(result => (
                                    <button key={result.id} type="button" onClick={() => addResult(result)} className="rounded-xl border border-slate-200 p-4 text-left hover:border-slate-400 hover:bg-slate-50">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="font-semibold">{result.product.name}</p>
                                                <p className="text-sm text-slate-500">{result.label ?? 'Variante principale'} · {result.sku}</p>
                                                <p className="text-xs text-slate-500">{result.product.brand?.name ?? 'Sans marque'}</p>
                                            </div>
                                            <div className="text-right text-sm">
                                                <p className="text-slate-500">Disponible</p>
                                                <p className="font-semibold">{formatQuantity(result.availability.available)}</p>
                                            </div>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </section>

                <aside className="rounded-2xl border bg-white p-5">
                    <h2 className="font-semibold">Produits selectionnes</h2>
                    {selectedLines.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">Selectionnez un ou plusieurs produits pour preparer votre transfert.</p>
                    ) : (
                        <>
                            <div className="mt-4 space-y-4">
                                {selectedLines.map((line, index) => (
                                    <div key={line.product_variant_id} className="rounded-xl border border-slate-200 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="font-semibold">{line.product_name}</p>
                                                <p className="text-sm text-slate-500">{line.label ?? 'Variante principale'} · {line.sku}</p>
                                                <p className="text-xs text-slate-500">Disponible {selectedWarehouseName ? `dans ${selectedWarehouseName}` : ''}: {formatQuantity(line.available)}</p>
                                            </div>
                                            <button type="button" onClick={() => removeLine(line.product_variant_id)} className="text-sm text-red-700 hover:underline">Retirer</button>
                                        </div>
                                        <label className="mt-3 block text-sm font-medium text-slate-700">
                                            Quantite
                                            <input type="number" min={1} max={Math.max(1, Number(line.available) || 1)} step={1} value={line.quantity} onChange={event => setQuantity(line.product_variant_id, event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 font-normal" />
                                        </label>
                                        {form.errors[`lines.${index}.quantity` as keyof typeof form.errors] && <p className="mt-1 text-sm text-red-600">{form.errors[`lines.${index}.quantity` as keyof typeof form.errors]}</p>}
                                    </div>
                                ))}
                            </div>

                            <div className="mt-5 rounded-xl bg-slate-50 p-4 text-sm">
                                <div className="flex justify-between gap-3">
                                    <span>Produits</span>
                                    <strong>{formatInteger(selectedLines.length)}</strong>
                                </div>
                                <div className="mt-2 flex justify-between gap-3">
                                    <span>Unites</span>
                                    <strong>{formatQuantity(totalUnits)}</strong>
                                </div>
                            </div>

                            <button type="button" onClick={submit} disabled={form.processing || selectedLines.length === 0} className="mt-5 inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{form.processing ? 'Transfert en cours...' : 'Confirmer le transfert'}</button>
                        </>
                    )}
                </aside>
            </div>
        </InventoryLayout>
    );
}
