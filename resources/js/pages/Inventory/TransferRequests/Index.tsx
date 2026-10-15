import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
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
        ? 'bg-success-soft text-success'
        : s === 'cancelled'
          ? 'bg-raised text-ink-muted'
          : s === 'shipped'
            ? 'bg-sage text-primary'
            : 'bg-warning-soft text-warning';

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
                description="Pilotez les demandes internes, de la préparation à la réception. Le stock ne bouge réellement qu’à la réception."
            />

            <section className="mb-5 rounded-card border border-line bg-surface p-4 shadow-soft">
                <div className="grid gap-3 lg:grid-cols-[minmax(280px,1fr)_220px]">
                    <div className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">Recherche</span>
                        <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="N°, commande ou entrepôt" />
                    </div>
                    <label className="block">
                        <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">Statut</span>
                        <select
                            value={filters.status ?? ''}
                            onChange={(e) => visit({ search: search || undefined, status: e.target.value || undefined, page: undefined })}
                            className="h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none focus:border-primary"
                        >
                            <option value="">Tous les statuts</option>
                            {Object.entries(STATUS_LABEL).map(([v, l]) => (
                                <option key={v} value={v}>{l}</option>
                            ))}
                        </select>
                    </label>
                </div>
                {can.manage && warehouses.length > 0 && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            replenish.post('/inventory/transfer-requests/replenish', { preserveScroll: true });
                        }}
                        className="mt-4 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-end"
                    >
                        <label className="min-w-0 flex-1 sm:max-w-sm">
                            <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">Entrepôt à évaluer</span>
                            <select
                                value={replenish.data.warehouse_id}
                                onChange={(e) => replenish.setData('warehouse_id', Number(e.target.value))}
                                className="h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none focus:border-primary"
                            >
                                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name} · {w.code}</option>)}
                            </select>
                        </label>
                        <Button type="submit" variant="secondary" loading={replenish.processing} loadingText="Évaluation…">
                            Évaluer le réassort
                        </Button>
                    </form>
                )}
            </section>

            {requests.data.length === 0 ? (
                <EmptyState title="Aucune demande de transfert" description="Les demandes de réassort ou de préparation apparaîtront ici dès qu’elles seront créées." />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-hidden rounded-card border border-line bg-surface shadow-soft md:block">
                        <div className="flex items-center justify-between border-b border-line px-4 py-3">
                            <div>
                                <h2 className="text-sm font-semibold text-ink">Suivi logistique</h2>
                                <p className="mt-0.5 text-xs text-ink-muted">Ouvrez une demande pour consulter les articles et effectuer l’étape suivante.</p>
                            </div>
                            <span className="rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">{requests.data.length} affichée(s)</span>
                        </div>
                        <div className="overflow-x-auto">
                        <table className="w-full min-w-[880px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-3">N°</th>
                                    <th className="px-4 py-3">Trajet</th>
                                    <th className="px-4 py-3">Motif</th>
                                    <th className="px-4 py-3">Commande</th>
                                    <th className="px-4 py-3 text-right">Unités</th>
                                    <th className="px-4 py-3">Statut</th>
                                    <th className="px-4 py-3">Créée le</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {requests.data.map((r) => (
                                    <tr key={r.id} className="transition-soft hover:bg-raised/70">
                                        <td className="px-4 py-3 font-semibold">
                                            <Link href={`/inventory/transfer-requests/${r.id}`} className="text-ink hover:underline">
                                                {r.request_number}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-ink">{r.source?.name ?? '—'} → {r.destination?.name ?? '—'}</p>
                                            <p className="mt-0.5 text-xs text-ink-faint">{r.source?.code ?? '—'} → {r.destination?.code ?? '—'}</p>
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{r.reasons.map((x) => REASON_LABEL[x] ?? x).join(' + ')}</td>
                                        <td className="px-4 py-3">
                                            {r.sales_order ? (
                                                <Link href={`/sales/orders/${r.sales_order.id}`} className="hover:underline">
                                                    {r.sales_order.order_number}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink">{formatQuantity(r.unit_count)}</td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(r.status)}`}>
                                                {STATUS_LABEL[r.status] ?? r.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{r.created_at ? formatDate(r.created_at) : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {requests.data.map((r) => (
                            <li key={r.id} className="rounded-card border border-line bg-surface p-4 shadow-soft">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/inventory/transfer-requests/${r.id}`} className="block truncate text-sm font-semibold hover:underline">
                                            {r.request_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">
                                            {r.source?.name ?? '—'} → {r.destination?.name ?? '—'}
                                        </p>
                                    </div>
                                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${statusClass(r.status)}`}>
                                        {STATUS_LABEL[r.status] ?? r.status}
                                    </span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Motif</dt>
                                        <dd className="truncate">{r.reasons.map((x) => REASON_LABEL[x] ?? x).join(' + ') || '—'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Commande</dt>
                                        <dd className="truncate">
                                            {r.sales_order ? (
                                                <Link href={`/sales/orders/${r.sales_order.id}`} className="hover:underline">
                                                    {r.sales_order.order_number}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Unités</dt>
                                        <dd className="tabular-nums">{formatQuantity(r.unit_count)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Créée le</dt>
                                        <dd className="text-ink-muted">{r.created_at ? formatDate(r.created_at) : '—'}</dd>
                                    </div>
                                </dl>
                                <Link
                                    href={`/inventory/transfer-requests/${r.id}`}
                                    className="mt-4 inline-flex min-h-9 items-center justify-center rounded-field border border-line-strong px-3 text-[13px] font-medium text-ink transition-soft hover:bg-sage"
                                >
                                    Voir
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={requests.links} />
        </InventoryLayout>
    );
}
