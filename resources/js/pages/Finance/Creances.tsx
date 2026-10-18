import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type OrderRow = {
    id: number;
    order_number: string;
    sale_date: string;
    customer: string;
    total_incl_tax: string;
    paid_amount: string;
    outstanding: string;
    payment_status: 'unpaid' | 'partially_paid';
    last_payment_date: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    asOf: string;
    storeId: number | null;
    stores: FinanceStore[];
    total: string;
    orders: { data: OrderRow[]; links: LinkData[]; total: number };
};

const STATUS_LABELS: Record<string, string> = { partially_paid: 'Partiellement payée', unpaid: 'Non payée' };
const STATUS_TONE: Record<string, string> = {
    partially_paid: 'bg-warning-soft text-warning',
    unpaid: 'bg-danger-soft text-danger',
};

export default function FinanceCreances({ organization, period, periodLabel, asOf, storeId, stores, total, orders }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Créances — Finance" />
            <PageHeader
                title="Créances"
                description={`${organization.name} · Solde au ${asOf} (fin ${periodLabel}) · Total : ${formatMoney(total)}`}
            />
            <FinanceFilters path="/finance/creances" period={period} storeId={storeId} stores={stores} />

            {orders.data.length === 0 ? (
                <div className="rounded-card border border-dashed border-line-strong bg-raised px-4 py-8 text-center text-sm text-ink-faint">
                    Aucune créance ouverte à cette date.
                </div>
            ) : (
                <>
                    {/* Desktop/tablet: table */}
                    <div className="hidden overflow-x-auto rounded-card border border-line bg-surface md:block">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="px-4 py-2.5">N° commande</th>
                                    <th className="px-4 py-2.5">Date de vente</th>
                                    <th className="px-4 py-2.5">Client</th>
                                    <th className="px-4 py-2.5 text-right">Total commande</th>
                                    <th className="px-4 py-2.5 text-right">Encaissé</th>
                                    <th className="px-4 py-2.5 text-right">Reste à payer</th>
                                    <th className="px-4 py-2.5">Statut paiement</th>
                                    <th className="px-4 py-2.5">Dernier paiement</th>
                                    <th className="px-4 py-2.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((order) => (
                                    <tr key={order.id} className="border-t border-line">
                                        <td className="px-4 py-2.5 font-medium text-ink">
                                            <Link href={`/sales/orders/${order.id}`}>{order.order_number}</Link>
                                        </td>
                                        <td className="px-4 py-2.5 text-ink-muted">{order.sale_date}</td>
                                        <td className="px-4 py-2.5">{order.customer}</td>
                                        <td className="px-4 py-2.5 text-right">{formatMoney(order.total_incl_tax)}</td>
                                        <td className="px-4 py-2.5 text-right text-ink-muted">{formatMoney(order.paid_amount)}</td>
                                        <td className="px-4 py-2.5 text-right font-medium">{formatMoney(order.outstanding)}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{STATUS_LABELS[order.payment_status]}</td>
                                        <td className="px-4 py-2.5 text-ink-muted">{order.last_payment_date ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-right"><Link href={`/sales/orders/${order.id}`} className="font-medium text-ink underline-offset-4 hover:underline">Ouvrir</Link></td>
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
                                    <div className="min-w-0">
                                        <Link href={`/sales/orders/${order.id}`} className="block truncate text-sm font-semibold text-ink">
                                            {order.order_number}
                                        </Link>
                                        <p className="truncate text-[13px] text-ink-muted">{order.customer}</p>
                                    </div>
                                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_TONE[order.payment_status] ?? 'bg-sage text-ink-muted'}`}>
                                        {STATUS_LABELS[order.payment_status]}
                                    </span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Date de vente</dt>
                                        <dd className="text-ink-muted">{order.sale_date}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Total commande</dt>
                                        <dd className="font-medium text-ink">{formatMoney(order.total_incl_tax)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Encaissé</dt>
                                        <dd className="text-ink-muted">{formatMoney(order.paid_amount)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Reste à payer</dt>
                                        <dd className="font-semibold text-ink">{formatMoney(order.outstanding)}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">Dernier paiement</dt>
                                        <dd className="text-ink-muted">{order.last_payment_date ?? '—'}</dd>
                                    </div>
                                </dl>
                                <Link href={`/sales/orders/${order.id}`} className="mt-3 inline-flex text-sm font-medium text-ink underline-offset-4 hover:underline">Ouvrir</Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <Pagination links={orders.links} />
        </ApplicationShell>
    );
}
