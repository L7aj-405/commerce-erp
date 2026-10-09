import CommissionFilters, { filterQuery, STATUS_LABELS, TYPE_LABELS } from '@/components/finance/CommissionFilters';
import type { CommissionFilterState } from '@/components/finance/CommissionFilters';
import CommissionMetric from '@/components/finance/CommissionMetric';
import CommissionTabs from '@/components/finance/CommissionTabs';
import { Button } from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatDate, formatMoney } from '@/utils/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

type Amounts = {
    gross: string; reversals: string; net: string; pending: string; approved: string; paid: string; revenue: string;
    cost?: string; margin?: string; margin_rate?: string | null; commission_to_margin_ratio?: string | null;
};
type Kpis = Amounts & {
    return_reversals: string; cancellations: string; corrections: string; manual_adjustments: string;
    entry_count: number; order_count: number; salesperson_count: number;
};
type RankingRow = Amounts & { salesperson_id: number | null; salesperson_name: string; is_unattributed: boolean; orders: number; entry_count: number };
type StoreRow = Amounts & { store_id: number | null; store_name: string };
type Tier = { rule_set: string; min_margin: string | null; max_margin: string | null; commission_rate: string; sale_lines: number; entry_count: number; revenue: string; margin?: string; gross: string; net: string };
type Adjustments = {
    by_type: { entry_type: string; amount: string; count: number }[];
    by_salesperson: { salesperson_id: number | null; salesperson_name: string | null; return_reversals: string; cancellations: string; corrections: string; total: string }[];
    recent: { id: number; occurred_at: string; sale_date: string; entry_type: string; status: string; commission_amount: string; salesperson_name: string | null; product: string | null; reason: string | null; sales_order_id: number; order_number: string | null; return_number: string | null }[];
};
type Health = { confirmed_line_count: number; missing_salesperson: number; missing_cost: number; margin_unavailable: number; has_active_rules: boolean; has_any_entries: boolean };

type Props = {
    organization: { id: number; name: string };
    filters: CommissionFilterState;
    sorting: { sort: string; direction: 'asc' | 'desc' };
    stores: { id: number; name: string; code: string }[];
    salespeople: { id: number; name: string | null }[];
    kpis: Kpis;
    ranking: RankingRow[];
    trend: { granularity: 'day' | 'week' | 'month'; points: { key: string; label: string; gross: string; reversals: string; net: string }[] };
    statuses: { status: string; amount: string; count: number }[];
    tiers: Tier[];
    storeBreakdown: StoreRow[] | null;
    adjustments: Adjustments;
    health: Health;
    reconciliation?: Record<string, number> | null;
    can: { costs: boolean; export: boolean; reconcile: boolean; rules: boolean };
};

