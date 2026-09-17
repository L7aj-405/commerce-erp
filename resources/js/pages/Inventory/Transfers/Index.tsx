import { ButtonLink } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import InventoryLayout from '@/layouts/InventoryLayout';
import { formatDate, formatInteger, formatQuantity } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Transfer = {
    id: number;
    transfer_number: string;
    transferred_at: string;
    product_count: number;
    unit_count: string;
    status: string;
    source_warehouse: { name: string; code: string };
    destination_warehouse: { name: string; code: string };
    performed_by: { name: string } | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    transfers: { data: Transfer[]; links: LinkData[] };
    filters: { search?: string };
    can: { create: boolean };
};

export default function TransferIndex({ transfers, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [searching, setSearching] = useState(false);
    const visit = (next: Record<string, string | undefined>) => router.get('/inventory/transfers', next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setSearching(true),
        onFinish: () => setSearching(false),
    });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search: search || undefined, page: undefined as unknown as string }), 280);

        return () => window.clearTimeout(timer);
    }, [search]);

    return (
        <InventoryLayout>
            <Head title="Transferts" />
            <PageHeader
                title="Transferts"
                description="Deplacez du stock entre vos emplacements."
                actions={can.create ? <ButtonLink href="/inventory/transfers/create">+ Nouveau transfert</ButtonLink> : undefined}
            />

            <div className="mb-6 flex flex-wrap gap-2">
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Reference, source ou destination" />
            </div>

            {transfers.data.length === 0 ? (
                <EmptyState
                    title="Aucun transfert enregistre"
                    description="Les transferts apparaitront ici des que vous commencerez a deplacer du stock entre vos emplacements."
                    actions={can.create ? <ButtonLink href="/inventory/transfers/create">Creer le premier transfert</ButtonLink> : undefined}
                />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-2xl border bg-white md:block">
                        <table className="w-full min-w-[820px] text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="p-3">Reference</th>
                                    <th className="p-3">Date</th>
                                    <th className="p-3">Trajet</th>
                                    <th className="p-3 text-right">Produits</th>
                                    <th className="p-3 text-right">Unites</th>
                                    <th className="p-3">Effectue par</th>
                                    <th className="p-3">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {transfers.data.map(transfer => (
                                    <tr key={transfer.id} className="border-t">
                                        <td className="p-3 font-semibold"><Link href={`/inventory/transfers/${transfer.id}`} className="hover:underline">{transfer.transfer_number}</Link></td>
                                        <td className="p-3">{formatDate(transfer.transferred_at)}</td>
                                        <td className="p-3">{transfer.source_warehouse.name} → {transfer.destination_warehouse.name}</td>
                                        <td className="p-3 text-right">{formatInteger(transfer.product_count)}</td>
                                        <td className="p-3 text-right">{formatQuantity(transfer.unit_count)}</td>
                                        <td className="p-3">{transfer.performed_by?.name ?? 'Systeme'}</td>
                                        <td className="p-3"><span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">Effectue</span></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {transfers.data.map(transfer => (
                            <li key={transfer.id} className="rounded-2xl border bg-white p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/inventory/transfers/${transfer.id}`} className="block truncate text-sm font-semibold hover:underline">
                                            {transfer.transfer_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-slate-500">
                                            {transfer.source_warehouse.name} → {transfer.destination_warehouse.name}
                                        </p>
                                    </div>
                                    <span className="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">Effectue</span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Date</dt>
                                        <dd>{formatDate(transfer.transferred_at)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Effectue par</dt>
                                        <dd className="truncate">{transfer.performed_by?.name ?? 'Systeme'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Produits</dt>
                                        <dd>{formatInteger(transfer.product_count)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-slate-400">Unites</dt>
                                        <dd className="tabular-nums">{formatQuantity(transfer.unit_count)}</dd>
                                    </div>
                                </dl>
                                <Link
                                    href={`/inventory/transfers/${transfer.id}`}
                                    className="mt-3 inline-flex min-h-9 items-center justify-center rounded-lg border px-3 text-[13px] font-medium hover:bg-slate-50"
                                >
                                    Voir
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <Pagination links={transfers.links} />
                </>
            )}
        </InventoryLayout>
    );
}
