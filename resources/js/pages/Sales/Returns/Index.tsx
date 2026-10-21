import { useDebouncedValue } from "@/components/pos/useDebouncedValue";
import EmptyState from "@/components/ui/EmptyState";
import PageHeader from "@/components/ui/PageHeader";
import Pagination from "@/components/ui/Pagination";
import SearchInput from "@/components/ui/SearchInput";
import SalesLayout from "@/layouts/SalesLayout";
import { useI18n } from "@/i18n";
import { formatDateTime as formatDate, formatMoney } from "@/utils/format";
import { Head, Link } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";

type LinkData = { url: string | null; label: string; active: boolean };
type Result = {
    id: number;
    order_number: string;
    customer_name: string | null;
    fulfilled_at: string;
    deadline: string;
    eligible: boolean;
    returns_enabled: boolean;
    invoice_numbers: string[];
};
type ReturnRow = {
    id: number;
    return_number: string;
    status: string;
    total_incl_tax: string;
    currency_code: string;
    sales_order: { order_number: string; customer_name: string | null };
};
type Props = {
    returns: { data: ReturnRow[]; links: LinkData[] };
    searchUrl: string;
};

export default function ReturnsIndex({ returns, searchUrl }: Props) {
    const { t } = useI18n();
    const [query, setQuery] = useState("");
    const debounced = useDebouncedValue(query.trim(), 250);
    const [rows, setRows] = useState<Result[]>([]);
    const [loading, setLoading] = useState(false);
    const abort = useRef<AbortController | null>(null);

    useEffect(() => {
        abort.current?.abort();
        if (!debounced) {
            setRows([]);
            setLoading(false);
            return;
        }
        const controller = new AbortController();
        abort.current = controller;
        setLoading(true);
        fetch(`${searchUrl}?search=${encodeURIComponent(debounced)}`, {
            headers: { Accept: "application/json" },
            signal: controller.signal,
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((payload) => setRows(payload.data ?? []))
            .catch(() => {
                if (!controller.signal.aborted) setRows([]);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [debounced, searchUrl]);

    return (
        <SalesLayout>
            <Head title={t("returns.title")} />
            <PageHeader
                title={t("returns.title")}
                description={t("returns.description")}
            />

            <section className="mb-6 rounded-card border border-line bg-surface p-4 shadow-soft">
                <SearchInput
                    value={query}
                    onChange={setQuery}
                    searching={loading}
                    placeholder={t("returns.search")}
                />
                <div className="mt-3 divide-y divide-line">
                    {!query.trim() && (
                        <p className="py-4 text-sm text-ink-muted">
                            {t("returns.searchHelp")}
                        </p>
                    )}
                    {query.trim() && !loading && rows.length === 0 && (
                        <p className="py-4 text-sm text-ink-muted">
                            {t("returns.noSale")}
                        </p>
                    )}
                    {rows.map((row) => (
                        <div
                            key={row.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-3"
                        >
                            <div className="min-w-0">
                                <p className="font-semibold text-ink">
                                    <bdi>{row.order_number}</bdi> ·{" "}
                                    {row.customer_name ||
                                        t("common.counterCustomer")}
                                </p>
                                <p className="text-xs text-ink-muted">
                                    {t("returns.invoiceDelivered")
                                        .replace(
                                            "{invoice}",
                                            row.invoice_numbers.join(", ") ||
                                                "—",
                                        )
                                        .replace(
                                            "{date}",
                                            formatDate(row.fulfilled_at),
                                        )}
                                </p>
                                <p
                                    className={`mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${row.eligible ? "bg-success-soft text-success" : "bg-danger-soft text-danger"}`}
                                >
                                    {row.eligible
                                        ? t("returns.authorized")
                                        : row.returns_enabled
                                          ? t("returns.deadlineExceeded")
                                          : t("returns.disabled")}{" "}
                                    ·{" "}
                                    {t("returns.until").replace(
                                        "{date}",
                                        row.deadline
                                            ? formatDate(row.deadline)
                                            : "—",
                                    )}
                                </p>
                            </div>
                            <Link
                                href={`/sales/orders/${row.id}/returns/create`}
                                className="rounded-field border border-line-strong px-3 py-2 text-sm font-medium text-ink transition-soft hover:bg-sage"
                            >
                                {t("returns.create")}
                            </Link>
                        </div>
                    ))}
                </div>
            </section>

            <section>
                <div className="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 className="font-semibold text-ink">
                            {t("returns.history")}
                        </h2>
                        <p className="text-sm text-ink-muted">
                            {t("returns.historyHelp")}
                        </p>
                    </div>
                </div>

                {returns.data.length === 0 ? (
                    <EmptyState
                        title={t("returns.empty")}
                        description={t("returns.emptyHelp")}
                    />
                ) : (
                    <>
                        <div className="hidden overflow-hidden rounded-card border border-line bg-surface shadow-soft md:block">
                            <table className="w-full text-start text-sm">
                                <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                                    <tr>
                                        <th className="px-4 py-3">
                                            {t("returns.number")}
                                        </th>
                                        <th className="px-4 py-3">
                                            {t("returns.orderCustomer")}
                                        </th>
                                        <th className="px-4 py-3">
                                            {t("common.status")}
                                        </th>
                                        <th className="px-4 py-3 text-end">
                                            {t("returns.returnedAmount")}
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {returns.data.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="transition-soft hover:bg-raised/70"
                                        >
                                            <td className="px-4 py-3 font-semibold text-ink">
                                                {row.return_number}
                                            </td>
                                            <td className="px-4 py-3">
                                                <p className="font-medium text-ink">
                                                    {
                                                        row.sales_order
                                                            .order_number
                                                    }
                                                </p>
                                                <p className="text-xs text-ink-faint">
                                                    {row.sales_order
                                                        .customer_name ||
                                                        t(
                                                            "common.counterCustomer",
                                                        )}
                                                </p>
                                            </td>
                                            <td className="px-4 py-3">
                                                <ReturnStatus
                                                    status={row.status}
                                                />
                                            </td>
                                            <td className="px-4 py-3 text-end font-semibold tabular-nums text-ink">
                                                {formatMoney(
                                                    row.total_incl_tax,
                                                    row.currency_code,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-end">
                                                <Link
                                                    href={`/sales/returns/${row.id}`}
                                                    className="rounded-field px-3 py-2 text-sm font-medium text-ink transition-soft hover:bg-sage"
                                                >
                                                    {t("common.open")}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid gap-3 md:hidden">
                            {returns.data.map((row) => (
                                <Link
                                    key={row.id}
                                    href={`/sales/returns/${row.id}`}
                                    className="rounded-card border border-line bg-surface p-4 shadow-soft"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold text-ink">
                                                {row.return_number}
                                            </p>
                                            <p className="text-sm text-ink-muted">
                                                <bdi>
                                                    {
                                                        row.sales_order
                                                            .order_number
                                                    }
                                                </bdi>{" "}
                                                ·{" "}
                                                {row.sales_order
                                                    .customer_name ||
                                                    t("common.counterCustomer")}
                                            </p>
                                        </div>
                                        <ReturnStatus status={row.status} />
                                    </div>
                                    <p className="mt-3 text-end font-semibold tabular-nums text-ink">
                                        {formatMoney(
                                            row.total_incl_tax,
                                            row.currency_code,
                                        )}
                                    </p>
                                </Link>
                            ))}
                        </div>
                        <Pagination links={returns.links} />
                    </>
                )}
            </section>
        </SalesLayout>
    );
}

function ReturnStatus({ status }: { status: string }) {
    const { t } = useI18n();
    const styles: Record<string, string> = {
        draft: "bg-warning-soft text-warning",
        received: "bg-success-soft text-success",
        cancelled: "bg-danger-soft text-danger",
    };

    const labels: Record<string, string> = {
        draft: t("returns.draft"),
        received: t("returns.received"),
        cancelled: t("returns.cancelled"),
    };
    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${styles[status] ?? "bg-raised text-ink-muted"}`}
        >
            {labels[status] ?? status.replaceAll("_", " ")}
        </span>
    );
}
