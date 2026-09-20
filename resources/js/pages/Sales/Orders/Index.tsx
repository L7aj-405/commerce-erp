import DocBadge from '@/components/ui/DocBadge';
import FilterSelect from '@/components/ui/FilterSelect';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import SearchInput from '@/components/ui/SearchInput';
import { useDebouncedValue } from '@/components/pos/useDebouncedValue';
import { Popover } from '@/components/pos/primitives';
import SalesLayout from '@/layouts/SalesLayout';
import { formatDate, formatMoney } from '@/utils/format';
import {
    fulfillmentLabel,
    fulfillmentTone,
    label,
    orderStatusLabel,
    orderStatusTone,
    paymentStatusLabel,
    paymentStatusTone,
    sourceLabel,
} from '@/utils/labels';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Order = {
    id: number;
    order_number: string;
    sale_date: string;
    customer_name: string | null;
    customer_company: string | null;
    status: string;
    fulfillment_status: string;
    payment_status: string;
    total_incl_tax: string;
    source: string;
    currency_code: string;
    has_active_invoice: boolean;
    returned_total: string;
    net_total: string;
    return_state: 'none' | 'partial' | 'full';
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    orders: { data: Order[]; links: PageLink[] };
    filters: { search?: string; status?: string; fulfillment_status?: string; payment_status?: string; sale_date?: string };
    summary: { total: number; a_preparer: number; partiellement_payees: number; payees: number };
};

