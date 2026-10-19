import { DownloadLink } from '@/components/ui/Button';
import { CashFlowChart, RankedMoneyChart, SalesCollectionsChart } from '@/components/dashboard/DashboardCharts';
import FinanceFilters, { type FinanceStore } from '@/components/finance/FinanceFilters';
import PageHeader from '@/components/ui/PageHeader';
import ApplicationShell from '@/layouts/ApplicationShell';
import { formatMoney } from '@/utils/format';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Situation = {
    period: string;
    label: string;
    ventes: string;
    ventes_nettes: string;
    facturation: string;
    facturation_brute: string;
    avoirs: string;
    encaissements: string;
    remboursements: string;
    net_encaisse: string;
    creances_debut: string;
    creances_fin: string;
    obligations_remboursement_debut: string;
    obligations_remboursement: string;
    position_nette_debut: string;
    position_nette_fin: string;
    reconciliation: { expected_fin: string; variance: string; unapplied_payments: string };
    customer_position: { expected_fin: string; variance: string };
};

type Props = {
    organization: { id: number; name: string };
    period: string;
    storeId: number | null;
    stores: FinanceStore[];
    situation: Situation;
    dashboard: {
        primary: { net_collections: string; receivables: string; treasury_balance: string | null; treasury_projected: string | null };
        secondary: { net_sales: string; net_billing: string; refunds: string; remaining_obligations: string; expected_collections: string; overdue: string; shortage: string | null };
        charts: {
            sales_vs_collections: Array<{ label: string; sales: string; collections: string }>;
            cash_flow: Array<{ label: string; incoming: string; outgoing: string }>;
            receivables: { counts: { unpaid: number; partially_paid: number }; amounts: { unpaid: string; partially_paid: string } };
            obligations: Array<{ category: string; label: string; amount: string }>;
        };
        treasury: { mode: 'historical' | 'current' | 'future'; balance: { complete: boolean; reason: string | null }; expected: string; remaining_outflows: string; overdue: string; projected: string | null; shortage: string | null };
    };
    can: { receivables: boolean; export: boolean };
};