export default function CommissionDashboard(props: Props) {
    const { organization, filters, sorting, stores, salespeople, kpis, ranking, trend, statuses, tiers, storeBreakdown, adjustments, health, reconciliation, can } = props;
    const [scanning, setScanning] = useState(false);
    const query = filterQuery(filters);
    const empty = kpis.entry_count === 0;

    return (
        <ApplicationShell wide>
            <Head title="Commissions · Tableau de bord" />
            <PageHeader
                title="Tableau de bord des commissions"
                description={`${organization.name} · Chiffres issus exclusivement du registre des commissions (écritures immuables)`}
                actions={can.export && (
                    <>
                        <a className={linkButton} href={`/finance/commissions/export/xlsx?${query}`}>Exporter Excel</a>
                        <a className={linkButton} href={`/finance/commissions/export/pdf?${query}`}>Rapport PDF</a>
                    </>
                )}
            />
            <CommissionTabs active="dashboard" />
            <CommissionFilters path="/finance/commissions" filters={filters} stores={stores} salespeople={salespeople} extra={{ sort: sorting.sort, direction: sorting.direction }} />

            {!health.has_active_rules && !health.has_any_entries ? (
                <EmptyState
                    title="Aucune règle de commission activée"
                    description="Aucune écriture de commission n’existe encore. Activez une version de règles pour que les ventes confirmées génèrent des commissions."
                    actions={can.rules && <Link className={linkButton} href="/finance/commissions/rules">Configurer les règles</Link>}
                />
            ) : (
                <>
                    {empty && (
                        <div className="mb-5 rounded-card border border-dashed border-line-strong bg-raised p-4 text-sm text-ink-muted">
                            Aucune écriture de commission sur cette période et ces filtres.
                            {health.confirmed_line_count > 0 && ` ${health.confirmed_line_count} ligne(s) de vente confirmée(s) existent sur la période — voir « Santé des commissions » ci-dessous pour les lignes non éligibles (commercial, coût ou marge manquants).`}
                            {health.confirmed_line_count === 0 && ' Aucune vente confirmée sur cette période.'}
                        </div>
                    )}

                    <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                        <CommissionMetric label="Commission brute générée" value={formatMoney(kpis.gross)} hint="Écritures de vente, avant reprises" />
                        <CommissionMetric label="Annulations / retours" value={formatMoney(kpis.reversals)} hint="Montant signé des reprises" tone={Number(kpis.reversals) < 0 ? 'danger' : undefined} />
                        <CommissionMetric label="Commission nette" value={formatMoney(kpis.net)} hint="Brute + reprises" strong />
                        <CommissionMetric label="En attente" value={formatMoney(kpis.pending)} hint={`${statusCount(statuses, 'pending')} écriture(s)`} />
                        <CommissionMetric label="Approuvée" value={formatMoney(kpis.approved)} hint={`${statusCount(statuses, 'approved')} écriture(s)`} />
                        <CommissionMetric label="Payée" value={formatMoney(kpis.paid)} hint={`${statusCount(statuses, 'paid')} écriture(s)`} />
                    </section>
                    <p className="-mt-2 mb-5 text-xs text-ink-faint">{kpis.order_count} commande(s) commissionnée(s) · {kpis.salesperson_count} commercial(aux) · {kpis.entry_count} écriture(s)</p>

                    {can.costs ? (
                        <section className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <CommissionMetric label="CA HT couvert" value={formatMoney(kpis.revenue)} hint="Lignes ayant une écriture de commission" />
                            <CommissionMetric label="Coût total" value={formatMoney(kpis.cost)} />
                            <CommissionMetric label="Marge brute" value={formatMoney(kpis.margin)} tone={Number(kpis.margin) < 0 ? 'danger' : undefined} />
                            <CommissionMetric label="Marge %" value={percent(kpis.margin_rate)} hint={kpis.margin_rate == null ? 'Aucun CA couvert' : undefined} />
                            <CommissionMetric label="Commission / marge brute" value={percent(kpis.commission_to_margin_ratio)} hint={ratioHint(kpis)} />
                        </section>
                    ) : (
                        <p className="mb-6 rounded-card border border-line bg-surface p-3 text-xs text-ink-muted">CA HT couvert : <strong>{formatMoney(kpis.revenue)}</strong> · Les indicateurs de coût et de marge nécessitent le droit « Prix d’achat ».</p>
                    )}

                    <div className="mb-6 grid gap-5 lg:grid-cols-3">
                        <Card title="Évolution de la commission nette" subtitle={`Regroupement par ${({ day: 'jour', week: 'semaine', month: 'mois' })[trend.granularity]}`} className="lg:col-span-2">
                            <TrendTable points={trend.points} />
                        </Card>
                        <Card title="Répartition par statut" subtitle="Montant net et nombre d’écritures">
                            <StatusBreakdown statuses={statuses} />
                        </Card>
                    </div>

                    <Card title="Performance par commercial" subtitle="Classement par défaut : commission nette décroissante. « Non attribuée » n’est pas un bénéficiaire payable.">
                        <RankingTable rows={ranking} sorting={sorting} filters={filters} canCosts={can.costs} />
                    </Card>

                    <div className="mt-6 grid gap-5 lg:grid-cols-2">
                        <Card title="Retours, annulations et corrections" subtitle="Reprises comptées à leur date de survenance, rattachées au commercial d’origine.">
                            <AdjustmentsPanel adjustments={adjustments} filters={filters} />
                        </Card>
                        <Card title="Analyse par tranche de règle" subtitle="D’après les instantanés de règle enregistrés sur chaque écriture.">
                            <TierTable tiers={tiers} canCosts={can.costs} />
                        </Card>
                    </div>

                    {storeBreakdown && (
                        <div className="mt-6">
                            <Card title="Répartition par magasin">
                                <StoreTable rows={storeBreakdown} canCosts={can.costs} />
                            </Card>
                        </div>
                    )}
                </>
            )}

            <div className="mt-6">
                <Card title="Santé des commissions" subtitle="Lignes de vente confirmées sur la période (date de vente) qui n’ont pas pu générer de commission.">
                    <div className="flex flex-wrap gap-2">
                        <HealthChip label="Commercial manquant" count={health.missing_salesperson} />
                        <HealthChip label="Coût manquant" count={health.missing_cost} />
                        <HealthChip label="Marge indisponible" count={health.margin_unavailable} />
                        {reconciliation && Object.entries(reconciliation).filter(([key]) => ['no_rule_set', 'no_matching_tier', 'eligible_missing', 'missing_return_reversal'].includes(key)).map(([key, count]) => (
                            <HealthChip key={key} label={reasonLabel(key)} count={count} />
                        ))}
                    </div>
                    {can.reconcile && reconciliation === undefined && (
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <Button size="sm" variant="secondary" loading={scanning} onClick={() => { setScanning(true); router.reload({ only: ['reconciliation'], onFinish: () => setScanning(false) }); }}>
                                Analyser règles et écritures manquantes
                            </Button>
                            <span className="text-xs text-ink-faint">Analyse bornée (500 commandes max.), lancée uniquement à la demande.</span>
                        </div>
                    )}
                    {can.reconcile && <p className="mt-3 text-xs"><Link className="text-primary" href={`/finance/commissions/ledger?from=${filters.from}&to=${filters.to}`}>Ouvrir la réconciliation dans le registre →</Link></p>}
                </Card>
            </div>
        </ApplicationShell>
    );
}

