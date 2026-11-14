import { AdvancedFilterBar, FilterSearch } from "@/components/ui/AdvancedFilterBar";
import type { FilterChip } from "@/components/ui/AdvancedFilterBar";
import { Button } from "@/components/ui/Button";
import EmptyState from "@/components/ui/EmptyState";
import PageHeader from "@/components/ui/PageHeader";
import StatusBadge from "@/components/ui/StatusBadge";
import { TablePager, TableShell } from "@/components/ui/TableShell";
import { useQueryFilters } from "@/hooks/useQueryFilters";
import { useI18n } from "@/i18n";
import ProcurementLayout from "@/layouts/ProcurementLayout";
import { formatDate, formatQuantity } from "@/utils/format";
import { Head, Link, useForm } from "@inertiajs/react";
import { useEffect, useState } from "react";

type Row = {
    id: number;
    description: string;
    requested_quantity: string;
    sales_order: { id: number; order_number: string } | null;
    customer: string | null;
    requested_by: string | null;
    created_at: string | null;
    status: string;
    status_label: string;
    resolved_article: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
};
type LinkData = { url: string | null; label: string; active: boolean };
type Props = {
    articles: { data: Row[]; links: LinkData[]; from?: number | null; to?: number | null; total?: number };
    filters: { search?: string; status: string };
    unresolvedCount: number;
    can: { manage: boolean };
};

const TABS = ["unresolved", "resolved", "all"] as const;

type VariantOption = {
    id: number;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    reference: string | null;
};

function ResolveForm({
    articleId,
    onDone,
}: {
    articleId: number;
    onDone: () => void;
}) {
    const { t } = useI18n();
    const [search, setSearch] = useState("");
    const [options, setOptions] = useState<VariantOption[]>([]);
    const form = useForm({ product_variant_id: 0 });

    useEffect(() => {
        const timer = window.setTimeout(() => {
            fetch(
                `/procurement/out-of-stock-articles/${articleId}/variant-search?search=${encodeURIComponent(search)}`,
                {
                    headers: { Accept: "application/json" },
                },
            )
                .then((r) => r.json())
                .then((json) => setOptions(json.data ?? []))
                .catch(() => setOptions([]));
        }, 250);
        return () => window.clearTimeout(timer);
    }, [search, articleId]);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (!form.data.product_variant_id) return;
                form.post(
                    `/procurement/out-of-stock-articles/${articleId}/resolve`,
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
            className="mt-2 flex flex-wrap items-center gap-2"
        >
            <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t("outOfStock.searchCatalog")}
                aria-label={t("outOfStock.searchCatalog")}
                className="control-input control-compact min-w-[220px] flex-1"
            />
            <select
                value={form.data.product_variant_id}
                onChange={(e) =>
                    form.setData("product_variant_id", Number(e.target.value))
                }
                aria-label={t("outOfStock.linkToItem")}
                className="control-select control-compact min-w-[200px] flex-1"
            >
                <option value={0}>{t("common.select")}</option>
                {options.map((o) => (
                    <option key={o.id} value={o.id}>
                        {o.product_name}{" "}
                        {o.variant_name ? `· ${o.variant_name}` : ""} (
                        {o.sku ?? o.reference ?? o.id})
                    </option>
                ))}
            </select>
            <Button
                type="submit"
                size="sm"
                disabled={!form.data.product_variant_id}
                loading={form.processing}
            >
                {t("outOfStock.linkToItem")}
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                {t("common.cancel")}
            </Button>
        </form>
    );
}

