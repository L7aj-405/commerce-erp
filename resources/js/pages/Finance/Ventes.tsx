import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type Order = {
    id: number;
    order_number: string;
    sale_date: string;
    customer: string;
    total_incl_tax: string;
    payment_status: string;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    organization: { id: number; name: string };
    period: string;
    periodLabel: string;
    storeId: number | null;
    stores: FinanceStore[];
    orders: { data: Order[]; links: LinkData[]; total: number };
};

const STATUS_LABELS: Record<string, string> = { paid: 'Payée', partially_paid: 'Partiellement payée', unpaid: 'Impayée' };

export default function FinanceVentes({ organization, period, periodLabel, storeId, stores, orders }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Ventes — Finance" />
            <PageHeader title="Ventes" description={`${organization.name} · ${periodLabel} · ${orders.total} commande(s) confirmée(s)`} />
            <FinanceFilters path="/finance/ventes" period={period} storeId={storeId} stores={stores} />

            <div className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full text-left text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-4 py-2.5">N° commande</th>
                            <th className="px-4 py-2.5">Date de vente</th>
                            <th className="px-4 py-2.5">Client</th>
                            <th className="px-4 py-2.5 text-right">Total TTC</th>
                            <th className="px-4 py-2.5">Statut paiement</th>
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
                                <td className="px-4 py-2.5 text-ink-muted">{STATUS_LABELS[order.payment_status] ?? order.payment_status}</td>
                            </tr>
                        ))}
                        {orders.data.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-ink-faint">
                                    Aucune commande confirmée sur cette période.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination links={orders.links} />
        </ApplicationShell>
    );
}
