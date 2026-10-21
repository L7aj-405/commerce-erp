import { DownloadLink } from "@/components/ui/Button";
import {
    CashFlowChart,
    RankedMoneyChart,
    SalesCollectionsChart,
} from "@/components/dashboard/DashboardCharts";
import FinanceFilters, {
    type FinanceStore,
} from "@/components/finance/FinanceFilters";
import PageHeader from "@/components/ui/PageHeader";
import { MetricCard } from "@/components/ui/MetricCard";
import ApplicationShell from "@/layouts/ApplicationShell";
import { useI18n } from "@/i18n";
import { formatMoney } from "@/utils/format";
import { Head } from "@inertiajs/react";
import type { ReactNode } from "react";

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
    reconciliation: {
        expected_fin: string;
        variance: string;
        unapplied_payments: string;
    };
    customer_position: { expected_fin: string; variance: string };
};

type Props = {
    organization: { id: number; name: string };
    period: string;
    storeId: number | null;
    stores: FinanceStore[];
    situation: Situation;
    dashboard: {
        primary: {
            net_collections: string;
            receivables: string;
            treasury_balance: string | null;
            treasury_projected: string | null;
        };
        secondary: {
            net_sales: string;
            net_billing: string;
            refunds: string;
            remaining_obligations: string;
            expected_collections: string;
            overdue: string;
            shortage: string | null;
        };
        charts: {
            sales_vs_collections: Array<{
                label: string;
                sales: string;
                collections: string;
            }>;
            cash_flow: Array<{
                label: string;
                incoming: string;
                outgoing: string;
            }>;
            receivables: {
                counts: { unpaid: number; partially_paid: number };
                amounts: { unpaid: string; partially_paid: string };
            };
            obligations: Array<{
                category: string;
                label: string;
                amount: string;
            }>;
        };
        treasury: {
            mode: "historical" | "current" | "future";
            balance: { complete: boolean; reason: string | null };
            expected: string;
            remaining_outflows: string;
            overdue: string;
            projected: string | null;
            shortage: string | null;
        };
    };
    can: { receivables: boolean; export: boolean };
};

