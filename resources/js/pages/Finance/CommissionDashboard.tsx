import CommissionFilters, {
    filterQuery,
    useCommissionLabels,
} from "@/components/finance/CommissionFilters";
import type { CommissionFilterState } from "@/components/finance/CommissionFilters";
import CommissionMetric from "@/components/finance/CommissionMetric";
import CommissionTabs from "@/components/finance/CommissionTabs";
import { useI18n } from "@/i18n";
import { Button } from "@/components/ui/Button";
import EmptyState from "@/components/ui/EmptyState";
import PageHeader from "@/components/ui/PageHeader";
import ApplicationShell from "@/layouts/ApplicationShell";
import { formatDate, formatMoney } from "@/utils/format";
import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import type { ReactNode } from "react";

type Amounts = {
    gross: string;
    reversals: string;
    net: string;
    pending: string;
    approved: string;
    paid: string;
    revenue: string;
    cost?: string;
    margin?: string;
    margin_rate?: string | null;
    commission_to_margin_ratio?: string | null;
};
type Kpis = Amounts & {
    return_reversals: string;
    cancellations: string;
    corrections: string;
    manual_adjustments: string;
    entry_count: number;
    order_count: number;
    salesperson_count: number;
};
type RankingRow = Amounts & {
    salesperson_id: number | null;
    salesperson_name: string;
    is_unattributed: boolean;
    orders: number;
    entry_count: number;
};
type StoreRow = Amounts & { store_id: number | null; store_name: string };
type Tier = {
    rule_set: string;
    min_margin: string | null;
    max_margin: string | null;
    commission_rate: string;
    sale_lines: number;
    entry_count: number;
    revenue: string;
    margin?: string;
    gross: string;
    net: string;
};
type Adjustments = {
    by_type: { entry_type: string; amount: string; count: number }[];
    by_salesperson: {
        salesperson_id: number | null;
        salesperson_name: string | null;
        return_reversals: string;
        cancellations: string;
        corrections: string;
        total: string;
    }[];
    recent: {
        id: number;
        occurred_at: string;
        sale_date: string;
        entry_type: string;
        status: string;
        commission_amount: string;
        salesperson_name: string | null;
        product: string | null;
        reason: string | null;
        sales_order_id: number;
        order_number: string | null;
        return_number: string | null;
    }[];
};
type Health = {
    confirmed_line_count: number;
    missing_salesperson: number;
    missing_cost: number;
    margin_unavailable: number;
    has_active_rules: boolean;
    has_any_entries: boolean;
};

type Props = {
    organization: { id: number; name: string };
    filters: CommissionFilterState;
    sorting: { sort: string; direction: "asc" | "desc" };
    stores: { id: number; name: string; code: string }[];
    salespeople: { id: number; name: string | null }[];
    kpis: Kpis;
    ranking: RankingRow[];
    trend: {
        granularity: "day" | "week" | "month";
        points: {
            key: string;
            label: string;
            gross: string;
            reversals: string;
            net: string;
        }[];
    };
    statuses: { status: string; amount: string; count: number }[];
    tiers: Tier[];
    storeBreakdown: StoreRow[] | null;
    adjustments: Adjustments;
    health: Health;
    reconciliation?: Record<string, number> | null;
    can: {
        costs: boolean;
        export: boolean;
        reconcile: boolean;
        rules: boolean;
    };
};
type Translator = ReturnType<typeof useI18n>["t"];

