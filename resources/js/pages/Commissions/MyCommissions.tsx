import CommissionFilters, { STATUS_LABELS, TYPE_LABELS } from '@/components/finance/CommissionFilters';
import type { CommissionFilterState } from '@/components/finance/CommissionFilters';
import CommissionMetric from '@/components/finance/CommissionMetric';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatMoney } from '@/utils/format';
import { Head } from '@inertiajs/react';

type Entry = {
    id: number; occurred_at: string; sale_date: string; entry_type: string; status: string; product_name_snapshot: string | null;
    commission_rate_snapshot: string; commission_amount: string; reversal_reason: string | null; approved_at: string | null; paid_at: string | null;
    margin_amount_snapshot?: string; margin_rate_snapshot?: string;
    sales_order: { id: number; order_number: string } | null; customer_return: { id: number; return_number: string } | null;
};
type Props = {
    filters: CommissionFilterState;
    summary: { gross: string; reversals: string; return_reversals: string; cancellations: string; corrections: string; net: string; pending: string; approved: string; paid: string; entry_count: number; order_count: number };
    entries: { data: Entry[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    can: { margin: boolean };
};

/** Salesperson self-service: own entries only; no purchase cost is ever shown here. */
export default function MyCommissions({ filters, summary, entries, can }: Props) {
    return (
        <ApplicationShell wide>
            <Head title="Mes commissions" />
            <PageHeader title="Mes commissions" description="Vos commissions, reprises éventuelles et leur statut de règlement." />
            <CommissionFilters path="/my-commissions" filters={filters} />

            <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <CommissionMetric label="Commission brute" value={formatMoney(summary.gross)} hint={`${summary.order_count} commande(s)`} />
                <CommissionMetric label="Retours / annulations" value={formatMoney(summary.reversals)} tone={Number(summary.reversals) < 0 ? 'danger' : undefined} />
                <CommissionMetric label="Commission nette" value={formatMoney(summary.net)} strong />
                <CommissionMetric label="En attente" value={formatMoney(summary.pending)} />
                <CommissionMetric label="Approuvée" value={formatMoney(summary.approved)} />
                <CommissionMetric label="Payée" value={formatMoney(summary.paid)} />
            </section>

            <section className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full min-w-[820px] text-left text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-3 py-2">Date</th><th className="px-3 py-2">Commande</th><th className="px-3 py-2">Produit</th>
                            {can.margin && <th className="px-3 py-2 text-right">Marge</th>}
                            <th className="px-3 py-2 text-right">Taux</th><th className="px-3 py-2 text-right">Commission</th><th className="px-3 py-2">Type</th><th className="px-3 py-2">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.data.map((entry) => (
                            <tr key={entry.id} className="border-t border-line align-top">
                                <td className="px-3 py-2 text-ink-muted">{formatDate(entry.occurred_at)}</td>
                                <td className="px-3 py-2">{entry.sales_order?.order_number ?? '—'}{entry.customer_return && <span className="block text-[11px] text-ink-faint">{entry.customer_return.return_number}</span>}</td>
                                <td className="px-3 py-2">{entry.product_name_snapshot ?? '—'}{entry.reversal_reason && <span className="block text-[11px] text-ink-faint">{entry.reversal_reason}</span>}</td>
                                {can.margin && <td className="px-3 py-2 text-right">{formatMoney(entry.margin_amount_snapshot)}</td>}
                                <td className="px-3 py-2 text-right">{Number(entry.commission_rate_snapshot).toLocaleString('fr-FR')} %</td>
                                <td className={`px-3 py-2 text-right font-semibold ${Number(entry.commission_amount) < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(entry.commission_amount)}</td>
                                <td className="px-3 py-2">{TYPE_LABELS[entry.entry_type] ?? entry.entry_type}</td>
                                <td className="px-3 py-2">
                                    {STATUS_LABELS[entry.status] ?? entry.status}
                                    {entry.paid_at && <span className="block text-[11px] text-ink-faint">le {formatDate(entry.paid_at)}</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {entries.data.length === 0 && <p className="p-8 text-center text-sm text-ink-faint">Aucune commission sur cette période.</p>}
            </section>
            <Pagination links={entries.links} />
        </ApplicationShell>
    );
}
