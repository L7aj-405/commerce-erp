import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Row = {
    id: number;
    product_name: string | null;
    variant_label: string | null;
    sku: string | null;
    quantity_delta: string;
    source_reference: string | null;
    reason: string;
    status: string;
    status_label: string;
    created_at: string | null;
    completed_at: string | null;
    completed_by: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    tasks: { data: Row[]; links: LinkData[] };
    filters: { search?: string; status: string };
    pendingCount: number;
    can: { manage: boolean };
};

const TABS = [
    { value: 'pending', label: 'À faire' },
    { value: 'completed', label: 'Mis à jour' },
    { value: 'all', label: 'Tous' },
] as const;

export default function WooCommerceStockTasksIndex({ tasks, filters, pendingCount, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/integrations/woocommerce/stock-tasks', next, {
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

    const complete = (id: number) =>
        router.post(`/integrations/woocommerce/stock-tasks/${id}/complete`, {}, { preserveScroll: true });

    return (
        <ApplicationShell>
            <Head title="Stock WooCommerce à mettre à jour" />
            <PageHeader
                title="Stock WooCommerce"
                description={`${pendingCount} article${pendingCount === 1 ? '' : 's'} à mettre à jour sur le site WooCommerce. La mise à jour se fait manuellement sur le site — l’ERP ne modifie jamais WooCommerce automatiquement.`}
                actions={
                    <Link
                        href="/integrations/woocommerce"
                        className="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm text-slate-900 hover:bg-slate-50"
                    >
                        Intégration WooCommerce
                    </Link>
                }
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
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Article, SKU, commande" />
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="w-full min-w-[900px] text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3 font-medium">Article</th>
                            <th className="px-4 py-3 font-medium">SKU</th>
                            <th className="px-4 py-3 text-right font-medium">Mouvement</th>
                            <th className="px-4 py-3 font-medium">Source</th>
                            <th className="px-4 py-3 font-medium">Date</th>
                            <th className="px-4 py-3 font-medium">Statut</th>
                            <th className="px-4 py-3 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {tasks.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 text-slate-900">
                                    {row.product_name}
                                    {row.variant_label ? <span className="text-slate-500"> · {row.variant_label}</span> : null}
                                </td>
                                <td className="px-4 py-3 text-slate-600">{row.sku ?? '—'}</td>
                                <td className="px-4 py-3 text-right tabular-nums text-slate-900">{formatQuantity(row.quantity_delta)}</td>
                                <td className="px-4 py-3 text-slate-600">{row.source_reference ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-600">{row.created_at ? formatDate(row.created_at) : '—'}</td>
                                <td className="px-4 py-3">
                                    {row.status === 'completed' ? (
                                        <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                            Mis à jour
                                        </span>
                                    ) : row.status === 'cancelled' ? (
                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500">Annulé</span>
                                    ) : (
                                        <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">À faire</span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {row.status === 'pending' && can.manage ? (
                                        <button
                                            onClick={() => complete(row.id)}
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-900 hover:bg-slate-50"
                                        >
                                            Marquer comme mis à jour
                                        </button>
                                    ) : row.status === 'completed' ? (
                                        <span className="text-xs text-slate-500">
                                            par {row.completed_by ?? '—'}
                                            {row.completed_at ? ` le ${formatDate(row.completed_at)}` : ''}
                                        </span>
                                    ) : null}
                                </td>
                            </tr>
                        ))}
                        {tasks.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                                    Aucune tâche WooCommerce.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination links={tasks.links} />
        </ApplicationShell>
    );
}
