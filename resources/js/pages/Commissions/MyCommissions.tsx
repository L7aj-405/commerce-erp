import CommissionFilters, {
    useCommissionLabels,
} from "@/components/finance/CommissionFilters";
import type { CommissionFilterState } from "@/components/finance/CommissionFilters";
import CommissionMetric from "@/components/finance/CommissionMetric";
import PageHeader from "@/components/ui/PageHeader";
import { TablePager, TableShell } from "@/components/ui/TableShell";
import ApplicationShell from "@/layouts/ApplicationShell";
import { useI18n } from "@/i18n";
import { formatDate, formatMoney } from "@/utils/format";
import { Head } from "@inertiajs/react";

type Entry = {
    id: number;
    occurred_at: string;
    sale_date: string;
    entry_type: string;
    status: string;
    product_name_snapshot: string | null;
    commission_rate_snapshot: string;
    commission_amount: string;
    reversal_reason: string | null;
    approved_at: string | null;
    paid_at: string | null;
    margin_amount_snapshot?: string;
    margin_rate_snapshot?: string;
    sales_order: { id: number; order_number: string } | null;
    customer_return: { id: number; return_number: string } | null;
};
type Props = {
    filters: CommissionFilterState;
    summary: {
        gross: string;
        reversals: string;
        return_reversals: string;
        cancellations: string;
        corrections: string;
        net: string;
        pending: string;
        approved: string;
        paid: string;
        entry_count: number;
        order_count: number;
    };
    entries: {
        data: Entry[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    can: { margin: boolean };
};

/** Salesperson self-service: own entries only; no purchase cost is ever shown here. */
export default function MyCommissions({
    filters,
    summary,
    entries,
    can,
}: Props) {
    const { statuses, types } = useCommissionLabels();
    const { t, locale } = useI18n();
    return (
        <ApplicationShell wide>
            <Head title={t("commissions.my")} />
            <PageHeader
                title={t("commissions.my")}
                description={t("commissions.myHelp")}
            />
            <CommissionFilters path="/my-commissions" filters={filters} />

            <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <CommissionMetric
                    label={t("commissions.gross")}
                    value={formatMoney(summary.gross)}
                    hint={`${summary.order_count} ${t("commissions.orders")}`}
                />
                <CommissionMetric
                    label={t("commissions.reversals")}
                    value={formatMoney(summary.reversals)}
                    tone={Number(summary.reversals) < 0 ? "danger" : undefined}
                />
                <CommissionMetric
                    label={t("commissions.net")}
                    value={formatMoney(summary.net)}
                    strong
                />
                <CommissionMetric
                    label={t("commissions.pending")}
                    value={formatMoney(summary.pending)}
                />
                <CommissionMetric
                    label={t("commissions.approved")}
                    value={formatMoney(summary.approved)}
                />
                <CommissionMetric
                    label={t("commissions.paid")}
                    value={formatMoney(summary.paid)}
                />
            </section>

            <TableShell
                label={t("commissions.my")}
                state={entries.data.length === 0 ? "empty" : "ready"}
                emptyTitle={t("commissions.emptyPeriod")}
                footer={entries.data.length === 0 ? undefined : <TablePager links={entries.links} total={entries.total} />}
            >
                <table className="erp-table min-w-[820px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-3 py-2.5">{t("common.date")}</th>
                            <th className="px-3 py-2.5">{t("invoices.order")}</th>
                            <th className="px-3 py-2.5">
                                {t("commissions.product")}
                            </th>
                            {can.margin && (
                                <th className="num px-3 py-2.5">
                                    {t("commissions.margin")}
                                </th>
                            )}
                            <th className="num px-3 py-2.5">
                                {t("commissions.rate")}
                            </th>
                            <th className="num px-3 py-2.5">
                                {t("commissions.commission")}
                            </th>
                            <th className="px-3 py-2.5">
                                {t("commissions.type")}
                            </th>
                            <th className="px-3 py-2.5">{t("common.status")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.data.map((entry) => (
                            <tr
                                key={entry.id}
                                className="border-t border-line align-top"
                            >
                                <td className="px-3 py-2.5 whitespace-nowrap text-ink-muted tabular-nums">
                                    {formatDate(entry.occurred_at)}
                                </td>
                                <td className="px-3 py-2.5">
                                    {entry.sales_order?.order_number ?? "—"}
                                    {entry.customer_return && (
                                        <span className="block text-xs text-ink-muted">
                                            {
                                                entry.customer_return
                                                    .return_number
                                            }
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2.5">
                                    {entry.product_name_snapshot ?? "—"}
                                    {entry.reversal_reason && (
                                        <span className="block text-xs text-ink-muted">
                                            {entry.reversal_reason}
                                        </span>
                                    )}
                                </td>
                                {can.margin && (
                                    <td className="num px-3 py-2.5">
                                        {formatMoney(
                                            entry.margin_amount_snapshot,
                                        )}
                                    </td>
                                )}
                                <td className="num px-3 py-2.5">
                                    {Number(
                                        entry.commission_rate_snapshot,
                                    ).toLocaleString(locale === "ar" ? "ar-MA" : "fr-FR")}{" "}
                                    %
                                </td>
                                <td
                                    className={`num px-3 py-2.5 font-semibold ${Number(entry.commission_amount) < 0 ? "text-danger" : "text-ink"}`}
                                >
                                    {formatMoney(entry.commission_amount)}
                                </td>
                                <td className="px-3 py-2.5">
                                    {types[entry.entry_type] ??
                                        entry.entry_type}
                                </td>
                                <td className="px-3 py-2.5">
                                    {statuses[entry.status] ?? entry.status}
                                    {entry.paid_at && (
                                        <span className="block text-xs text-ink-muted">
                                            {t("commissions.on")}{" "}
                                            {formatDate(entry.paid_at)}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>
        </ApplicationShell>
    );
}