function RankingTable({ rows, sorting, filters, canCosts }: { rows: RankingRow[]; sorting: Props['sorting']; filters: CommissionFilterState; canCosts: boolean }) {
    if (rows.length === 0) return <p className="py-6 text-center text-sm text-ink-faint">Aucun commercial sur cette période.</p>;
    const sortLink = (key: string) => {
        const direction = sorting.sort === key && sorting.direction === 'desc' ? 'asc' : 'desc';
        return `/finance/commissions?${filterQuery(filters, { sort: key, direction })}`;
    };
    const Th = ({ k, children, right = true }: { k: string; children: ReactNode; right?: boolean }) => (
        <th className={`px-3 py-2 ${right ? 'text-right' : ''}`}>
            <Link href={sortLink(k)} preserveScroll className="hover:text-ink">{children}{sorting.sort === k ? (sorting.direction === 'desc' ? ' ↓' : ' ↑') : ''}</Link>
        </th>
    );
    const detail = (row: RankingRow) => `/finance/commissions/salespeople/${row.salesperson_id ?? 'unattributed'}?${filterQuery({ ...filters, salesperson: null })}`;

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-left text-sm">
                <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr>
                        <Th k="salesperson_name" right={false}>Commercial</Th><Th k="orders">Commandes</Th><Th k="revenue">CA HT</Th>
                        {canCosts && <><Th k="margin">Marge brute</Th><th className="px-3 py-2 text-right">Marge %</th></>}
                        <Th k="gross">Comm. brute</Th><Th k="reversals">Retours / ajust.</Th><Th k="net">Comm. nette</Th>
                        <Th k="pending">En attente</Th><Th k="approved">Approuvée</Th><Th k="paid">Payée</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.salesperson_id ?? 'unattributed'} className="border-t border-line">
                            <td className="px-3 py-2">
                                <Link href={detail(row)} className="font-medium text-primary">{row.salesperson_name}</Link>
                                {row.is_unattributed && <span className="ml-2 rounded-full bg-raised px-2 py-0.5 text-[10px] text-ink-muted">non payable</span>}
                            </td>
                            <td className="px-3 py-2 text-right">{row.orders}</td>
                            <td className="px-3 py-2 text-right">{formatMoney(row.revenue)}</td>
                            {canCosts && <><td className="px-3 py-2 text-right">{formatMoney(row.margin)}</td><td className="px-3 py-2 text-right">{percent(row.margin_rate)}</td></>}
                            <td className="px-3 py-2 text-right">{formatMoney(row.gross)}</td>
                            <td className={`px-3 py-2 text-right ${Number(row.reversals) < 0 ? 'text-danger' : ''}`}>{formatMoney(row.reversals)}</td>
                            <td className="px-3 py-2 text-right font-semibold">{formatMoney(row.net)}</td>
                            <td className="px-3 py-2 text-right">{formatMoney(row.pending)}</td>
                            <td className="px-3 py-2 text-right">{formatMoney(row.approved)}</td>
                            <td className="px-3 py-2 text-right">{formatMoney(row.paid)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function TrendTable({ points }: { points: Props['trend']['points'] }) {
    if (points.length === 0) return <p className="py-6 text-center text-sm text-ink-faint">Aucune écriture sur la période.</p>;
    const max = Math.max(...points.map((point) => Math.max(Math.abs(Number(point.gross)), Math.abs(Number(point.net)))), 1);
    return (
        <div className="max-h-80 overflow-y-auto">
            <table className="w-full text-sm">
                <thead className="sticky top-0 bg-surface text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr><th className="py-2 text-left">Période</th><th className="py-2 text-right">Brute</th><th className="py-2 text-right">Reprises</th><th className="py-2 text-right">Nette</th><th className="w-2/5 py-2 pl-4 text-left">Nette</th></tr>
                </thead>
                <tbody>
                    {points.map((point) => (
                        <tr key={point.key} className="border-t border-line">
                            <td className="py-1.5 text-ink-muted">{point.label}</td>
                            <td className="py-1.5 text-right">{formatMoney(point.gross)}</td>
                            <td className={`py-1.5 text-right ${Number(point.reversals) < 0 ? 'text-danger' : ''}`}>{formatMoney(point.reversals)}</td>
                            <td className="py-1.5 text-right font-medium">{formatMoney(point.net)}</td>
                            <td className="py-1.5 pl-4">
                                <div className="h-2 rounded-full bg-sage">
                                    <div className={`h-2 rounded-full ${Number(point.net) < 0 ? 'bg-danger' : 'bg-primary'}`} style={{ width: `${(Math.abs(Number(point.net)) / max) * 100}%` }} />
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function StatusBreakdown({ statuses }: { statuses: Props['statuses'] }) {
    const total = statuses.reduce((sum, row) => sum + row.count, 0);
    return (
        <ul className="space-y-3">
            {statuses.map((row) => (
                <li key={row.status}>
                    <div className="flex items-baseline justify-between text-sm">
                        <span className="font-medium text-ink">{STATUS_LABELS[row.status] ?? row.status}</span>
                        <span className="text-ink">{formatMoney(row.amount)} <span className="text-xs text-ink-faint">· {row.count} écr.</span></span>
                    </div>
                    <div className="mt-1 h-2 rounded-full bg-sage">
                        <div className="h-2 rounded-full bg-primary" style={{ width: total ? `${(row.count / total) * 100}%` : '0%' }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function AdjustmentsPanel({ adjustments, filters }: { adjustments: Adjustments; filters: CommissionFilterState }) {
    if (adjustments.by_type.length === 0) return <p className="py-6 text-center text-sm text-ink-faint">Aucun retour, annulation ou correction sur la période.</p>;
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                {adjustments.by_type.map((row) => (
                    <span key={row.entry_type} className="rounded-full bg-raised px-3 py-1 text-xs text-ink-muted">
                        {TYPE_LABELS[row.entry_type] ?? row.entry_type} : <strong className="text-danger">{formatMoney(row.amount)}</strong> ({row.count})
                    </span>
                ))}
            </div>
            <table className="w-full text-sm">
                <thead className="text-[11px] uppercase tracking-wide text-ink-faint"><tr><th className="py-1 text-left">Commercial</th><th className="py-1 text-right">Retours</th><th className="py-1 text-right">Annulations</th><th className="py-1 text-right">Corrections</th><th className="py-1 text-right">Total</th></tr></thead>
                <tbody>
                    {adjustments.by_salesperson.map((row) => (
                        <tr key={row.salesperson_id ?? 'unattributed'} className="border-t border-line">
                            <td className="py-1.5"><Link className="text-primary" href={`/finance/commissions/salespeople/${row.salesperson_id ?? 'unattributed'}?${filterQuery({ ...filters, salesperson: null })}`}>{row.salesperson_name ?? 'Non attribuée'}</Link></td>
                            <td className="py-1.5 text-right text-danger">{formatMoney(row.return_reversals)}</td>
                            <td className="py-1.5 text-right text-danger">{formatMoney(row.cancellations)}</td>
                            <td className="py-1.5 text-right text-danger">{formatMoney(row.corrections)}</td>
                            <td className="py-1.5 text-right font-semibold text-danger">{formatMoney(row.total)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-faint">Dernières reprises</p>
                <ul className="divide-y divide-line text-sm">
                    {adjustments.recent.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                            <span>
                                <span className="text-ink-muted">{formatDate(row.occurred_at)}</span> · {TYPE_LABELS[row.entry_type] ?? row.entry_type} ·{' '}
                                <Link className="text-primary" href={`/sales/orders/${row.sales_order_id}`}>{row.order_number ?? `#${row.sales_order_id}`}</Link>
                                {row.return_number && <span className="text-ink-muted"> ({row.return_number})</span>} · {row.salesperson_name ?? 'Non attribuée'}
                                {row.reason && <span className="block text-xs text-ink-faint">{row.reason}</span>}
                            </span>
                            <Link href={`/finance/commissions/ledger/${row.id}`} className="font-semibold text-danger">{formatMoney(row.commission_amount)}</Link>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function TierTable({ tiers, canCosts }: { tiers: Tier[]; canCosts: boolean }) {
    if (tiers.length === 0) return <p className="py-6 text-center text-sm text-ink-faint">Aucune écriture sur la période.</p>;
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr><th className="py-1 text-left">Règle</th><th className="py-1 text-left">Tranche de marge</th><th className="py-1 text-right">Taux</th><th className="py-1 text-right">Lignes</th>{canCosts && <th className="py-1 text-right">Marge</th>}<th className="py-1 text-right">Commission nette</th></tr>
                </thead>
                <tbody>
                    {tiers.map((tier, index) => (
                        <tr key={index} className="border-t border-line">
                            <td className="py-1.5">{tier.rule_set}</td>
                            <td className="py-1.5 text-ink-muted">{tierLabel(tier.min_margin, tier.max_margin)}</td>
                            <td className="py-1.5 text-right">{Number(tier.commission_rate).toLocaleString('fr-FR')} %</td>
                            <td className="py-1.5 text-right">{tier.sale_lines}</td>
                            {canCosts && <td className="py-1.5 text-right">{formatMoney(tier.margin)}</td>}
                            <td className="py-1.5 text-right font-medium">{formatMoney(tier.net)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function StoreTable({ rows, canCosts }: { rows: StoreRow[]; canCosts: boolean }) {
    return (
        <table className="w-full text-sm">
            <thead className="text-[11px] uppercase tracking-wide text-ink-faint"><tr><th className="py-1 text-left">Magasin</th><th className="py-1 text-right">CA HT</th>{canCosts && <th className="py-1 text-right">Marge brute</th>}<th className="py-1 text-right">Commission nette</th><th className="py-1 text-right">Payée</th></tr></thead>
            <tbody>
                {rows.map((row) => (
                    <tr key={row.store_id ?? 'none'} className="border-t border-line">
                        <td className="py-1.5">{row.store_name}</td>
                        <td className="py-1.5 text-right">{formatMoney(row.revenue)}</td>
                        {canCosts && <td className="py-1.5 text-right">{formatMoney(row.margin)}</td>}
                        <td className="py-1.5 text-right font-medium">{formatMoney(row.net)}</td>
                        <td className="py-1.5 text-right">{formatMoney(row.paid)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function Card({ title, subtitle, children, className = '' }: { title: string; subtitle?: string; children: ReactNode; className?: string }) {
    return (
        <section className={`rounded-card border border-line bg-surface p-5 ${className}`}>
            <h2 className="font-semibold text-ink">{title}</h2>
            {subtitle && <p className="mt-0.5 text-xs text-ink-muted">{subtitle}</p>}
            <div className="mt-4">{children}</div>
        </section>
    );
}

function HealthChip({ label, count }: { label: string; count: number }) {
    return <span className={`rounded-full px-3 py-1 text-xs ${count > 0 ? 'bg-warning-soft text-warning' : 'bg-raised text-ink-muted'}`}>{label} : <strong>{count}</strong></span>;
}

const statusCount = (statuses: Props['statuses'], status: string) => statuses.find((row) => row.status === status)?.count ?? 0;
const percent = (value: string | null | undefined) => (value == null ? 'n/d' : `${Number(value).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`);
const ratioHint = (kpis: Kpis) => (kpis.commission_to_margin_ratio != null ? 'Commission nette ÷ marge brute' : Number(kpis.margin ?? 0) < 0 ? 'Non calculé : marge négative' : 'Non calculé : marge nulle');
const tierLabel = (min: string | null, max: string | null) => {
    const f = (value: string) => `${Number(value).toLocaleString('fr-FR')} %`;
    if (min === null && max === null) return 'Toutes marges';
    if (min === null) return `< ${f(max!)}`;
    if (max === null) return `≥ ${f(min)}`;
    return `${f(min)} – ${f(max)}`;
};
const reasonLabel = (value: string) => ({ eligible_missing: 'Éligible sans écriture', no_rule_set: 'Aucune règle applicable', no_matching_tier: 'Aucune tranche applicable', missing_return_reversal: 'Reprise de retour manquante' }[value] ?? value);
const linkButton = 'inline-flex items-center rounded-field border border-line-strong bg-surface px-3 py-2 text-sm font-medium text-ink hover:border-primary hover:text-primary';