export default function FinanceSituation({
    organization,
    period,
    storeId,
    stores,
    situation,
    dashboard,
    can,
}: Props) {
    const { t } = useI18n();
    const q = (extra: Record<string, string | number | undefined> = {}) => ({
        month: period,
        store_id: storeId ?? "",
        ...extra,
    });

    const hasVariance =
        situation.customer_position.variance !== "0.0000" &&
        Number(situation.customer_position.variance) !== 0;

    return (
        <ApplicationShell wide>
            <Head title={t("finance.monthlyOverview")} />
            <PageHeader
                title={t("finance.monthlyOverview")}
                description={`${organization.name} · ${situation.label}`}
                actions={
                    can.export ? (
                        <div className="flex gap-2">
                            <DownloadLink
                                href={`/finance/export/xlsx?${new URLSearchParams(q() as Record<string, string>).toString()}`}
                                variant="secondary"
                                size="sm"
                            >
                                {t("finance.exportXlsx")}
                            </DownloadLink>
                            <DownloadLink
                                href={`/finance/export/pdf?${new URLSearchParams(q() as Record<string, string>).toString()}`}
                                variant="secondary"
                                size="sm"
                            >
                                {t("finance.exportPdf")}
                            </DownloadLink>
                        </div>
                    ) : undefined
                }
            />

            <FinanceFilters
                path="/finance"
                period={period}
                storeId={storeId}
                stores={stores}
            />

            <section
                aria-label={t("finance.primaryIndicators")}
                className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            >
                <MetricCard
                    label={t("finance.netCollected")}
                    value={formatMoney(dashboard.primary.net_collections)}
                    helper={t("finance.netCollectedHelp")}
                    tone="positive"
                    href={`/finance/ca-encaisse?${new URLSearchParams(q() as Record<string, string>).toString()}`}
                />
                <MetricCard
                    label={t("finance.receivables")}
                    value={
                        can.receivables
                            ? formatMoney(dashboard.primary.receivables)
                            : "—"
                    }
                    helper={
                        can.receivables
                            ? t("finance.receivablesHelp")
                            : t("finance.unauthorized")
                    }
                    tone={
                        Number(dashboard.primary.receivables) > 0
                            ? "warning"
                            : "neutral"
                    }
                    href={
                        can.receivables
                            ? `/finance/creances?${new URLSearchParams(q() as Record<string, string>).toString()}`
                            : undefined
                    }
                />
                <MetricCard
                    label={t("finance.treasuryBalance")}
                    value={
                        dashboard.primary.treasury_balance === null
                            ? t("common.notConfigured")
                            : formatMoney(dashboard.primary.treasury_balance)
                    }
                    helper={
                        dashboard.primary.treasury_balance === null
                            ? t("finance.unconfiguredTreasury")
                            : t("finance.organizationAccounts")
                    }
                    unconfigured={dashboard.primary.treasury_balance === null}
                />
                <MetricCard
                    label={t("finance.monthEndForecast")}
                    value={
                        dashboard.primary.treasury_projected === null
                            ? t("common.unavailable")
                            : formatMoney(dashboard.primary.treasury_projected)
                    }
                    helper={
                        dashboard.primary.treasury_projected === null
                            ? t("finance.forecastUnavailable")
                            : t("finance.forecastFormula")
                    }
                    tone={
                        dashboard.primary.treasury_projected !== null &&
                        Number(dashboard.primary.treasury_projected) < 0
                            ? "negative"
                            : "forecast"
                    }
                    unconfigured={dashboard.primary.treasury_projected === null}
                />
            </section>

            <section
                aria-label={t("finance.secondaryIndicators")}
                className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7"
            >
                <MetricCard
                    level="secondary"
                    label={t("finance.netSales")}
                    value={formatMoney(dashboard.secondary.net_sales)}
                />
                <MetricCard
                    level="secondary"
                    label={t("finance.netBilling")}
                    value={formatMoney(dashboard.secondary.net_billing)}
                />
                <MetricCard
                    level="secondary"
                    label={t("finance.refunds")}
                    value={formatMoney(dashboard.secondary.refunds)}
                    tone={
                        Number(dashboard.secondary.refunds) > 0
                            ? "negative"
                            : "neutral"
                    }
                />
                <MetricCard
                    level="secondary"
                    label={t("finance.remainingObligations")}
                    value={formatMoney(
                        dashboard.secondary.remaining_obligations,
                    )}
                    tone={
                        Number(dashboard.secondary.remaining_obligations) > 0
                            ? "negative"
                            : "neutral"
                    }
                />
                <MetricCard
                    level="secondary"
                    label={t("finance.expectedCollections")}
                    value={formatMoney(
                        dashboard.secondary.expected_collections,
                    )}
                    tone={
                        Number(dashboard.secondary.expected_collections) > 0
                            ? "positive"
                            : "neutral"
                    }
                />
                <MetricCard
                    level="secondary"
                    label={t("finance.overdue")}
                    value={formatMoney(dashboard.secondary.overdue)}
                    tone={
                        Number(dashboard.secondary.overdue) > 0
                            ? "negative"
                            : "neutral"
                    }
                />
                <MetricCard
                    level="secondary"
                    label={t("finance.cashShortage")}
                    value={
                        dashboard.secondary.shortage === null
                            ? "—"
                            : formatMoney(dashboard.secondary.shortage)
                    }
                    tone={
                        Number(dashboard.secondary.shortage) > 0
                            ? "negative"
                            : "neutral"
                    }
                />
            </section>

            <div className="mt-6">
                <SalesCollectionsChart
                    points={dashboard.charts.sales_vs_collections}
                />
            </div>

            <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(320px,1fr)]">
                <CashFlowChart points={dashboard.charts.cash_flow} />
                <RankedMoneyChart
                    title={t("finance.receivables")}
                    description={`${dashboard.charts.receivables.counts.unpaid} ${t("finance.unpaid")} · ${dashboard.charts.receivables.counts.partially_paid} ${t("finance.partiallyPaid")}`}
                    points={[
                        {
                            label: t("finance.unpaid"),
                            value: dashboard.charts.receivables.amounts.unpaid,
                        },
                        {
                            label: t("finance.partiallyPaid"),
                            value: dashboard.charts.receivables.amounts
                                .partially_paid,
                        },
                    ]}
                    emptyLabel={t("finance.noReceivables")}
                />
            </div>

            <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,1fr)]">
                <RankedMoneyChart
                    title={t("finance.obligationsToPay")}
                    description={t("finance.remainingByCategory")}
                    points={dashboard.charts.obligations.map((row) => ({
                        label: row.label,
                        value: row.amount,
                    }))}
                    emptyLabel={t("finance.noObligations")}
                />
                <section className="rounded-card border border-line bg-surface p-5 shadow-card">
                    <h2 className="font-semibold text-ink">
                        {t("finance.treasuryForecast")}
                    </h2>
                    <p className="mt-1 text-[13px] text-ink-muted">
                        {t("finance.knownDueDatesOnly")}
                    </p>
                    <dl className="mt-5 space-y-3 text-sm">
                        <ForecastRow
                            label={t("finance.currentBalance")}
                            value={dashboard.primary.treasury_balance}
                            unavailable={t("common.notConfigured")}
                        />
                        <ForecastRow
                            label={t("finance.expectedCollections")}
                            value={dashboard.treasury.expected}
                            tone="positive"
                        />
                        <ForecastRow
                            label={t("finance.remainingOutflows")}
                            value={dashboard.treasury.remaining_outflows}
                            tone="negative"
                        />
                        <ForecastRow
                            label={t("finance.overdue")}
                            value={dashboard.treasury.overdue}
                            tone="negative"
                        />
                        <div className="border-t border-line-strong pt-3">
                            <ForecastRow
                                label={t("finance.projectedPosition")}
                                value={dashboard.treasury.projected}
                                unavailable={t("finance.forecastUnavailable")}
                                tone={
                                    dashboard.treasury.projected !== null &&
                                    Number(dashboard.treasury.projected) < 0
                                        ? "negative"
                                        : "forecast"
                                }
                                strong
                            />
                        </div>
                    </dl>
                </section>
            </div>

            <section className="mt-8 rounded-card border border-line bg-surface p-5">
                <h2 className="mb-4 text-sm font-semibold text-ink">
                    {t("finance.customerPositionReconciliation")}
                </h2>
                <table className="w-full max-w-xl text-sm">
                    <tbody>
                        <ReconciliationRow
                            label={t("finance.reconciliation.opening")}
                            value={situation.position_nette_debut}
                        />
                        <ReconciliationRow
                            label={t("finance.reconciliation.netBilling")}
                            value={situation.facturation}
                        />
                        <ReconciliationRow
                            label={t("finance.reconciliation.netCollections")}
                            value={situation.net_encaisse}
                        />
                        <tr className="border-t border-line-strong font-semibold text-ink">
                            <td className="py-2">
                                {t("finance.reconciliation.closing")}
                            </td>
                            <td className="py-2 text-end">
                                {formatMoney(situation.position_nette_fin)}
                            </td>
                        </tr>
                        <ReconciliationRow
                            label={t("finance.reconciliation.receivables")}
                            value={situation.creances_fin}
                        />
                        <ReconciliationRow
                            label={t("finance.reconciliation.refunds")}
                            value={situation.obligations_remboursement}
                        />
                    </tbody>
                </table>
                {hasVariance && (
                    <p className="mt-3 rounded-field bg-raised px-3 py-2 text-[13px] text-ink-muted">
                        {t("finance.reconciliation.variancePrefix")}{" "}
                        {formatMoney(situation.customer_position.variance)} —{" "}
                        {t("finance.reconciliation.varianceHelp").replace(
                            "{amount}",
                            formatMoney(
                                situation.reconciliation.unapplied_payments,
                            ),
                        )}
                    </p>
                )}
            </section>
        </ApplicationShell>
    );
}

function ForecastRow({
    label,
    value,
    unavailable = "—",
    tone = "neutral",
    strong = false,
}: {
    label: string;
    value: string | null;
    unavailable?: string;
    tone?: "neutral" | "positive" | "negative" | "forecast";
    strong?: boolean;
}) {
    const valueTone =
        tone === "positive"
            ? "finance-inflow"
            : tone === "negative"
              ? "finance-outflow"
              : tone === "forecast"
                ? "finance-forecast"
                : "text-ink";
    return (
        <div
            className={`flex items-center justify-between gap-4 ${strong ? "font-semibold" : ""}`}
        >
            <dt className="text-ink-muted">{label}</dt>
            <dd className={`metric-value ${valueTone}`} dir="ltr">
                {value === null ? unavailable : formatMoney(value)}
            </dd>
        </div>
    );
}

function ReconciliationRow({
    label,
    value,
}: {
    label: string;
    value: string;
}): ReactNode {
    return (
        <tr>
            <td className="py-1.5 text-ink-muted">{label}</td>
            <td className="metric-value py-1.5 text-end text-ink" dir="ltr">
                {formatMoney(value)}
            </td>
        </tr>
    );
}