export default function CommissionDashboard(props: Props) {
    const { t } = useI18n();
    const {
        organization,
        filters,
        sorting,
        stores,
        salespeople,
        kpis,
        ranking,
        trend,
        statuses,
        tiers,
        storeBreakdown,
        adjustments,
        health,
        reconciliation,
        can,
    } = props;
    const [scanning, setScanning] = useState(false);
    const query = filterQuery(filters);
    const empty = kpis.entry_count === 0;

    return (
        <ApplicationShell wide>
            <Head title={t("commissions.dashboard.headTitle")} />
            <PageHeader
                title={t("commissions.dashboard.title")}
                description={`${organization.name} · ${t("commissions.dashboard.description")}`}
                actions={
                    can.export && (
                        <>
                            <a
                                className={linkButton}
                                href={`/finance/commissions/export/xlsx?${query}`}
                            >
                                {t("common.exportExcel")}
                            </a>
                            <a
                                className={linkButton}
                                href={`/finance/commissions/export/pdf?${query}`}
                            >
                                {t("commissions.dashboard.pdfReport")}
                            </a>
                        </>
                    )
                }
            />
            <CommissionTabs active="dashboard" />
            <CommissionFilters
                path="/finance/commissions"
                filters={filters}
                stores={stores}
                salespeople={salespeople}
                extra={{ sort: sorting.sort, direction: sorting.direction }}
            />

            {!health.has_active_rules && !health.has_any_entries ? (
                <EmptyState
                    title={t("commissions.dashboard.noActiveRule")}
                    description={t("commissions.dashboard.noActiveRuleHelp")}
                    actions={
                        can.rules && (
                            <Link
                                className={linkButton}
                                href="/finance/commissions/rules"
                            >
                                {t("commissions.dashboard.configureRules")}
                            </Link>
                        )
                    }
                />
            ) : (
                <>
                    {empty && (
                        <div className="mb-5 rounded-card border border-dashed border-line-strong bg-raised p-4 text-sm text-ink-muted">
                            {t("commissions.dashboard.noEntries")}
                            {health.confirmed_line_count > 0 &&
                                ` ${t("commissions.dashboard.confirmedLinesHelp").replace("{count}", String(health.confirmed_line_count))}`}
                            {health.confirmed_line_count === 0 &&
                                ` ${t("commissions.dashboard.noConfirmedSales")}`}
                        </div>
                    )}

                    <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                        <CommissionMetric
                            label={t("commissions.dashboard.grossCommission")}
                            value={formatMoney(kpis.gross)}
                            hint={t("commissions.dashboard.beforeReversals")}
                        />
                        <CommissionMetric
                            label={t(
                                "commissions.dashboard.cancellationsReturns",
                            )}
                            value={formatMoney(kpis.reversals)}
                            hint={t("commissions.dashboard.signedReversals")}
                            tone={
                                Number(kpis.reversals) < 0
                                    ? "danger"
                                    : undefined
                            }
                        />
                        <CommissionMetric
                            label={t("commissions.dashboard.netCommission")}
                            value={formatMoney(kpis.net)}
                            hint={t("commissions.dashboard.grossPlusReversals")}
                            strong
                        />
                        <CommissionMetric
                            label={t("status.pending")}
                            value={formatMoney(kpis.pending)}
                            hint={t(
                                "commissions.dashboard.entriesCount",
                            ).replace(
                                "{count}",
                                String(statusCount(statuses, "pending")),
                            )}
                        />
                        <CommissionMetric
                            label={t("status.approved")}
                            value={formatMoney(kpis.approved)}
                            hint={t(
                                "commissions.dashboard.entriesCount",
                            ).replace(
                                "{count}",
                                String(statusCount(statuses, "approved")),
                            )}
                        />
                        <CommissionMetric
                            label={t("status.paid")}
                            value={formatMoney(kpis.paid)}
                            hint={t(
                                "commissions.dashboard.entriesCount",
                            ).replace(
                                "{count}",
                                String(statusCount(statuses, "paid")),
                            )}
                        />
                    </section>
                    <p className="-mt-2 mb-5 text-xs text-ink-faint">
                        {t("commissions.dashboard.summary")
                            .replace("{orders}", String(kpis.order_count))
                            .replace(
                                "{salespeople}",
                                String(kpis.salesperson_count),
                            )
                            .replace("{entries}", String(kpis.entry_count))}
                    </p>

                    {can.costs ? (
                        <section className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <CommissionMetric
                                label={t(
                                    "commissions.dashboard.coveredRevenue",
                                )}
                                value={formatMoney(kpis.revenue)}
                                hint={t("commissions.dashboard.linesWithEntry")}
                            />
                            <CommissionMetric
                                label={t("commissions.dashboard.totalCost")}
                                value={formatMoney(kpis.cost)}
                            />
                            <CommissionMetric
                                label={t("commissions.dashboard.grossMargin")}
                                value={formatMoney(kpis.margin)}
                                tone={
                                    Number(kpis.margin) < 0
                                        ? "danger"
                                        : undefined
                                }
                            />
                            <CommissionMetric
                                label={t("commissions.dashboard.marginRate")}
                                value={percent(kpis.margin_rate)}
                                hint={
                                    kpis.margin_rate == null
                                        ? t(
                                              "commissions.dashboard.noCoveredRevenue",
                                          )
                                        : undefined
                                }
                            />
                            <CommissionMetric
                                label={t(
                                    "commissions.dashboard.commissionMargin",
                                )}
                                value={percent(kpis.commission_to_margin_ratio)}
                                hint={ratioHint(kpis, t)}
                            />
                        </section>
                    ) : (
                        <p className="mb-6 rounded-card border border-line bg-surface p-3 text-xs text-ink-muted">
                            {t("commissions.dashboard.coveredRevenue")} :{" "}
                            <strong>{formatMoney(kpis.revenue)}</strong> ·{" "}
                            {t("commissions.dashboard.costPermissionHelp")}
                        </p>
                    )}

                    <div className="mb-6 grid gap-5 lg:grid-cols-3">
                        <Card
                            title={t("commissions.dashboard.netTrend")}
                            subtitle={`${t("commissions.dashboard.groupedBy")} ${{ day: t("commissions.dashboard.day"), week: t("commissions.dashboard.week"), month: t("commissions.dashboard.month") }[trend.granularity]}`}
                            className="lg:col-span-2"
                        >
                            <TrendTable points={trend.points} />
                        </Card>
                        <Card
                            title={t("commissions.dashboard.byStatus")}
                            subtitle={t("commissions.dashboard.netAndCount")}
                        >
                            <StatusBreakdown statuses={statuses} />
                        </Card>
                    </div>

                    <Card
                        title={t(
                            "commissions.dashboard.salespersonPerformance",
                        )}
                        subtitle={t("commissions.dashboard.rankingHelp")}
                    >
                        <RankingTable
                            rows={ranking}
                            sorting={sorting}
                            filters={filters}
                            canCosts={can.costs}
                        />
                    </Card>

                    <div className="mt-6 grid gap-5 lg:grid-cols-2">
                        <Card
                            title={t("commissions.dashboard.adjustments")}
                            subtitle={t(
                                "commissions.dashboard.adjustmentsHelp",
                            )}
                        >
                            <AdjustmentsPanel
                                adjustments={adjustments}
                                filters={filters}
                            />
                        </Card>
                        <Card
                            title={t("commissions.dashboard.tierAnalysis")}
                            subtitle={t("commissions.dashboard.tierHelp")}
                        >
                            <TierTable tiers={tiers} canCosts={can.costs} />
                        </Card>
                    </div>

                    {storeBreakdown && (
                        <div className="mt-6">
                            <Card title={t("commissions.dashboard.byStore")}>
                                <StoreTable
                                    rows={storeBreakdown}
                                    canCosts={can.costs}
                                />
                            </Card>
                        </div>
                    )}
                </>
            )}

            <div className="mt-6">
                <Card
                    title={t("commissions.dashboard.health")}
                    subtitle={t("commissions.dashboard.healthHelp")}
                >
                    <div className="flex flex-wrap gap-2">
                        <HealthChip
                            label={t(
                                "commissions.dashboard.missingSalesperson",
                            )}
                            count={health.missing_salesperson}
                        />
                        <HealthChip
                            label={t("commissions.dashboard.missingCost")}
                            count={health.missing_cost}
                        />
                        <HealthChip
                            label={t("commissions.dashboard.marginUnavailable")}
                            count={health.margin_unavailable}
                        />
                        {reconciliation &&
                            Object.entries(reconciliation)
                                .filter(([key]) =>
                                    [
                                        "no_rule_set",
                                        "no_matching_tier",
                                        "eligible_missing",
                                        "missing_return_reversal",
                                    ].includes(key),
                                )
                                .map(([key, count]) => (
                                    <HealthChip
                                        key={key}
                                        label={reasonLabel(key, t)}
                                        count={count}
                                    />
                                ))}
                    </div>
                    {can.reconcile && reconciliation === undefined && (
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <Button
                                size="sm"
                                variant="secondary"
                                loading={scanning}
                                onClick={() => {
                                    setScanning(true);
                                    router.reload({
                                        only: ["reconciliation"],
                                        onFinish: () => setScanning(false),
                                    });
                                }}
                            >
                                {t("commissions.dashboard.analyzeMissing")}
                            </Button>
                            <span className="text-xs text-ink-faint">
                                {t("commissions.dashboard.analysisLimit")}
                            </span>
                        </div>
                    )}
                    {can.reconcile && (
                        <p className="mt-3 text-xs">
                            <Link
                                className="text-primary"
                                href={`/finance/commissions/ledger?from=${filters.from}&to=${filters.to}`}
                            >
                                {t("commissions.dashboard.openReconciliation")}{" "}
                                →
                            </Link>
                        </p>
                    )}
                </Card>
            </div>
        </ApplicationShell>
    );
}