export default function FinanceSituation({ organization, period, storeId, stores, situation, dashboard, can }: Props) {
    const q = (extra: Record<string, string | number | undefined> = {}) => ({ month: period, store_id: storeId ?? '', ...extra });

    const hasVariance = situation.customer_position.variance !== '0.0000' && Number(situation.customer_position.variance) !== 0;

    return (
        <ApplicationShell wide>
            <Head title="Situation mensuelle" />
            <PageHeader
                title="Situation mensuelle"
                description={`${organization.name} · ${situation.label}`}
                actions={
                    can.export ? (
                        <div className="flex gap-2">
                            <DownloadLink href={`/finance/export/xlsx?${new URLSearchParams(q() as Record<string, string>).toString()}`} variant="secondary" size="sm">
                                Export XLSX
                            </DownloadLink>
                            <DownloadLink href={`/finance/export/pdf?${new URLSearchParams(q() as Record<string, string>).toString()}`} variant="secondary" size="sm">
                                Export PDF
                            </DownloadLink>
                        </div>
                    ) : undefined
                }
            />

            <FinanceFilters path="/finance" period={period} storeId={storeId} stores={stores} />

            <section aria-label="Indicateurs financiers principaux" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <PrimaryMetric label="Net encaissé" value={dashboard.primary.net_collections} detail="Encaissements − remboursements" href={`/finance/ca-encaisse?${new URLSearchParams(q() as Record<string, string>).toString()}`} />
                <PrimaryMetric label="Créances clients" value={can.receivables ? dashboard.primary.receivables : null} detail={can.receivables ? 'Commandes confirmées restant à payer' : 'Accès non autorisé'} href={can.receivables ? `/finance/creances?${new URLSearchParams(q() as Record<string, string>).toString()}` : undefined} />
                <PrimaryMetric label="Solde de trésorerie" value={dashboard.primary.treasury_balance} detail={dashboard.primary.treasury_balance === null ? 'Solde non configuré · organisation entière' : 'Comptes financiers · organisation entière'} />
                <PrimaryMetric label="Prévision fin de mois" value={dashboard.primary.treasury_projected} detail={dashboard.primary.treasury_projected === null ? 'Prévision indisponible' : 'Solde + encaissements attendus − obligations'} />
            </section>

            <section aria-label="Indicateurs financiers secondaires" className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                <SecondaryMetric label="Ventes nettes" value={dashboard.secondary.net_sales} />
                <SecondaryMetric label="Facturation nette" value={dashboard.secondary.net_billing} />
                <SecondaryMetric label="Remboursements" value={dashboard.secondary.refunds} />
                <SecondaryMetric label="Obligations restantes" value={dashboard.secondary.remaining_obligations} />
                <SecondaryMetric label="Encaissements attendus" value={dashboard.secondary.expected_collections} />
                <SecondaryMetric label="En retard" value={dashboard.secondary.overdue} />
                <SecondaryMetric label="Manque de trésorerie" value={dashboard.secondary.shortage} />
            </section>

            <div className="mt-6"><SalesCollectionsChart points={dashboard.charts.sales_vs_collections} /></div>

            <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(320px,1fr)]">
                <CashFlowChart points={dashboard.charts.cash_flow} />
                <RankedMoneyChart title="Créances clients" description={`${dashboard.charts.receivables.counts.unpaid} non payée(s) · ${dashboard.charts.receivables.counts.partially_paid} partiellement payée(s)`} points={[
                    { label: 'Non payées', value: dashboard.charts.receivables.amounts.unpaid },
                    { label: 'Partiellement payées', value: dashboard.charts.receivables.amounts.partially_paid },
                ]} emptyLabel="Aucune créance client ouverte." />
            </div>

            <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,1fr)]">
                <RankedMoneyChart title="Obligations à payer" description="Reste dû par catégorie à la fin de la période" points={dashboard.charts.obligations.map((row) => ({ label: row.label, value: row.amount }))} emptyLabel="Aucune obligation restante." />
                <section className="rounded-card border border-line bg-surface p-5 shadow-card">
                    <h2 className="font-semibold text-ink">Prévision de trésorerie</h2>
                    <p className="mt-1 text-[13px] text-ink-muted">Synthèse fondée uniquement sur les échéances connues.</p>
                    <dl className="mt-5 space-y-3 text-sm">
                        <ForecastRow label="Solde actuel" value={dashboard.primary.treasury_balance} unavailable="Solde non configuré" />
                        <ForecastRow label="Encaissements attendus" value={dashboard.treasury.expected} />
                        <ForecastRow label="Sorties restantes" value={dashboard.treasury.remaining_outflows} negative />
                        <ForecastRow label="Dont en retard" value={dashboard.treasury.overdue} negative />
                        <div className="border-t border-line-strong pt-3"><ForecastRow label="Position projetée" value={dashboard.treasury.projected} unavailable="Prévision indisponible" strong /></div>
                    </dl>
                </section>
            </div>

            <section className="mt-8 rounded-card border border-line bg-surface p-5">
                <h2 className="mb-4 text-sm font-semibold text-ink">Réconciliation de la position client</h2>
                <table className="w-full max-w-xl text-sm">
                    <tbody>
                        <ReconciliationRow label="Position nette début (créances − à rembourser)" value={situation.position_nette_debut} />
                        <ReconciliationRow label="+ Facturation nette" value={situation.facturation} />
                        <ReconciliationRow label="− Encaissements nets" value={situation.net_encaisse} />
                        <tr className="border-t border-line-strong font-semibold text-ink">
                            <td className="py-2">= Position nette fin</td>
                            <td className="py-2 text-right">{formatMoney(situation.position_nette_fin)}</td>
                        </tr>
                        <ReconciliationRow label="dont créances clients" value={situation.creances_fin} />
                        <ReconciliationRow label="dont obligations de remboursement" value={situation.obligations_remboursement} />
                    </tbody>
                </table>
                {hasVariance && (
                    <p className="mt-3 rounded-field bg-raised px-3 py-2 text-[13px] text-ink-muted">
                        Écart de réconciliation : {formatMoney(situation.customer_position.variance)} — inclut notamment{' '}
                        {formatMoney(situation.reconciliation.unapplied_payments)} de paiement(s) reçu(s) ce mois-ci sur des commandes pas
                        encore facturées (état légitime : le paiement compte dans les encaissements mais n’a pas encore d’obligation
                        facturée à réduire).
                    </p>
                )}
            </section>
        </ApplicationShell>
    );
}

function PrimaryMetric({ label, value, detail, href }: { label: string; value: string | null; detail: string; href?: string }) {
    const content = (
        <div className="h-full rounded-card border border-line bg-surface p-5 shadow-card transition-soft hover:border-line-strong">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p>
            <p className="mt-3 text-3xl font-semibold tracking-tight text-ink">{value === null ? '—' : formatMoney(value)}</p>
            <p className="mt-2 text-[12px] text-ink-muted">{detail}</p>
        </div>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}

function SecondaryMetric({ label, value }: { label: string; value: string | null }) {
    return <div className="rounded-card border border-line bg-raised px-4 py-3"><p className="text-[10px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p><p className="mt-1.5 font-semibold tabular-nums text-ink">{value === null ? '—' : formatMoney(value)}</p></div>;
}

function ForecastRow({ label, value, unavailable = 'Indisponible', negative = false, strong = false }: { label: string; value: string | null; unavailable?: string; negative?: boolean; strong?: boolean }) {
    return <div className={`flex items-center justify-between gap-4 ${strong ? 'font-semibold' : ''}`}><dt className="text-ink-muted">{label}</dt><dd className="tabular-nums text-ink">{value === null ? unavailable : `${negative ? '− ' : ''}${formatMoney(value)}`}</dd></div>;
}

function ReconciliationRow({ label, value }: { label: string; value: string }): ReactNode {
    return (
        <tr>
            <td className="py-1.5 text-ink-muted">{label}</td>
            <td className="py-1.5 text-right text-ink">{formatMoney(value)}</td>
        </tr>
    );
}
