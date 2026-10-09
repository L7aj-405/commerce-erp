import CommissionFilters, { filterQuery, STATUS_LABELS, TYPE_LABELS } from '@/components/finance/CommissionFilters';
import type { CommissionFilterState } from '@/components/finance/CommissionFilters';
import CommissionMetric from '@/components/finance/CommissionMetric';
import CommissionTabs from '@/components/finance/CommissionTabs';
import PageHeader from '@/components/ui/PageHeader';
import Pagination from '@/components/ui/Pagination';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';

type Entry = {
    id: number; occurred_at: string; sale_date: string; entry_type: string; status: string;
    product_name_snapshot: string | null; line_reference_snapshot: string | null; revenue_ht_snapshot: string;
    margin_amount_snapshot?: string; margin_rate_snapshot?: string; commission_rate_snapshot: string; commission_amount: string;
    commission_rule_set_name_snapshot: string; reversal_reason: string | null; paid_at: string | null;
    sales_order: { id: number; order_number: string } | null; customer_return: { id: number; return_number: string } | null;
};
type Props = {
    organization: { id: number; name: string };
    salesperson: { key: string; name: string | null; is_unattributed: boolean };
    filters: CommissionFilterState;
    stores: { id: number; name: string; code: string }[];
    kpis: { gross: string; reversals: string; net: string; pending: string; approved: string; paid: string; revenue: string; margin?: string; entry_count: number; order_count: number };
    entries: { data: Entry[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    can: { costs: boolean };
};

export default function CommissionSalesperson({ organization, salesperson, filters, stores, kpis, entries, can }: Props) {
    const back = `/finance/commissions?${filterQuery({ ...filters, salesperson: null })}`;

    return (
        <ApplicationShell wide>
            <Head title={`Commissions · ${salesperson.name ?? 'Commercial'}`} />
            <PageHeader
                title={salesperson.name ?? 'Commercial'}
                description={`${organization.name} · Détail des commissions${salesperson.is_unattributed ? ' — ventes sans commercial (non payable)' : ''}`}
                actions={<Link className="text-sm font-medium text-primary" href={back}>← Tableau de bord</Link>}
            />
            <CommissionTabs active="dashboard" />
            <CommissionFilters path={`/finance/commissions/salespeople/${salesperson.key}`} filters={filters} stores={stores} />

            <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <CommissionMetric label="CA HT" value={formatMoney(kpis.revenue)} hint={`${kpis.order_count} commande(s)`} />
                {can.costs && <CommissionMetric label="Marge brute" value={formatMoney(kpis.margin)} tone={Number(kpis.margin) < 0 ? 'danger' : undefined} />}
                <CommissionMetric label="Commission brute" value={formatMoney(kpis.gross)} />
                <CommissionMetric label="Reprises" value={formatMoney(kpis.reversals)} tone={Number(kpis.reversals) < 0 ? 'danger' : undefined} hint="Retours, annulations, corrections" />
                <CommissionMetric label="Commission nette" value={formatMoney(kpis.net)} strong />
                <CommissionMetric label="En attente" value={formatMoney(kpis.pending)} />
                <CommissionMetric label="Approuvée" value={formatMoney(kpis.approved)} />
                <CommissionMetric label="Payée" value={formatMoney(kpis.paid)} />
            </section>

            <section className="overflow-x-auto rounded-card border border-line bg-surface">
                <table className="w-full min-w-[1000px] text-left text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-3 py-2">Date</th><th className="px-3 py-2">Commande</th><th className="px-3 py-2">Produit / ligne</th>
                            <th className="px-3 py-2 text-right">CA HT</th>{can.costs && <th className="px-3 py-2 text-right">Marge</th>}
                            <th className="px-3 py-2 text-right">Taux</th><th className="px-3 py-2 text-right">Commission</th><th className="px-3 py-2">Type</th><th className="px-3 py-2">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.data.map((entry) => (
                            <tr key={entry.id} className="border-t border-line align-top">
                                <td className="px-3 py-2 text-ink-muted">{formatDate(entry.occurred_at)}<span className="block text-[11px] text-ink-faint">vente {formatDate(entry.sale_date)}</span></td>
                                <td className="px-3 py-2">{entry.sales_order ? <Link className="font-medium text-primary" href={`/sales/orders/${entry.sales_order.id}`}>{entry.sales_order.order_number}</Link> : '—'}{entry.customer_return && <span className="block text-[11px] text-ink-faint">{entry.customer_return.return_number}</span>}</td>
                                <td className="px-3 py-2"><Link className="text-ink hover:text-primary" href={`/finance/commissions/ledger/${entry.id}`}>{entry.product_name_snapshot ?? entry.line_reference_snapshot ?? `Écriture #${entry.id}`}</Link>{entry.reversal_reason && <span className="block text-[11px] text-ink-faint">{entry.reversal_reason}</span>}</td>
                                <td className="px-3 py-2 text-right">{formatMoney(entry.revenue_ht_snapshot)}</td>
                                {can.costs && <td className="px-3 py-2 text-right">{formatMoney(entry.margin_amount_snapshot)}</td>}
                                <td className="px-3 py-2 text-right">{Number(entry.commission_rate_snapshot).toLocaleString('fr-FR')} %</td>
                                <td className={`px-3 py-2 text-right font-semibold ${Number(entry.commission_amount) < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(entry.commission_amount)}</td>
                                <td className="px-3 py-2">{TYPE_LABELS[entry.entry_type] ?? entry.entry_type}</td>
                                <td className="px-3 py-2">{STATUS_LABELS[entry.status] ?? entry.status}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {entries.data.length === 0 && <p className="p-8 text-center text-sm text-ink-faint">Aucune écriture pour ces filtres.</p>}
            </section>
            <Pagination links={entries.links} />
        </ApplicationShell>
    );
}