function RankingTable({
    rows,
    sorting,
    filters,
    canCosts,
}: {
    rows: RankingRow[];
    sorting: Props["sorting"];
    filters: CommissionFilterState;
    canCosts: boolean;
}) {
    const { t } = useI18n();
    if (rows.length === 0)
        return (
            <p className="py-6 text-center text-sm text-ink-faint">
                {t("commissions.dashboard.noSalesperson")}
            </p>
        );
    const sortLink = (key: string) => {
        const direction =
            sorting.sort === key && sorting.direction === "desc"
                ? "asc"
                : "desc";
        return `/finance/commissions?${filterQuery(filters, { sort: key, direction })}`;
    };
    const Th = ({
        k,
        children,
        right = true,
    }: {
        k: string;
        children: ReactNode;
        right?: boolean;
    }) => (
        <th className={`px-3 py-2 ${right ? "text-right" : ""}`}>
            <Link href={sortLink(k)} preserveScroll className="hover:text-ink">
                {children}
                {sorting.sort === k
                    ? sorting.direction === "desc"
                        ? " ↓"
                        : " ↑"
                    : ""}
            </Link>
        </th>
    );
    const detail = (row: RankingRow) =>
        `/finance/commissions/salespeople/${row.salesperson_id ?? "unattributed"}?${filterQuery({ ...filters, salesperson: null })}`;

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-left text-sm">
                <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr>
                        <Th k="salesperson_name" right={false}>
                            {t("commissions.dashboard.salesperson")}
                        </Th>
                        <Th k="orders">{t("commissions.dashboard.orders")}</Th>
                        <Th k="revenue">
                            {t("commissions.dashboard.revenueExclTax")}
                        </Th>
                        {canCosts && (
                            <>
                                <Th k="margin">
                                    {t("commissions.dashboard.grossMargin")}
                                </Th>
                                <th className="px-3 py-2 text-right">
                                    {t("commissions.dashboard.marginRate")}
                                </th>
                            </>
                        )}
                        <Th k="gross">
                            {t("commissions.dashboard.grossShort")}
                        </Th>
                        <Th k="reversals">
                            {t("commissions.dashboard.returnsAdjustments")}
                        </Th>
                        <Th k="net">{t("commissions.dashboard.netShort")}</Th>
                        <Th k="pending">{t("status.pending")}</Th>
                        <Th k="approved">{t("status.approved")}</Th>
                        <Th k="paid">{t("status.paid")}</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.salesperson_id ?? "unattributed"}
                            className="border-t border-line"
                        >
                            <td className="px-3 py-2">
                                <Link
                                    href={detail(row)}
                                    className="font-medium text-primary"
                                >
                                    {row.salesperson_name}
                                </Link>
                                {row.is_unattributed && (
                                    <span className="ms-2 rounded-full bg-raised px-2 py-0.5 text-[10px] text-ink-muted">
                                        {t("commissions.dashboard.notPayable")}
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {row.orders}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMoney(row.revenue)}
                            </td>
                            {canCosts && (
                                <>
                                    <td className="px-3 py-2 text-right">
                                        {formatMoney(row.margin)}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {percent(row.margin_rate)}
                                    </td>
                                </>
                            )}
                            <td className="px-3 py-2 text-right">
                                {formatMoney(row.gross)}
                            </td>
                            <td
                                className={`px-3 py-2 text-right ${Number(row.reversals) < 0 ? "text-danger" : ""}`}
                            >
                                {formatMoney(row.reversals)}
                            </td>
                            <td className="px-3 py-2 text-right font-semibold">
                                {formatMoney(row.net)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMoney(row.pending)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMoney(row.approved)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMoney(row.paid)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function TrendTable({ points }: { points: Props["trend"]["points"] }) {
    const { t } = useI18n();
    if (points.length === 0)
        return (
            <p className="py-6 text-center text-sm text-ink-faint">
                {t("commissions.dashboard.noEntryPeriod")}
            </p>
        );
    const max = Math.max(
        ...points.map((point) =>
            Math.max(
                Math.abs(Number(point.gross)),
                Math.abs(Number(point.net)),
            ),
        ),
        1,
    );
    return (
        <div className="max-h-80 overflow-y-auto">
            <table className="w-full text-sm">
                <thead className="sticky top-0 bg-surface text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th className="py-2 text-start">
                            {t("common.period")}
                        </th>
                        <th className="py-2 text-end">
                            {t("commissions.dashboard.gross")}
                        </th>
                        <th className="py-2 text-end">
                            {t("commissions.dashboard.reversals")}
                        </th>
                        <th className="py-2 text-end">
                            {t("commissions.dashboard.net")}
                        </th>
                        <th className="w-2/5 py-2 ps-4 text-start">
                            {t("commissions.dashboard.net")}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {points.map((point) => (
                        <tr key={point.key} className="border-t border-line">
                            <td className="py-1.5 text-ink-muted">
                                {point.label}
                            </td>
                            <td className="py-1.5 text-end">
                                {formatMoney(point.gross)}
                            </td>
                            <td
                                className={`py-1.5 text-end ${Number(point.reversals) < 0 ? "text-danger" : ""}`}
                            >
                                {formatMoney(point.reversals)}
                            </td>
                            <td className="py-1.5 text-end font-medium">
                                {formatMoney(point.net)}
                            </td>
                            <td className="py-1.5 ps-4">
                                <div className="h-2 rounded-full bg-sage">
                                    <div
                                        className={`h-2 rounded-full ${Number(point.net) < 0 ? "bg-danger" : "bg-primary"}`}
                                        style={{
                                            width: `${(Math.abs(Number(point.net)) / max) * 100}%`,
                                        }}
                                    />
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function StatusBreakdown({ statuses }: { statuses: Props["statuses"] }) {
    const { statuses: statusLabels } = useCommissionLabels();
    const { t } = useI18n();
    const total = statuses.reduce((sum, row) => sum + row.count, 0);
    return (
        <ul className="space-y-3">
            {statuses.map((row) => (
                <li key={row.status}>
                    <div className="flex items-baseline justify-between text-sm">
                        <span className="font-medium text-ink">
                            {statusLabels[row.status] ?? row.status}
                        </span>
                        <span className="text-ink">
                            {/* 0% tier entries are a count, never payable money. */}
                            {row.status === "no_commission" ? "—" : formatMoney(row.amount)}{" "}
                            <span className="text-xs text-ink-faint">
                                ·{" "}
                                {t(
                                    "commissions.dashboard.entriesShort",
                                ).replace("{count}", String(row.count))}
                            </span>
                        </span>
                    </div>
                    <div className="mt-1 h-2 rounded-full bg-sage">
                        <div
                            className="h-2 rounded-full bg-primary"
                            style={{
                                width: total
                                    ? `${(row.count / total) * 100}%`
                                    : "0%",
                            }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function AdjustmentsPanel({
    adjustments,
    filters,
}: {
    adjustments: Adjustments;
    filters: CommissionFilterState;
}) {
    const { types } = useCommissionLabels();
    const { t } = useI18n();
    if (adjustments.by_type.length === 0)
        return (
            <p className="py-6 text-center text-sm text-ink-faint">
                {t("commissions.dashboard.noAdjustment")}
            </p>
        );
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                {adjustments.by_type.map((row) => (
                    <span
                        key={row.entry_type}
                        className="rounded-full bg-raised px-3 py-1 text-xs text-ink-muted"
                    >
                        {types[row.entry_type] ?? row.entry_type} :{" "}
                        <strong className="text-danger">
                            {formatMoney(row.amount)}
                        </strong>{" "}
                        ({row.count})
                    </span>
                ))}
            </div>
            <table className="w-full text-sm">
                <thead className="text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th className="py-1 text-start">
                            {t("commissions.dashboard.salesperson")}
                        </th>
                        <th className="py-1 text-end">
                            {t("commissions.dashboard.returns")}
                        </th>
                        <th className="py-1 text-end">
                            {t("commissions.dashboard.cancellations")}
                        </th>
                        <th className="py-1 text-end">
                            {t("commissions.dashboard.corrections")}
                        </th>
                        <th className="py-1 text-end">{t("common.total")}</th>
                    </tr>
                </thead>
                <tbody>
                    {adjustments.by_salesperson.map((row) => (
                        <tr
                            key={row.salesperson_id ?? "unattributed"}
                            className="border-t border-line"
                        >
                            <td className="py-1.5">
                                <Link
                                    className="text-primary"
                                    href={`/finance/commissions/salespeople/${row.salesperson_id ?? "unattributed"}?${filterQuery({ ...filters, salesperson: null })}`}
                                >
                                    {row.salesperson_name ??
                                        t("commissions.dashboard.unattributed")}
                                </Link>
                            </td>
                            <td className="py-1.5 text-right text-danger">
                                {formatMoney(row.return_reversals)}
                            </td>
                            <td className="py-1.5 text-right text-danger">
                                {formatMoney(row.cancellations)}
                            </td>
                            <td className="py-1.5 text-right text-danger">
                                {formatMoney(row.corrections)}
                            </td>
                            <td className="py-1.5 text-right font-semibold text-danger">
                                {formatMoney(row.total)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-faint">
                    {t("commissions.dashboard.latestReversals")}
                </p>
                <ul className="divide-y divide-line text-sm">
                    {adjustments.recent.map((row) => (
                        <li
                            key={row.id}
                            className="flex flex-wrap items-baseline justify-between gap-2 py-1.5"
                        >
                            <span>
                                <span className="text-ink-muted">
                                    {formatDate(row.occurred_at)}
                                </span>{" "}
                                · {types[row.entry_type] ?? row.entry_type} ·{" "}
                                <Link
                                    className="text-primary"
                                    href={`/sales/orders/${row.sales_order_id}`}
                                >
                                    {row.order_number ??
                                        `#${row.sales_order_id}`}
                                </Link>
                                {row.return_number && (
                                    <span className="text-ink-muted">
                                        {" "}
                                        (<bdi>{row.return_number}</bdi>)
                                    </span>
                                )}{" "}
                                ·{" "}
                                {row.salesperson_name ??
                                    t("commissions.dashboard.unattributed")}
                                {row.reason && (
                                    <span className="block text-xs text-ink-faint">
                                        {row.reason}
                                    </span>
                                )}
                            </span>
                            <Link
                                href={`/finance/commissions/ledger/${row.id}`}
                                className="font-semibold text-danger"
                            >
                                {formatMoney(row.commission_amount)}
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function TierTable({ tiers, canCosts }: { tiers: Tier[]; canCosts: boolean }) {
    const { t } = useI18n();
    if (tiers.length === 0)
        return (
            <p className="py-6 text-center text-sm text-ink-faint">
                {t("commissions.dashboard.noEntryPeriod")}
            </p>
        );
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th className="py-1 text-start">
                            {t("commissions.dashboard.rule")}
                        </th>
                        <th className="py-1 text-start">
                            {t("commissions.dashboard.marginTier")}
                        </th>
                        <th className="py-1 text-end">
                            {t("commissions.dashboard.rate")}
                        </th>
                        <th className="py-1 text-end">
                            {t("commissions.dashboard.lines")}
                        </th>
                        {canCosts && (
                            <th className="py-1 text-end">
                                {t("commissions.dashboard.margin")}
                            </th>
                        )}
                        <th className="py-1 text-end">
                            {t("commissions.dashboard.netCommission")}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {tiers.map((tier, index) => (
                        <tr key={index} className="border-t border-line">
                            <td className="py-1.5">{tier.rule_set}</td>
                            <td className="py-1.5 text-ink-muted">
                                {tierLabel(tier.min_margin, tier.max_margin, t)}
                            </td>
                            <td className="py-1.5 text-right">
                                {Number(tier.commission_rate).toLocaleString(
                                    "fr-FR",
                                )}{" "}
                                %
                            </td>
                            <td className="py-1.5 text-right">
                                {tier.sale_lines}
                            </td>
                            {canCosts && (
                                <td className="py-1.5 text-right">
                                    {formatMoney(tier.margin)}
                                </td>
                            )}
                            <td className="py-1.5 text-right font-medium">
                                {formatMoney(tier.net)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function StoreTable({
    rows,
    canCosts,
}: {
    rows: StoreRow[];
    canCosts: boolean;
}) {
    const { t } = useI18n();
    return (
        <table className="w-full text-sm">
            <thead className="text-[11px] uppercase tracking-wide text-ink-faint">
                <tr>
                    <th className="py-1 text-start">{t("common.store")}</th>
                    <th className="py-1 text-end">
                        {t("commissions.dashboard.revenueExclTax")}
                    </th>
                    {canCosts && (
                        <th className="py-1 text-end">
                            {t("commissions.dashboard.grossMargin")}
                        </th>
                    )}
                    <th className="py-1 text-end">
                        {t("commissions.dashboard.netCommission")}
                    </th>
                    <th className="py-1 text-end">{t("status.paid")}</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr
                        key={row.store_id ?? "none"}
                        className="border-t border-line"
                    >
                        <td className="py-1.5">{row.store_name}</td>
                        <td className="py-1.5 text-right">
                            {formatMoney(row.revenue)}
                        </td>
                        {canCosts && (
                            <td className="py-1.5 text-right">
                                {formatMoney(row.margin)}
                            </td>
                        )}
                        <td className="py-1.5 text-right font-medium">
                            {formatMoney(row.net)}
                        </td>
                        <td className="py-1.5 text-right">
                            {formatMoney(row.paid)}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function Card({
    title,
    subtitle,
    children,
    className = "",
}: {
    title: string;
    subtitle?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={`rounded-card border border-line bg-surface p-5 ${className}`}
        >
            <h2 className="font-semibold text-ink">{title}</h2>
            {subtitle && (
                <p className="mt-0.5 text-xs text-ink-muted">{subtitle}</p>
            )}
            <div className="mt-4">{children}</div>
        </section>
    );
}

function HealthChip({ label, count }: { label: string; count: number }) {
    return (
        <span
            className={`rounded-full px-3 py-1 text-xs ${count > 0 ? "bg-warning-soft text-warning" : "bg-raised text-ink-muted"}`}
        >
            {label} : <strong>{count}</strong>
        </span>
    );
}

const statusCount = (statuses: Props["statuses"], status: string) =>
    statuses.find((row) => row.status === status)?.count ?? 0;
const percent = (value: string | null | undefined) =>
    value == null
        ? "n/d"
        : `${Number(value).toLocaleString("fr-FR", { maximumFractionDigits: 2 })} %`;
const ratioHint = (kpis: Kpis, t: Translator) =>
    kpis.commission_to_margin_ratio != null
        ? t("commissions.dashboard.netOverMargin")
        : Number(kpis.margin ?? 0) < 0
          ? t("commissions.dashboard.negativeMargin")
          : t("commissions.dashboard.zeroMargin");
const tierLabel = (min: string | null, max: string | null, t: Translator) => {
    const f = (value: string) => `${Number(value).toLocaleString("fr-FR")} %`;
    if (min === null && max === null)
        return t("commissions.dashboard.allMargins");
    if (min === null) return `< ${f(max!)}`;
    if (max === null) return `≥ ${f(min)}`;
    return `${f(min)} – ${f(max)}`;
};
const reasonLabel = (value: string, t: Translator) =>
    ({
        eligible_missing: t("commissions.dashboard.eligibleMissing"),
        no_rule_set: t("commissions.dashboard.noApplicableRule"),
        no_matching_tier: t("commissions.dashboard.noApplicableTier"),
        missing_return_reversal: t(
            "commissions.dashboard.missingReturnReversal",
        ),
    })[value] ?? value;
const linkButton =
    "inline-flex items-center rounded-field border border-line-strong bg-surface px-3 py-2 text-sm font-medium text-ink hover:border-primary hover:text-primary";
