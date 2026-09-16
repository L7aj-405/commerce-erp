import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Row = {
    id: number;
    description: string;
    requested_quantity: string;
    sales_order: { id: number; order_number: string } | null;
    customer: string | null;
    requested_by: string | null;
    created_at: string | null;
    status: string;
    status_label: string;
    resolved_article: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    articles: { data: Row[]; links: LinkData[] };
    filters: { search?: string; status: string };
    unresolvedCount: number;
    can: { manage: boolean };
};

const TABS = [
    { value: 'unresolved', label: 'À traiter' },
    { value: 'resolved', label: 'Résolus' },
    { value: 'all', label: 'Tous' },
] as const;

type VariantOption = { id: number; product_name: string; variant_name: string | null; sku: string | null; reference: string | null };

function ResolveForm({ articleId, onDone }: { articleId: number; onDone: () => void }) {
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<VariantOption[]>([]);
    const form = useForm({ product_variant_id: 0 });

    useEffect(() => {
        const timer = window.setTimeout(() => {
            fetch(`/procurement/out-of-stock-articles/${articleId}/variant-search?search=${encodeURIComponent(search)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((json) => setOptions(json.data ?? []))
                .catch(() => setOptions([]));
        }, 250);
        return () => window.clearTimeout(timer);
    }, [search, articleId]);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (!form.data.product_variant_id) return;
                form.post(`/procurement/out-of-stock-articles/${articleId}/resolve`, { preserveScroll: true, onSuccess: onDone });
            }}
            className="mt-2 flex flex-wrap items-center gap-2"
        >
            <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Rechercher un article catalogue (SKU, nom)…"
                className="h-9 min-w-[220px] rounded-lg border border-slate-300 px-2 text-xs"
            />
            <select
                value={form.data.product_variant_id}
                onChange={(e) => form.setData('product_variant_id', Number(e.target.value))}
                className="h-9 rounded-lg border border-slate-300 px-2 text-xs"
            >
                <option value={0}>Sélectionner…</option>
                {options.map((o) => (
                    <option key={o.id} value={o.id}>
                        {o.product_name} {o.variant_name ? `· ${o.variant_name}` : ''} ({o.sku ?? o.reference ?? o.id})
                    </option>
                ))}
            </select>
            <button
                type="submit"
                disabled={!form.data.product_variant_id || form.processing}
                className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                Associer à cet article
            </button>
            <button type="button" onClick={onDone} className="text-xs text-slate-500 hover:underline">
                Annuler
            </button>
        </form>
    );
}

export default function OutOfStockArticlesIndex({ articles, filters, unresolvedCount, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const [resolving, setResolving] = useState<number | null>(null);

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/procurement/out-of-stock-articles', next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined, status: filters.status, page: undefined }), 280);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <ProcurementLayout>
            <Head title="Articles hors stock" />
            <PageHeader
                title="Articles hors stock"
                description={`${unresolvedCount} article${unresolvedCount === 1 ? '' : 's'} à traiter — signalés sur une commande car pas encore présents au catalogue. Résoudre associe la demande à un article catalogue réel ; cela ne crée jamais de stock.`}
            />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <div className="inline-flex rounded-lg border border-slate-300 bg-white p-1">
                    {TABS.map((tab) => (
                        <button
                            key={tab.value}
                            onClick={() => visit({ search: filters.search, status: tab.value, page: undefined })}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                                filters.status === tab.value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Article, commande, client" />
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="w-full min-w-[900px] text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3 font-medium">Article demandé</th>
                            <th className="px-4 py-3 text-right font-medium">Qté</th>
                            <th className="px-4 py-3 font-medium">Commande</th>
                            <th className="px-4 py-3 font-medium">Client</th>
                            <th className="px-4 py-3 font-medium">Agent</th>
                            <th className="px-4 py-3 font-medium">Date</th>
                            <th className="px-4 py-3 font-medium">Statut</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {articles.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 text-slate-900">{row.description}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatQuantity(row.requested_quantity)}</td>
                                <td className="px-4 py-3">
                                    {row.sales_order ? (
                                        <Link href={`/sales/orders/${row.sales_order.id}`} className="text-slate-900 underline">
                                            {row.sales_order.order_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-4 py-3 text-slate-600">{row.customer ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-600">{row.requested_by ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-600">{row.created_at ? formatDate(row.created_at) : '—'}</td>
                                <td className="px-4 py-3">
                                    {row.status === 'resolved' ? (
                                        <div>
                                            <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                                Résolu
                                            </span>
                                            <p className="mt-1 text-xs text-slate-500">
                                                → {row.resolved_article} · par {row.resolved_by ?? '—'}
                                                {row.resolved_at ? ` le ${formatDate(row.resolved_at)}` : ''}
                                            </p>
                                        </div>
                                    ) : resolving === row.id ? (
                                        <ResolveForm articleId={row.id} onDone={() => setResolving(null)} />
                                    ) : (
                                        <div className="flex items-center gap-2">
                                            <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
                                                À traiter
                                            </span>
                                            {can.manage && (
                                                <button
                                                    onClick={() => setResolving(row.id)}
                                                    className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-900 hover:bg-slate-50"
                                                >
                                                    Associer à un article
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {articles.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                                    Aucun article hors stock.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination links={articles.links} />
        </ProcurementLayout>
    );
}