export default function OrderIndex({ orders, filters, summary }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [menuFor, setMenuFor] = useState<number | null>(null);
    const debounced = useDebouncedValue(search, 250);
    const mounted = useRef(false);

    const apply = (patch: Record<string, string | undefined>) =>
        router.get('/sales/orders', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;
            return;
        }
        apply({ search: debounced || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debounced]);

    const searching = search !== (filters.search ?? '');

    const tiles = [
        { label: 'Total commandes', value: summary.total },
        { label: 'À préparer', value: summary.a_preparer },
        { label: 'Partiellement payées', value: summary.partiellement_payees },
        { label: 'Payées', value: summary.payees },
    ];

    return (
        <SalesLayout>
            <Head title="Commandes" />
            <PageHeader title="Commandes" description="Les commandes proviennent du point de vente." />

            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="rounded-card border border-line bg-surface px-4 py-3">
                        <p className="text-xs text-ink-muted">{tile.label}</p>
                        <p className="mt-1 text-xl font-semibold text-ink">{tile.value}</p>
                    </div>
                ))}
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchInput
                    value={search}
                    onChange={setSearch}
                    searching={searching}
                    placeholder="N° commande, client, téléphone…"
                />
                <FilterSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
                    <option value="">Tous statuts</option>
                    <option value="draft">Brouillon</option>
                    <option value="confirmed">Confirmée</option>
                    <option value="cancelled">Annulée</option>
                </FilterSelect>
                <FilterSelect
                    value={filters.fulfillment_status ?? ''}
                    onChange={(e) => apply({ fulfillment_status: e.target.value || undefined })}
                >
                    <option value="">Préparation</option>
                    <option value="unfulfilled">À préparer</option>
                    <option value="partially_fulfilled">Préparation partielle</option>
                    <option value="fulfilled">Livrée</option>
                </FilterSelect>
                <FilterSelect
                    value={filters.payment_status ?? ''}
                    onChange={(e) => apply({ payment_status: e.target.value || undefined })}
                >
                    <option value="">Paiement</option>
                    <option value="unpaid">Non payée</option>
                    <option value="partially_paid">Partiellement payée</option>
                    <option value="paid">Payée</option>
                </FilterSelect>
                <input
                    type="date"
                    value={filters.sale_date ?? ''}
                    onChange={(e) => apply({ sale_date: e.target.value || undefined })}
                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
            </div>

            {orders.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-10 text-center text-sm text-ink-muted">
                    Aucune commande ne correspond à ces critères.
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full min-w-[880px] text-left text-sm">
                            <thead className="border-b border-line bg-raised text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Commande</th>
                                    <th className="px-4 py-3 font-medium">Date</th>
                                    <th className="px-4 py-3 font-medium">Client</th>
                                    <th className="px-4 py-3 font-medium">Statut</th>
                                    <th className="px-4 py-3 font-medium">Préparation / Livraison</th>
                                    <th className="px-4 py-3 font-medium">Paiement</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                    <th className="px-4 py-3 font-medium">Source</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {orders.data.map((order) => (
                                    <tr
                                        key={order.id}
                                        onClick={() => router.visit(`/sales/orders/${order.id}`)}
                                        className="cursor-pointer transition-soft hover:bg-sage/50"
                                    >
                                        <td className="px-4 py-3 font-medium text-ink">{order.order_number}</td>
                                        <td className="px-4 py-3 text-ink-muted">{formatDate(order.sale_date)}</td>
                                        <td className="px-4 py-3">
                                            <div className="text-ink">{order.customer_name ?? 'Client comptoir'}</div>
                                            {order.customer_company && (
                                                <div className="text-xs text-ink-muted">{order.customer_company}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                <DocBadge tone={orderStatusTone(order.status)}>{label(orderStatusLabel, order.status)}</DocBadge>
                                                {order.return_state === 'partial' && <DocBadge tone="warning">Retour partiel</DocBadge>}
                                                {order.return_state === 'full' && <DocBadge tone="danger">Retournée intégralement</DocBadge>}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <DocBadge tone={fulfillmentTone(order.fulfillment_status)}>
                                                {label(fulfillmentLabel, order.fulfillment_status)}
                                            </DocBadge>
                                        </td>
                                        <td className="px-4 py-3">
                                            <DocBadge tone={paymentStatusTone(order.payment_status)}>
                                                {label(paymentStatusLabel, order.payment_status)}
                                            </DocBadge>
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium tabular-nums text-ink">
                                            <span className={order.return_state === 'none' ? '' : 'text-ink-muted line-through'}>{formatMoney(order.total_incl_tax, order.currency_code)}</span>
                                            {order.return_state !== 'none' && <span className="block text-xs font-semibold text-ink">Net {formatMoney(order.net_total, order.currency_code)}</span>}
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">{label(sourceLabel, order.source)}</td>
                                        <td className="relative px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                            <button
                                                type="button"
                                                aria-label="Actions"
                                                onClick={() => setMenuFor(menuFor === order.id ? null : order.id)}
                                                className="flex size-9 items-center justify-center rounded-field text-ink-faint transition-soft hover:bg-sage hover:text-ink"
                                            >
                                                ⋯
                                            </button>
                                            <Popover open={menuFor === order.id} onClose={() => setMenuFor(null)}>
                                                <div className="flex flex-col text-sm">
                                                    <Link
                                                        href={`/sales/orders/${order.id}`}
                                                        className="rounded-field px-2 py-1.5 text-left hover:bg-sage"
                                                    >
                                                        Voir
                                                    </Link>
                                                    {order.status === 'confirmed' && !order.has_active_invoice && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.post(`/sales/orders/${order.id}/invoices`, {}, { onFinish: () => setMenuFor(null) })
                                                            }
                                                            className="rounded-field px-2 py-1.5 text-left hover:bg-sage"
                                                        >
                                                            Créer facture
                                                        </button>
                                                    )}
                                                </div>
                                            </Popover>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards */}
                    <ul className="space-y-3 md:hidden">
                        {orders.data.map((order) => (
                            <li key={order.id} className="rounded-card border border-line bg-surface p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <Link href={`/sales/orders/${order.id}`} className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-ink">{order.order_number}</p>
                                        <p className="text-[13px] text-ink-muted">{formatDate(order.sale_date)}</p>
                                    </Link>
                                    <div className="relative shrink-0">
                                        <button
                                            type="button"
                                            aria-label="Actions"
                                            onClick={() => setMenuFor(menuFor === order.id ? null : order.id)}
                                            className="flex size-9 items-center justify-center rounded-field text-ink-faint transition-soft hover:bg-sage hover:text-ink"
                                        >
                                            ⋯
                                        </button>
                                        <Popover open={menuFor === order.id} onClose={() => setMenuFor(null)}>
                                            <div className="flex flex-col text-sm">
                                                <Link
                                                    href={`/sales/orders/${order.id}`}
                                                    className="rounded-field px-2 py-1.5 text-left hover:bg-sage"
                                                >
                                                    Voir
                                                </Link>
                                                {order.status === 'confirmed' && !order.has_active_invoice && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(`/sales/orders/${order.id}/invoices`, {}, { onFinish: () => setMenuFor(null) })
                                                        }
                                                        className="rounded-field px-2 py-1.5 text-left hover:bg-sage"
                                                    >
                                                        Créer facture
                                                    </button>
                                                )}
                                            </div>
                                        </Popover>
                                    </div>
                                </div>

                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    <DocBadge tone={orderStatusTone(order.status)}>{label(orderStatusLabel, order.status)}</DocBadge>
                                    {order.return_state === 'partial' && <DocBadge tone="warning">Retour partiel</DocBadge>}
                                    {order.return_state === 'full' && <DocBadge tone="danger">Retournée intégralement</DocBadge>}
                                    <DocBadge tone={fulfillmentTone(order.fulfillment_status)}>{label(fulfillmentLabel, order.fulfillment_status)}</DocBadge>
                                    <DocBadge tone={paymentStatusTone(order.payment_status)}>{label(paymentStatusLabel, order.payment_status)}</DocBadge>
                                </div>

                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Client</dt>
                                        <dd className="truncate text-ink">{order.customer_name ?? 'Client comptoir'}</dd>
                                        {order.customer_company && <dd className="truncate text-ink-muted">{order.customer_company}</dd>}
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Source</dt>
                                        <dd className="truncate text-ink-muted">{label(sourceLabel, order.source)}</dd>
                                    </div>
                                </dl>

                                <p className="mt-2 text-base font-semibold tabular-nums text-ink">
                                    {formatMoney(order.return_state === 'none' ? order.total_incl_tax : order.net_total, order.currency_code)}
                                </p>
                                {order.return_state !== 'none' && <p className="text-xs text-ink-muted">Vente initiale {formatMoney(order.total_incl_tax, order.currency_code)} · Retours -{formatMoney(order.returned_total, order.currency_code)}</p>}
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={orders.links} />
        </SalesLayout>
    );
}
