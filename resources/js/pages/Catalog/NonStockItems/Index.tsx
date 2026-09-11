import { Button } from '@/components/ui/Button';
import DocBadge from '@/components/ui/DocBadge';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import FilterSelect from '@/components/ui/FilterSelect';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import SalesLayout from '@/layouts/SalesLayout';
import { formatMoney, formatQuantity } from '@/utils/format';
import { Head, router, useForm } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';

type Item = {
    id: number;
    name: string;
    reference: string | null;
    unit_label: string | null;
    price_input_mode: 'ht' | 'ttc';
    default_price_excl_tax: string | null;
    default_price_incl_tax: string | null;
    tax_rate: string;
    tax_name: string | null;
    usage_count: number;
    status: string;
    created_at: string;
    created_by: { name: string } | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    items: { data: Item[]; links: PageLink[] };
    filters: { search?: string; status?: string };
    taxRates: { id: number; name: string; rate: string }[];
    can: { manage: boolean };
};

export default function NonStockItemsIndex({ items, filters, taxRates, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const debounced = useDebouncedValue(search, 250);
    const mounted = useRef(false);
    const [editing, setEditing] = useState<number | null>(null);

    const apply = (patch: Record<string, string | undefined>) =>
        router.get('/catalog/non-stock-items', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;
            return;
        }
        apply({ search: debounced || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    return (
        <SalesLayout>
            <Head title="Articles hors stock" />
            <PageHeader
                title="Articles hors stock"
                description="Articles externes déjà proposés en devis. Ils n’ont aucun stock, aucune réservation, aucun mouvement d’inventaire — ce sont des candidats au catalogue produit."
                actions={
                    <a
                        href="/catalog/non-stock-items/export"
                        className="inline-flex min-h-10 items-center rounded-field border border-line-strong bg-surface px-4 text-sm text-ink hover:bg-raised"
                    >
                        Exporter (CSV)
                    </a>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchInput value={search} onChange={setSearch} searching={search !== (filters.search ?? '')} placeholder="Désignation, référence…" />
                <FilterSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
                    <option value="">Tous</option>
                    <option value="active">Actif</option>
                    <option value="inactive">Désactivé</option>
                </FilterSelect>
            </div>

            <div className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full min-w-[860px] text-left text-sm">
                    <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th className="px-4 py-3 font-medium">Désignation</th>
                            <th className="px-4 py-3 font-medium">Référence</th>
                            <th className="px-4 py-3 text-right font-medium">Prix HT</th>
                            <th className="px-4 py-3 text-right font-medium">Prix TTC</th>
                            <th className="px-4 py-3 font-medium">TVA</th>
                            <th className="px-4 py-3 text-right font-medium">Devis</th>
                            <th className="px-4 py-3 font-medium">Statut</th>
                            {can.manage && <th className="px-4 py-3" />}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {items.data.map((item) => (
                            <Fragment key={item.id}>
                                <tr>
                                    <td className="px-4 py-3 font-medium text-ink">{item.name}</td>
                                    <td className="px-4 py-3 text-ink-muted">{item.reference ?? '—'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{item.default_price_excl_tax ? formatMoney(item.default_price_excl_tax) : '—'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{item.default_price_incl_tax ? formatMoney(item.default_price_incl_tax) : '—'}</td>
                                    <td className="px-4 py-3 text-ink-muted">{formatQuantity(item.tax_rate)} %</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{item.usage_count}</td>
                                    <td className="px-4 py-3">
                                        <DocBadge tone={item.status === 'active' ? 'positive' : 'neutral'}>{item.status === 'active' ? 'Actif' : 'Désactivé'}</DocBadge>
                                    </td>
                                    {can.manage && (
                                        <td className="px-4 py-3 text-right">
                                            <button type="button" onClick={() => setEditing(editing === item.id ? null : item.id)} className="text-xs text-ink-muted hover:underline">
                                                Modifier
                                            </button>
                                        </td>
                                    )}
                                </tr>
                                {editing === item.id && can.manage && (
                                    <tr className="bg-raised/40">
                                        <td colSpan={8} className="px-4 py-3">
                                            <EditRow item={item} taxRates={taxRates} onDone={() => setEditing(null)} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                        {items.data.length === 0 && (
                            <tr>
                                <td colSpan={can.manage ? 8 : 7} className="px-4 py-10 text-center text-ink-muted">
                                    Aucun article hors stock enregistré.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination links={items.links} />
            <p className="mt-3 text-xs text-ink-faint">
                La promotion d’un article hors stock en produit du catalogue arrivera dans une phase ultérieure.
            </p>
        </SalesLayout>
    );
}

function EditRow({ item, taxRates, onDone }: { item: Item; taxRates: { id: number; name: string; rate: string }[]; onDone: () => void }) {
    const form = useForm({
        name: item.name,
        reference: item.reference ?? '',
        unit_label: item.unit_label ?? '',
        price_input_mode: item.price_input_mode,
        unit_price: item.price_input_mode === 'ttc' ? (item.default_price_incl_tax ?? '') : (item.default_price_excl_tax ?? ''),
        tax_rate_id: '' as string,
        status: item.status,
    });

    const submit = () => form.patch(`/catalog/non-stock-items/${item.id}`, { preserveScroll: true, onSuccess: onDone });

    return (
        <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Désignation" className={input} />
            <input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} placeholder="Référence" className={input} />
            <input value={form.data.unit_label} onChange={(e) => form.setData('unit_label', e.target.value)} placeholder="Unité" className={input} />
            <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={input}>
                <option value="active">Actif</option>
                <option value="inactive">Désactivé</option>
            </select>
            <div className="flex gap-1">
                <input value={form.data.unit_price} onChange={(e) => form.setData('unit_price', e.target.value)} placeholder="Prix" className={`${input} text-right`} />
                <select value={form.data.price_input_mode} onChange={(e) => form.setData('price_input_mode', e.target.value as 'ht' | 'ttc')} className="rounded-field border border-line-strong px-1 text-xs">
                    <option value="ht">HT</option>
                    <option value="ttc">TTC</option>
                </select>
            </div>
            <select value={form.data.tax_rate_id} onChange={(e) => form.setData('tax_rate_id', e.target.value)} className={input}>
                <option value="">TVA inchangée</option>
                <option value="0">Aucune TVA</option>
                {taxRates.map((t) => (
                    <option key={t.id} value={t.id}>
                        {t.name} ({formatQuantity(t.rate)} %)
                    </option>
                ))}
            </select>
            <div className="col-span-2 flex gap-2 md:col-span-4">
                <Button size="sm" onClick={submit} loading={form.processing}>
                    Enregistrer
                </Button>
                <button type="button" onClick={onDone} className="text-xs text-ink-muted hover:underline">
                    Annuler
                </button>
            </div>
        </div>
    );
}

const input = 'rounded-field border border-line-strong px-2 py-1 text-sm';
