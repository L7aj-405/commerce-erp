import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDate, formatQuantity } from '@/utils/format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Warehouse = { id: number; name: string; code: string };
type Row = {
    id: number;
    request_number: string;
    status: string;
    source: Warehouse | null;
    destination: Warehouse | null;
    sales_order: { id: number; order_number: string } | null;
    reasons: string[];
    unit_count: number;
    created_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    requests: { data: Row[]; links: LinkData[] };
    filters: { search?: string; status?: string };
    warehouses: Warehouse[];
    can: { manage: boolean; receive: boolean };
};

const STATUS_LABEL: Record<string, string> = {
    requested: 'Demandée',
    preparing: 'En préparation',
    shipped: 'Expédiée',
    received: 'Réceptionnée',
    cancelled: 'Annulée',
};
const REASON_LABEL: Record<string, string> = {
    order_fulfillment: 'Commande',
    minimum_replenishment: 'Réassort',
    manual: 'Manuel',
};
const statusClass = (s: string) =>
    s === 'received'
        ? 'bg-emerald-50 text-emerald-700'
        : s === 'cancelled'
          ? 'bg-slate-100 text-slate-500'
          : s === 'shipped'
            ? 'bg-indigo-50 text-indigo-700'
            : 'bg-amber-50 text-amber-700';

export default function TransferRequestIndex({ requests, filters, warehouses, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const replenish = useForm({ warehouse_id: warehouses[0]?.id ?? 0 });

    const visit = (next: Record<string, string | undefined>) =>
        router.get('/inventory/transfer-requests', next, {
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
        <InventoryLayout>
            <Head title="Demandes de transfert" />
            <PageHeader
                title="Demandes de transfert"
                description="Instructions logistiques internes : demandée → en préparation → expédiée → réceptionnée. Le mouvement de stock n’a lieu qu’à la réception."
            />

            <div className="mb-6 flex flex-wrap items-center gap-2">
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="N°, commande, entrepôt" />
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => visit({ search: filters.search, status: e.target.value || undefined, page: undefined })}
                    className="rounded-lg border px-3 py-2 text-sm"
                >
                    <option value="">Tous statuts</option>
                    {Object.entries(STATUS_LABEL).map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </select>
                {can.manage && warehouses.length > 0 && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            replenish.post('/inventory/transfer-requests/replenish', { preserveScroll: true });
                        }}
                        className="ml-auto flex items-center gap-2"
                    >
                        <select
                            value={replenish.data.warehouse_id}
                            onChange={(e) => replenish.setData('warehouse_id', Number(e.target.value))}
                            className="rounded-lg border px-3 py-2 text-sm"
                        >
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                        <button className="rounded-lg border px-3 py-2 text-sm font-medium hover:bg-slate-50" disabled={replenish.processing}>
                            Évaluer le réassort
                        </button>
                    </form>
                )}
            </div>

            <div className="overflow-hidden rounded-2xl border bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="p-3">N°</th>
                            <th className="p-3">De</th>
                            <th className="p-3">Vers</th>
                            <th className="p-3">Motif</th>
                            <th className="p-3">Commande</th>
                            <th className="p-3 text-right">Unités</th>
                            <th className="p-3">Statut</th>
                            <th className="p-3">Créée le</th>
                        </tr>
                    </thead>
                    <tbody>
                        {requests.data.map((r) => (
                            <tr key={r.id} className="border-t">
                                <td className="p-3 font-semibold">
                                    <Link href={`/inventory/transfer-requests/${r.id}`} className="hover:underline">
                                        {r.request_number}
                                    </Link>
                                </td>
                                <td className="p-3">{r.source?.name ?? '—'}</td>
                                <td className="p-3">{r.destination?.name ?? '—'}</td>
                                <td className="p-3">{r.reasons.map((x) => REASON_LABEL[x] ?? x).join(' + ')}</td>
                                <td className="p-3">
                                    {r.sales_order ? (
                                        <Link href={`/sales/orders/${r.sales_order.id}`} className="hover:underline">
                                            {r.sales_order.order_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="p-3 text-right tabular-nums">{formatQuantity(r.unit_count)}</td>
                                <td className="p-3">
                                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusClass(r.status)}`}>
                                        {STATUS_LABEL[r.status] ?? r.status}
                                    </span>
                                </td>
                                <td className="p-3 text-slate-500">{r.created_at ? formatDate(r.created_at) : '—'}</td>
                            </tr>
                        ))}
                        {requests.data.length === 0 && (
                            <tr>
                                <td colSpan={8} className="p-8 text-center text-slate-500">
                                    Aucune demande de transfert.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination links={requests.links} />
        </InventoryLayout>
    );
}