export default function OutOfStockArticlesIndex({
    articles,
    filters,
    unresolvedCount,
    can,
}: Props) {
    const { t } = useI18n();
    const [resolving, setResolving] = useState<number | null>(null);
    // OutOfStockArticleController@index validates `search` and `status`
    // (unresolved|resolved|all); the server defaults to `unresolved`.
    const f = useQueryFilters("/procurement/out-of-stock-articles", filters, {
        keys: ["search", "status"],
    });
    const tabLabel = (tab: string) =>
        t(`outOfStock.tab.${tab}` as Parameters<typeof t>[0]);

    const chips: FilterChip[] = [];
    if (filters.search) {
        chips.push({
            key: "search",
            label: t("filters.search"),
            value: filters.search,
            onRemove: () => f.remove("search"),
        });
    }
    if (filters.status !== "unresolved") {
        chips.push({
            key: "status",
            label: t("common.status"),
            value: tabLabel(filters.status),
            onRemove: () => f.remove("status"),
        });
    }
    const filtered = chips.length > 0;
    const emptyTitle = filtered ? t("filters.noMatch") : t("outOfStock.empty");
    const emptyDescription = filtered ? t("filters.noMatchHelp") : undefined;
    const emptyAction = filtered ? (
        <Button variant="secondary" onClick={f.reset}>
            {t("filters.reset")}
        </Button>
    ) : undefined;
    const pager = (
        <TablePager
            links={articles.links}
            from={articles.from}
            to={articles.to}
            total={articles.total}
        />
    );
    const statusPill = (row: Row) =>
        row.status === "resolved" ? (
            <StatusBadge status="completed">{t("outOfStock.resolved")}</StatusBadge>
        ) : (
            <StatusBadge status="pending">{t("outOfStock.toProcess")}</StatusBadge>
        );
    const resolution = (row: Row) => (
        <>
            → {row.resolved_article} · {t("finance.monthEnd.by")}{" "}
            {row.resolved_by ?? "—"}
            {row.resolved_at
                ? ` ${t("outOfStock.onDate")} ${formatDate(row.resolved_at)}`
                : ""}
        </>
    );

    return (
        <ProcurementLayout>
            <Head title={t("outOfStock.title")} />
            <PageHeader
                title={t("outOfStock.title")}
                description={t("outOfStock.description").replace(
                    "{count}",
                    String(unresolvedCount),
                )}
            />

            <div className="mb-4">
                <AdvancedFilterBar
                    label={t("outOfStock.title")}
                    search={
                        <FilterSearch
                            value={f.search}
                            onChange={f.setSearch}
                            loading={f.searching}
                            label={t("common.search")}
                            placeholder={t("outOfStock.search")}
                        />
                    }
                    chips={chips}
                    activeCount={chips.length}
                    loading={f.loading && !f.searching}
                    onReset={f.reset}
                >
                    <div
                        role="group"
                        aria-label={t("common.status")}
                        className="inline-flex h-10 items-center gap-0.5 rounded-field border border-line-strong bg-surface p-0.5"
                    >
                        {TABS.map((tab) => {
                            const active = filters.status === tab;
                            return (
                                <button
                                    key={tab}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() =>
                                        tab === "unresolved"
                                            ? f.remove("status")
                                            : f.set("status", tab)
                                    }
                                    className={`h-full rounded-[calc(var(--radius-field)-3px)] px-3 text-sm font-medium transition-soft focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary ${
                                        active
                                            ? "bg-primary text-primary-fg"
                                            : "text-ink-muted hover:bg-sage hover:text-ink"
                                    }`}
                                >
                                    {tabLabel(tab)}
                                </button>
                            );
                        })}
                    </div>
                </AdvancedFilterBar>
            </div>

            <TableShell
                label={t("outOfStock.title")}
                state={articles.data.length === 0 ? "empty" : "ready"}
                busy={f.loading}
                emptyTitle={emptyTitle}
                emptyDescription={emptyDescription}
                emptyAction={emptyAction}
                className="hidden md:block"
                footer={articles.data.length === 0 ? undefined : pager}
            >
                <table className="erp-table min-w-[900px] text-sm">
                    <thead>
                        <tr>
                            <th className="px-4 py-3">{t("outOfStock.requestedItem")}</th>
                            <th className="num px-4 py-3">{t("common.quantityShort")}</th>
                            <th className="px-4 py-3">{t("orders.show.order")}</th>
                            <th className="px-4 py-3">{t("common.customer")}</th>
                            <th className="px-4 py-3">{t("outOfStock.agent")}</th>
                            <th className="px-4 py-3">{t("common.date")}</th>
                            <th className="px-4 py-3">{t("common.status")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {articles.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 font-medium text-ink">{row.description}</td>
                                <td className="num px-4 py-3">{formatQuantity(row.requested_quantity)}</td>
                                <td className="px-4 py-3">
                                    {row.sales_order ? (
                                        <Link
                                            href={`/sales/orders/${row.sales_order.id}`}
                                            className="font-medium text-ink hover:text-accent hover:underline"
                                        >
                                            <bdi>{row.sales_order.order_number}</bdi>
                                        </Link>
                                    ) : (
                                        "—"
                                    )}
                                </td>
                                <td className="px-4 py-3 text-ink-muted">{row.customer ?? "—"}</td>
                                <td className="px-4 py-3 text-ink-muted">{row.requested_by ?? "—"}</td>
                                <td className="px-4 py-3 whitespace-nowrap text-ink-muted tabular-nums">
                                    {row.created_at ? formatDate(row.created_at) : "—"}
                                </td>
                                <td className="px-4 py-3">
                                    {row.status === "resolved" ? (
                                        <div>
                                            {statusPill(row)}
                                            <p className="mt-1 text-xs text-ink-muted">{resolution(row)}</p>
                                        </div>
                                    ) : resolving === row.id ? (
                                        <ResolveForm articleId={row.id} onDone={() => setResolving(null)} />
                                    ) : (
                                        <div className="flex flex-wrap items-center gap-2">
                                            {statusPill(row)}
                                            {can.manage && (
                                                <Button type="button" size="sm" variant="secondary" onClick={() => setResolving(row.id)}>
                                                    {t("outOfStock.linkToItem")}
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableShell>

            {/* Mobile: stacked cards */}
            {articles.data.length === 0 ? (
                <div className="md:hidden">
                    <EmptyState compact title={emptyTitle} description={emptyDescription} actions={emptyAction} />
                </div>
            ) : (
                <div className="space-y-3 md:hidden" aria-busy={f.loading || undefined}>
                    <ul className={`space-y-3 transition-opacity ${f.loading ? "opacity-55" : ""}`}>
                        {articles.data.map((row) => (
                            <li key={row.id} className="surface-card p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-ink">{row.description}</p>
                                        <p className="text-[13px] text-ink-muted">
                                            {t("common.quantityShort")}{" "}
                                            <span className="tabular-nums">{formatQuantity(row.requested_quantity)}</span>
                                        </p>
                                    </div>
                                    <span className="shrink-0">{statusPill(row)}</span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px]">
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t("orders.show.order")}</dt>
                                        <dd className="truncate">
                                            {row.sales_order ? (
                                                <Link href={`/sales/orders/${row.sales_order.id}`} className="font-medium text-ink underline">
                                                    <bdi>{row.sales_order.order_number}</bdi>
                                                </Link>
                                            ) : (
                                                "—"
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t("common.customer")}</dt>
                                        <dd className="truncate text-ink-muted">{row.customer ?? "—"}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t("outOfStock.agent")}</dt>
                                        <dd className="truncate text-ink-muted">{row.requested_by ?? "—"}</dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-ink-faint">{t("common.date")}</dt>
                                        <dd className="text-ink-muted">{row.created_at ? formatDate(row.created_at) : "—"}</dd>
                                    </div>
                                </dl>
                                {row.status === "resolved" ? (
                                    <p className="mt-2 text-[13px] text-ink-muted">{resolution(row)}</p>
                                ) : resolving === row.id ? (
                                    <div className="mt-3 border-t border-line pt-3">
                                        <ResolveForm articleId={row.id} onDone={() => setResolving(null)} />
                                    </div>
                                ) : (
                                    can.manage && (
                                        <Button type="button" size="sm" variant="secondary" className="mt-3" onClick={() => setResolving(row.id)}>
                                            {t("outOfStock.linkToItem")}
                                        </Button>
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                    {pager}
                </div>
            )}
        </ProcurementLayout>
    );
}
