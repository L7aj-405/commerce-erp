import { Button, ButtonLink } from "@/components/ui/Button";
import PageHeader from "@/components/ui/PageHeader";
import Pagination from "@/components/ui/Pagination";
import { useI18n } from "@/i18n";
import CatalogLayout from "@/layouts/CatalogLayout";
import { formatMoney } from "@/utils/format";
import { Head, useForm } from "@inertiajs/react";

type Import = {
    id: number;
    original_file_name: string;
    status: string;
    total_rows: number;
    ready_count: number;
    unchanged_count: number;
    invalid_count: number;
    not_found_count: number;
    duplicate_count: number;
    skipped_count: number;
    updated_count: number;
    created_by: { name: string };
};
type Row = {
    id: number;
    row_number: number;
    product_name: string | null;
    variant_label: string | null;
    sku: string | null;
    current_purchase_price: string | null;
    new_purchase_price: string | null;
    status: string;
    message: string | null;
};
type Props = {
    import: Import;
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

const colors: Record<string, string> = {
    ready: "bg-success-soft text-success",
    updated: "bg-success-soft text-success",
    unchanged: "bg-raised text-ink-muted",
    invalid: "bg-danger-soft text-danger",
    not_found: "bg-danger-soft text-danger",
    duplicate: "bg-warning-soft text-warning",
    skipped: "bg-raised text-ink-muted",
};

export default function ProductCostImportPreview({
    import: productImport,
    rows,
}: Props) {
    const { t } = useI18n();
    const labels: Record<string, string> = {
        ready: t("imports.costs.ready"),
        unchanged: t("imports.costs.unchanged"),
        invalid: t("imports.costs.invalid"),
        not_found: t("imports.costs.notFound"),
        duplicate: t("imports.costs.duplicate"),
        skipped: t("imports.costs.skipped"),
        updated: t("imports.costs.updated"),
    };
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const confirm = () =>
        form.post(
            `/catalog/purchase-prices/imports/${productImport.id}/confirm`,
        );
    const completed = productImport.status === "completed";
    return (
        <CatalogLayout>
            <Head title={t("imports.costs.headTitle")} />
            <PageHeader
                title={t("imports.costs.title")}
                description={`${productImport.original_file_name} · ${t("imports.costs.analyzedRows").replace("{count}", String(productImport.total_rows))}`}
                actions={
                    <ButtonLink
                        href="/catalog/purchase-prices"
                        variant="secondary"
                    >
                        {t("imports.costs.back")}
                    </ButtonLink>
                }
            />
            <section className="mb-6 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <Count
                    label={t("imports.costs.readyPlural")}
                    value={productImport.ready_count}
                />
                <Count
                    label={t("imports.costs.unchangedPlural")}
                    value={productImport.unchanged_count}
                />
                <Count
                    label={t("imports.costs.notFoundPlural")}
                    value={productImport.not_found_count}
                />
                <Count
                    label={t("imports.costs.invalidPlural")}
                    value={productImport.invalid_count}
                />
                <Count
                    label={t("imports.costs.duplicatePlural")}
                    value={productImport.duplicate_count}
                />
                <Count
                    label={t("imports.costs.skippedPlural")}
                    value={productImport.skipped_count}
                />
            </section>
            <section className="mb-6 flex flex-col gap-3 rounded-card border border-line bg-surface p-5 shadow-soft sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="font-semibold text-ink">
                        {completed
                            ? t("imports.costs.updatedCount").replace(
                                  "{count}",
                                  String(productImport.updated_count),
                              )
                            : t("imports.costs.readyCount").replace(
                                  "{count}",
                                  String(productImport.ready_count),
                              )}
                    </p>
                    <p className="mt-1 text-sm text-ink-muted">
                        {t("imports.costs.safetyHelp")}
                    </p>
                    {errors.import && (
                        <p className="mt-2 text-sm text-danger">
                            {errors.import}
                        </p>
                    )}
                </div>
                {!completed && (
                    <Button
                        type="button"
                        disabled={
                            productImport.ready_count === 0 || form.processing
                        }
                        onClick={confirm}
                    >
                        {form.processing
                            ? t("imports.costs.applying")
                            : t("imports.costs.confirm")}
                    </Button>
                )}
            </section>
            <div className="overflow-x-auto rounded-card border border-line bg-surface shadow-soft">
                <table className="w-full min-w-[850px] text-start text-sm">
                    <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th className="px-4 py-3">
                                {t("imports.products.row")}
                            </th>
                            <th className="px-4 py-3">
                                {t("imports.products.productVariant")}
                            </th>
                            <th className="px-4 py-3 text-end">
                                {t("imports.costs.currentPrice")}
                            </th>
                            <th className="px-4 py-3 text-end">
                                {t("imports.costs.newPrice")}
                            </th>
                            <th className="px-4 py-3">{t("common.status")}</th>
                            <th className="px-4 py-3">
                                {t("imports.costs.detail")}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {rows.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 text-ink-faint">
                                    {row.row_number}
                                </td>
                                <td className="px-4 py-3">
                                    <p className="font-semibold">
                                        {row.product_name ||
                                            t("imports.costs.unknownProduct")}
                                    </p>
                                    <p className="text-xs text-ink-muted">
                                        {row.variant_label ||
                                            t("imports.products.mainVariant")}
                                        {row.sku ? ` · ${row.sku}` : ""}
                                    </p>
                                </td>
                                <td className="px-4 py-3 text-end tabular-nums">
                                    {row.current_purchase_price === null
                                        ? "—"
                                        : formatMoney(
                                              row.current_purchase_price,
                                          )}
                                </td>
                                <td className="px-4 py-3 text-end font-semibold tabular-nums">
                                    {row.new_purchase_price === null
                                        ? "—"
                                        : formatMoney(row.new_purchase_price)}
                                </td>
                                <td className="px-4 py-3">
                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-semibold ${colors[row.status] ?? "bg-raised"}`}
                                    >
                                        {labels[row.status] ?? row.status}
                                    </span>
                                </td>
                                <td className="max-w-sm px-4 py-3 text-ink-muted">
                                    {row.message || "—"}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={rows.links} />
        </CatalogLayout>
    );
}

function Count({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-card border border-line bg-surface p-4 shadow-soft">
            <p className="text-xs uppercase tracking-wide text-ink-faint">
                {label}
            </p>
            <p className="mt-1 text-xl font-semibold tabular-nums">
                {value.toLocaleString("fr-FR")}
            </p>
        </div>
    );
}
