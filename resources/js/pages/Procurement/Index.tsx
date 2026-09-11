import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import ProcurementLayout from '@/layouts/ProcurementLayout';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Row = {
    id: number;
    procurement_number: string;
    sales_order: { id: number; order_number: string } | null;
    supplier: { id: number; name: string } | null;
    product: string;
    quantity: string;
    status: string;
    status_label: string;
    supplier_availability_status: string;
    ordered_at: string | null;
    expected_at: string | null;
    received_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    procurements: { data: Row[]; links: LinkData[] };
    filters: { search?: string; status?: string };
    can: { manage: boolean; receive: boolean; suppliers: boolean };
};

const STATUS_ORDER = [
    'pending_supplier',
    'supplier_confirmed',
    'ordered',
    'received',
    'completed',
    'cancelled',
    'unavailable',
] as const;
const STATUS_LABEL: Record<string, string> = {
    pending_supplier: 'En attente fournisseur',
    supplier_confirmed: 'Disponibilité confirmée',
    ordered: 'Commandé',
    received: 'Réceptionné',
    completed: 'Clôturé',
    cancelled: 'Annulé',
    unavailable: 'Indisponible',
};
const statusClass = (s: string) =>
    s === 'received' || s === 'completed'
        ? 'bg-emerald-50 text-emerald-700'
        : s === 'cancelled' || s === 'unavailable'
          ? 'bg-slate-100 text-slate-500'
          : s === 'ordered'
            ? 'bg-indigo-50 text-indigo-700'
            : 'bg-amber-50 text-amber-700';

export default function ProcurementIndex({ procurements, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/procurement', next, {
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
            <Head title="Approvisionnements fournisseur" />
            <PageHeader
                title="Approvisionnements fournisseur"
                description="Commandes spéciales : la marchandise est commandée au fournisseur pour un client précis et reste réservée à sa commande à la réception."
                actions={
                    can.suppliers ? (
                        <Link
                            href="/procurement/suppliers"
                            className="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm text-slate-900 hover:bg-slate-50"
                        >
                            Fournisseurs
                        </Link>
                    ) : undefined
                }
            />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="N°, commande, fournisseur" />
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => visit({ search: filters.search, status: e.target.value || undefined, page: undefined })}
                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm"
                >
                    <option value="">Tous les statuts</option>
                    {STATUS_ORDER.map((s) => (
                        <option key={s} value={s}>
                            {STATUS_LABEL[s]}
                        </option>
                    ))}
                </select>
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table className="w-full min-w-[900px] text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3 font-medium">N°</th>
                            <th className="px-4 py-3 font-medium">Commande client</th>
                            <th className="px-4 py-3 font-medium">Fournisseur</th>
                            <th className="px-4 py-3 font-medium">Article</th>
                            <th className="px-4 py-3 text-right font-medium">Qté</th>
                            <th className="px-4 py-3 font-medium">Statut</th>
                            <th className="px-4 py-3 font-medium">Commandé le</th>
                            <th className="px-4 py-3 font-medium">Réception prévue</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {procurements.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 font-medium text-slate-900">{row.procurement_number}</td>
                                <td className="px-4 py-3">
                                    {row.sales_order ? (
                                        <Link href={`/sales/orders/${row.sales_order.id}`} className="text-slate-900 underline">
                                            {row.sales_order.order_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-4 py-3 text-slate-600">{row.supplier?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-600">{row.product || '—'}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatQuantity(row.quantity)}</td>
                                <td className="px-4 py-3">
                                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusClass(row.status)}`}>
                                        {row.status_label ?? STATUS_LABEL[row.status] ?? row.status}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-slate-600">{row.ordered_at ? formatDate(row.ordered_at) : '—'}</td>
                                <td className="px-4 py-3 text-slate-600">
                                    {row.received_at
                                        ? `Reçu ${formatDate(row.received_at)}`
                                        : row.expected_at
                                          ? formatDate(row.expected_at)
                                          : '—'}
                                </td>
                            </tr>
                        ))}
                        {procurements.data.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-10 text-center text-slate-500">
                                    Aucun approvisionnement fournisseur.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination links={procurements.links} />
        </ProcurementLayout>
    );
}
