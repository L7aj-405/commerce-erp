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
                title="Transferts de stock"
                description="Consultez les mouvements effectués entre vos emplacements et créez un nouveau transfert."
                actions={can.create ? <ButtonLink href="/inventory/transfers/create"><IconPlus /> Nouveau transfert</ButtonLink> : undefined}
            />

            <section className="mb-5 rounded-card border border-line bg-surface p-4 shadow-soft">
                <label className="mb-2 block text-[11px] font-semibold uppercase tracking-wide text-ink-faint">Rechercher un transfert</label>
                <SearchInput value={search} onChange={setSearch} searching={searching} placeholder="Référence, emplacement source ou destination" />
            </section>

            {transfers.data.length === 0 ? (
                <EmptyState
                    title="Aucun transfert enregistré"
                    description="Les transferts apparaîtront ici dès que vous commencerez à déplacer du stock entre vos emplacements."
                    actions={can.create ? <ButtonLink href="/inventory/transfers/create">Créer le premier transfert</ButtonLink> : undefined}
                />
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-hidden rounded-card border border-line bg-surface shadow-soft md:block">
                        <div className="flex items-center justify-between border-b border-line px-4 py-3">
                            <div>
                                <h2 className="text-sm font-semibold text-ink">Historique des transferts</h2>
                                <p className="mt-0.5 text-xs text-ink-muted">Chaque transfert validé correspond à un mouvement réel de stock.</p>
                            </div>
                            <span className="rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-ink-muted">{formatInteger(transfers.data.length)} affiché(s)</span>
                        </div>
                        <div className="overflow-x-auto">
                        <table className="w-full min-w-[820px] text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-3">Référence</th>
                                    <th className="px-4 py-3">Date</th>
                                    <th className="px-4 py-3">Trajet</th>
                                    <th className="px-4 py-3 text-right">Produits</th>
                                    <th className="px-4 py-3 text-right">Unités</th>
                                    <th className="px-4 py-3">Effectué par</th>
                                    <th className="px-4 py-3">Statut</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {transfers.data.map(transfer => (
                                    <tr key={transfer.id} className="transition-soft hover:bg-raised/70">
                                        <td className="px-4 py-3 font-semibold"><Link href={`/inventory/transfers/${transfer.id}`} className="text-ink hover:underline">{transfer.transfer_number}</Link></td>
                                        <td className="px-4 py-3 text-ink-muted">{formatDate(transfer.transferred_at)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2 font-medium text-ink">
                                                <span>{transfer.source_warehouse.name}</span><IconArrow /><span>{transfer.destination_warehouse.name}</span>
                                            </div>
                                            <p className="mt-0.5 text-xs text-ink-faint">{transfer.source_warehouse.code} → {transfer.destination_warehouse.code}</p>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-ink-muted">{formatInteger(transfer.product_count)}</td>
                                        <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink">{formatQuantity(transfer.unit_count)}</td>
                                        <td className="px-4 py-3 text-ink-muted">{transfer.performed_by?.name ?? 'Système'}</td>
                                        <td className="px-4 py-3"><span className="rounded-full bg-success-soft px-2.5 py-1 text-xs font-semibold text-success">Effectué</span></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {transfers.data.map(transfer => (
                            <li key={transfer.id} className="rounded-card border border-line bg-surface p-4 shadow-soft">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link href={`/inventory/transfers/${transfer.id}`} className="block truncate text-sm font-semibold hover:underline">
                                            {transfer.transfer_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">
                                            {transfer.source_warehouse.name} → {transfer.destination_warehouse.name}
                                        </p>
                                    </div>
                                    <span className="shrink-0 rounded-full bg-success-soft px-2.5 py-1 text-xs font-semibold text-success">Effectué</span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date</dt>
                                        <dd>{formatDate(transfer.transferred_at)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Effectué par</dt>
                                        <dd className="truncate">{transfer.performed_by?.name ?? 'Système'}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Produits</dt>
                                        <dd>{formatInteger(transfer.product_count)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Unités</dt>
                                        <dd className="tabular-nums">{formatQuantity(transfer.unit_count)}</dd>
                                    </div>
                                </dl>
                                <Link
                                    href={`/inventory/transfers/${transfer.id}`}
                                    className="mt-4 inline-flex min-h-9 items-center justify-center rounded-field border border-line-strong px-3 text-[13px] font-medium text-ink transition-soft hover:bg-sage"
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

function IconPlus() { return <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 5v14M5 12h14" /></svg>; }
function IconArrow() { return <svg className="shrink-0 text-ink-faint" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14m-5-5 5 5-5 5" /></svg>; }
