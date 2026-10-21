import { Button, ButtonLink } from "@/components/ui/Button";
import PageHeader from "@/components/ui/PageHeader";
import StatusBadge from "@/components/ui/StatusBadge";
import StepIndicator from "@/components/ui/StepIndicator";
import { useI18n } from "@/i18n";
import CatalogLayout from "@/layouts/CatalogLayout";
import { Head, Link, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";

type Lookup = { id: number; name: string; symbol?: string; rate?: string };
type ImportRow = {
    id: number;
    row_number: number;
    raw_data?: string[];
    normalized_data: Record<string, string | null> | null;
    status: string;
    messages: string[] | null;
    product_id?: number | null;
    error_code?: string | null;
    error_phase?: string | null;
    error_message?: string | null;
};
type ProductImport = {
    id: number;
    original_file_name: string;
    source_format: string;
    status: string;
    global_error_code: string | null;
    global_error_message: string | null;
    headers: string[];
    mapping: Record<string, number | null>;
    defaults: Record<string, number | string | null> | null;
    total_rows: number;
    ready_count: number;
    warning_count: number;
    error_count: number;
    imported_count: number;
    created_product_count: number;
    created_variant_count: number;
    linked_image_url_count: number;
    invalid_or_missing_image_url_count: number;
    initialized_stock_count: number;
    skipped_count: number;
    failed_count: number;
    stock_detected: boolean;
    created_at: string;
    created_by?: { name: string };
};
type Props = {
    productImport: ProductImport | null;
    rows?: ImportRow[];
    resultRows?: ImportRow[];
    fields?: Record<string, { label: string; required: boolean }>;
    lookups?: {
        brands: Lookup[];
        categories: Lookup[];
        units: Lookup[];
        taxRates: Lookup[];
        warehouses: Array<Lookup & { code: string }>;
    };
    prospectiveCount?: number;
    maxFileMb: number;
    recentImports: ProductImport[];
    canImportStock?: boolean;
};

export default function ProductImportWizard({
    productImport,
    rows = [],
    resultRows = [],
    fields = {},
    lookups = {
        brands: [],
        categories: [],
        units: [],
        taxRates: [],
        warehouses: [],
    },
    prospectiveCount = 0,
    maxFileMb,
    recentImports,
    canImportStock = false,
}: Props) {
    const { t } = useI18n();
    const upload = useForm<{ file: File | null }>({ file: null });
    const mapping = useForm({
        mapping: productImport?.mapping ?? {},
        defaults: {
            category_id: productImport?.defaults?.category_id ?? null,
            brand_id: productImport?.defaults?.brand_id ?? null,
            unit_id: productImport?.defaults?.unit_id ?? null,
            tax_rate_id: productImport?.defaults?.tax_rate_id ?? null,
            stock_mode: productImport?.defaults?.stock_mode ?? "skip",
            warehouse_id: productImport?.defaults?.warehouse_id ?? null,
        },
    });
    const confirmation = useForm({});
    const finalStatuses = ["completed", "completed_with_errors", "failed"];
    const isFinal = productImport
        ? finalStatuses.includes(productImport.status)
        : false;
    const step = confirmation.processing
        ? 4
        : !productImport
          ? 1
          : productImport.status === "uploaded"
            ? 2
            : isFinal
              ? 5
              : productImport.status === "processing"
                ? 4
                : 3;
    const submitFile = (event: FormEvent) => {
        event.preventDefault();
        upload.post("/catalog/products/import", { forceFormData: true });
    };
    const submitMapping = (event: FormEvent) => {
        event.preventDefault();
        mapping.put(`/catalog/products/import/${productImport?.id}/preview`);
    };
    const confirm = () =>
        confirmation.post(
            `/catalog/products/import/${productImport?.id}/confirm`,
        );
    const fieldForColumn = (index: number) =>
        Object.entries(mapping.data.mapping).find(
            ([, value]) => value === index,
        )?.[0] ?? "";
    const assignColumn = (index: number, field: string) => {
        const next = { ...mapping.data.mapping };
        for (const key of Object.keys(next))
            if (next[key] === index) next[key] = null;
        if (field) next[field] = index;
        mapping.setData("mapping", next);
    };
    const selectId = (value: string) => (value ? Number(value) : null);
    const quantityMapped =
        mapping.data.mapping.stock_quantity !== null &&
        mapping.data.mapping.stock_quantity !== undefined;

    return (
        <CatalogLayout>
            <Head title={t("imports.products.title")} />
            <PageHeader
                title={t("imports.products.title")}
                description={t("imports.products.description")}
                actions={
                    <ButtonLink href="/catalog/products" variant="secondary">
                        {t("imports.products.backToProducts")}
                    </ButtonLink>
                }
            />
            <StepIndicator current={step} />
            {productImport?.global_error_message && (
                <section className="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-red-900">
                    <p className="font-semibold">
                        {productImport.global_error_code === "technical_error"
                            ? t("imports.products.technicalIncident")
                            : productImport.status === "failed"
                              ? t("imports.products.impossible")
                              : t("imports.products.fixConfiguration")}
                    </p>
                    <p className="mt-1 text-sm">
                        {productImport.global_error_message}
                    </p>
                    {productImport.global_error_code === "technical_error" && (
                        <p className="mt-2 text-xs">
                            {t("common.reference")} : Import #{productImport.id}
                        </p>
                    )}
                </section>
            )}
            {!productImport && (
                <>
                    <form
                        onSubmit={submitFile}
                        className="rounded-2xl border border-slate-200 bg-white p-6"
                    >
                        <label className="block text-sm font-medium">
                            {t("imports.products.fileLabel")}
                            <input
                                type="file"
                                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                onChange={(event) =>
                                    upload.setData(
                                        "file",
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                                className="mt-3 block w-full rounded-xl border border-dashed border-slate-300 p-6 text-sm"
                            />
                        </label>
                        <p className="mt-3 text-sm text-slate-500">
                            {t("imports.products.fileHelp").replace(
                                "{size}",
                                String(maxFileMb),
                            )}
                        </p>
                        {upload.errors.file && (
                            <p className="mt-3 text-sm text-red-600">
                                {upload.errors.file}
                            </p>
                        )}
                        <Button
                            type="submit"
                            disabled={!upload.data.file}
                            loading={upload.processing}
                            loadingText={t("imports.products.analyzing")}
                            className="mt-5"
                        >
                            {t("imports.products.analyze")}
                        </Button>
                    </form>
                    {recentImports.length > 0 && (
                        <section className="mt-8">
                            <h2 className="mb-3 font-semibold">
                                {t("imports.products.recent")}
                            </h2>
                            <div className="overflow-hidden rounded-xl border bg-white">
                                {recentImports.map((item) => (
                                    <Link
                                        key={item.id}
                                        href={`/catalog/products/import/${item.id}`}
                                        className="flex items-center justify-between border-b px-4 py-3 last:border-0 hover:bg-slate-50"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {item.original_file_name}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {item.created_by?.name} ·{" "}
                                                {item.created_at}
                                            </p>
                                        </div>
                                        <StatusBadge status={item.status} />
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}
                </>
            )}
            {productImport?.status === "uploaded" && (
                <form onSubmit={submitMapping} className="space-y-6">
                    <section className="rounded-2xl border bg-white p-6">
                        <h2 className="font-semibold">
                            {t("imports.products.mapColumns")}
                        </h2>
                        <p className="mt-1 text-sm text-slate-500">
                            {productImport.original_file_name} ·{" "}
                            {t("imports.products.detectedRows").replace(
                                "{count}",
                                String(productImport.total_rows),
                            )}
                        </p>
                        <div className="mt-5 overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="py-3">
                                            {t("imports.products.fileColumn")}
                                        </th>
                                        <th className="py-3">
                                            {t("imports.products.erpField")}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {productImport.headers.map(
                                        (header, index) => (
                                            <tr
                                                key={index}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-3 font-medium">
                                                    {header}
                                                </td>
                                                <td className="py-3">
                                                    <select
                                                        value={fieldForColumn(
                                                            index,
                                                        )}
                                                        onChange={(event) =>
                                                            assignColumn(
                                                                index,
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        className="w-full max-w-sm rounded-lg border px-3 py-2"
                                                    >
                                                        <option value="">
                                                            {t(
                                                                "imports.products.doNotImport",
                                                            )}
                                                        </option>
                                                        {Object.entries(
                                                            fields,
                                                        ).map(
                                                            ([
                                                                key,
                                                                definition,
                                                            ]) => (
                                                                <option
                                                                    key={key}
                                                                    value={key}
                                                                >
                                                                    {
                                                                        definition.label
                                                                    }
                                                                    {definition.required
                                                                        ? " *"
                                                                        : ""}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {Object.entries(mapping.errors).map(([key, error]) => (
                            <p key={key} className="mt-2 text-sm text-red-600">
                                {error}
                            </p>
                        ))}
                    </section>
                    <section className="rounded-2xl border bg-white p-6">
                        <h2 className="font-semibold">
                            {t("imports.products.defaults")}
                        </h2>
                        <p className="mt-1 text-sm text-slate-500">
                            {t("imports.products.defaultsHelp")}
                        </p>
                        <div className="mt-4 grid gap-4 md:grid-cols-4">
                            {(
                                [
                                    [
                                        "category_id",
                                        t("products.category"),
                                        lookups.categories,
                                    ],
                                    [
                                        "brand_id",
                                        t("products.brand"),
                                        lookups.brands,
                                    ],
                                    [
                                        "unit_id",
                                        t("products.unit"),
                                        lookups.units,
                                    ],
                                    [
                                        "tax_rate_id",
                                        t("products.tax"),
                                        lookups.taxRates,
                                    ],
                                ] as const
                            ).map(([key, label, options]) => (
                                <label key={key} className="text-sm">
                                    {label}
                                    <select
                                        value={mapping.data.defaults[key] ?? ""}
                                        onChange={(event) =>
                                            mapping.setData("defaults", {
                                                ...mapping.data.defaults,
                                                [key]: selectId(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                        className="mt-1 w-full rounded-lg border px-3 py-2"
                                    >
                                        <option value="">
                                            {t("common.none")}
                                        </option>
                                        {options.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.name}
                                                {option.symbol
                                                    ? ` (${option.symbol})`
                                                    : ""}
                                                {option.rate
                                                    ? ` (${option.rate}%)`
                                                    : ""}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ))}
                        </div>
                    </section>
                    {quantityMapped && (
                        <section className="rounded-2xl border border-blue-200 bg-blue-50 p-6">
                            <h2 className="font-semibold text-blue-950">
                                {t("imports.products.stockConfiguration")}
                            </h2>
                            <p className="mt-1 text-sm text-blue-800">
                                {t("imports.products.stockHelp")}
                            </p>
                            <div className="mt-4 space-y-3">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        checked={
                                            mapping.data.defaults.stock_mode ===
                                            "skip"
                                        }
                                        onChange={() =>
                                            mapping.setData("defaults", {
                                                ...mapping.data.defaults,
                                                stock_mode: "skip",
                                                warehouse_id: null,
                                            })
                                        }
                                    />
                                    {t("imports.products.skipStock")}
                                </label>
                                {canImportStock && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            checked={
                                                mapping.data.defaults
                                                    .stock_mode === "import"
                                            }
                                            onChange={() =>
                                                mapping.setData("defaults", {
                                                    ...mapping.data.defaults,
                                                    stock_mode: "import",
                                                })
                                            }
                                        />
                                        {t(
                                            "imports.products.importInitialStock",
                                        )}
                                    </label>
                                )}
                                {mapping.data.defaults.stock_mode ===
                                    "import" && (
                                    <label className="block max-w-sm text-sm font-medium">
                                        {t("imports.products.location")}
                                        <select
                                            value={
                                                mapping.data.defaults
                                                    .warehouse_id ?? ""
                                            }
                                            onChange={(event) =>
                                                mapping.setData("defaults", {
                                                    ...mapping.data.defaults,
                                                    warehouse_id: selectId(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                            className="mt-1 w-full rounded-lg border px-3 py-2"
                                        >
                                            <option value="">
                                                {t(
                                                    "imports.products.selectLocation",
                                                )}
                                            </option>
                                            {lookups.warehouses.map(
                                                (warehouse) => (
                                                    <option
                                                        key={warehouse.id}
                                                        value={warehouse.id}
                                                    >
                                                        {warehouse.name} ·{" "}
                                                        {warehouse.code}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                )}
                                {!canImportStock && (
                                    <p className="text-sm text-blue-800">
                                        {t(
                                            "imports.products.noStockPermission",
                                        )}
                                    </p>
                                )}
                                {mapping.errors["defaults.warehouse_id"] && (
                                    <p className="text-sm text-red-600">
                                        {
                                            mapping.errors[
                                                "defaults.warehouse_id"
                                            ]
                                        }
                                    </p>
                                )}
                            </div>
                        </section>
                    )}
                    <Button
                        type="submit"
                        loading={mapping.processing}
                        loadingText={t("imports.products.verifying")}
                    >
                        {t("imports.products.verify")}
                    </Button>
                </form>
            )}
            {productImport?.status === "previewed" && (
                <>
                    <section className="grid gap-4 sm:grid-cols-3">
                        <div className="rounded-xl bg-emerald-50 p-5">
                            <p className="text-sm text-emerald-700">
                                {t("imports.products.ready")}
                            </p>
                            <p className="mt-1 text-3xl font-semibold">
                                {productImport.ready_count}
                            </p>
                        </div>
                        <div className="rounded-xl bg-amber-50 p-5">
                            <p className="text-sm text-amber-700">
                                {t("imports.products.warnings")}
                            </p>
                            <p className="mt-1 text-3xl font-semibold">
                                {productImport.warning_count}
                            </p>
                        </div>
                        <div className="rounded-xl bg-red-50 p-5">
                            <p className="text-sm text-red-700">
                                {t("imports.products.errors")}
                            </p>
                            <p className="mt-1 text-3xl font-semibold">
                                {productImport.error_count}
                            </p>
                        </div>
                    </section>
                    {productImport.stock_detected && (
                        <div className="mt-5 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                            {t("imports.products.stockDetected")} ·{" "}
                            {productImport.defaults?.stock_mode === "import"
                                ? t("imports.products.stockPlanned").replace(
                                      "{location}",
                                      lookups.warehouses.find(
                                          (item) =>
                                              item.id ===
                                              productImport.defaults
                                                  ?.warehouse_id,
                                      )?.name ??
                                          t(
                                              "imports.products.selectedLocation",
                                          ),
                                  )
                                : t("imports.products.noQuantityImported")}
                            .
                        </div>
                    )}
                    <div className="mt-6 overflow-x-auto rounded-xl border bg-white">
                        <table className="w-full min-w-[1050px] text-start text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="p-3">
                                        {t("imports.products.row")}
                                    </th>
                                    <th className="p-3">
                                        {t("imports.products.productVariant")}
                                    </th>
                                    <th className="p-3">SKU</th>
                                    <th className="p-3">
                                        {t("imports.products.regularPrice")}
                                    </th>
                                    <th className="p-3">
                                        {t("imports.products.promoPrice")}
                                    </th>
                                    <th className="p-3">
                                        {t("imports.products.stockLocation")}
                                    </th>
                                    <th className="p-3">
                                        {t("products.brand")}
                                    </th>
                                    <th className="p-3">
                                        {t("imports.products.imageUrl")}
                                    </th>
                                    <th className="p-3">
                                        {t("common.status")}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3">
                                            {row.row_number}
                                        </td>
                                        <td className="p-3 font-medium">
                                            {row.normalized_data?.name || "—"}
                                            <p className="text-xs font-normal text-slate-500">
                                                {row.normalized_data
                                                    ?.variant_label ||
                                                    t(
                                                        "imports.products.mainVariant",
                                                    )}
                                            </p>
                                            {row.messages?.map(
                                                (message, index) => (
                                                    <p
                                                        key={index}
                                                        className="mt-1 max-w-lg text-xs font-normal text-slate-500"
                                                    >
                                                        {message}
                                                    </p>
                                                ),
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {row.normalized_data?.sku || "—"}
                                        </td>
                                        <td className="p-3">
                                            {row.normalized_data?.sale_price ||
                                                "—"}
                                        </td>
                                        <td className="p-3">
                                            {row.normalized_data?.promo_price ||
                                                "—"}
                                        </td>
                                        <td className="p-3">
                                            {row.normalized_data
                                                ?.stock_quantity || "—"}
                                            <p className="text-xs text-slate-500">
                                                {productImport.defaults
                                                    ?.stock_mode === "import"
                                                    ? lookups.warehouses.find(
                                                          (item) =>
                                                              item.id ===
                                                              productImport
                                                                  .defaults
                                                                  ?.warehouse_id,
                                                      )?.name
                                                    : t(
                                                          "imports.products.notImported",
                                                      )}
                                            </p>
                                        </td>
                                        <td className="p-3">
                                            {row.normalized_data?.brand || "—"}
                                        </td>
                                        <td className="p-3">
                                            {row.normalized_data?.image_url ? (
                                                <a
                                                    href={
                                                        row.normalized_data
                                                            .image_url
                                                    }
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="block max-w-48 truncate text-blue-700"
                                                >
                                                    {
                                                        row.normalized_data
                                                            .image_url
                                                    }
                                                </a>
                                            ) : (
                                                "—"
                                            )}
                                        </td>
                                        <td className="p-3">
                                            <StatusBadge status={row.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-6 flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            onClick={confirm}
                            disabled={prospectiveCount === 0}
                            loading={confirmation.processing}
                            loadingText={t("imports.products.importing")}
                        >
                            {t("imports.products.importCount").replace(
                                "{count}",
                                String(prospectiveCount),
                            )}
                        </Button>
                        <span className="text-sm text-slate-500">
                            {t("imports.products.errorRowsSkipped")}
                        </span>
                    </div>
                </>
            )}
            {productImport?.status === "processing" && (
                <section className="rounded-2xl border bg-white p-8 text-center">
                    <h2 className="text-xl font-semibold">
                        {t("imports.products.processing")}
                    </h2>
                    <p className="mt-2 text-sm text-slate-600">
                        {t("imports.products.processingHelp")}
                    </p>
                </section>
            )}
            {productImport && isFinal && (
                <section className="rounded-2xl border bg-white p-8">
                    <div className="mx-auto max-w-5xl">
                        <p
                            className={`text-center text-sm font-medium ${productImport.status === "completed" ? "text-emerald-700" : "text-amber-700"}`}
                        >
                            {productImport.status === "failed"
                                ? t("imports.products.impossible")
                                : productImport.status ===
                                    "completed_with_errors"
                                  ? t("imports.products.completedWithErrors")
                                  : t("imports.products.completed")}
                        </p>
                        <h2 className="mt-2 text-center text-2xl font-semibold">
                            {t("imports.products.importedCount").replace(
                                "{count}",
                                String(productImport.created_product_count),
                            )}
                        </h2>
                        <dl className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="rounded-lg bg-emerald-50 p-3">
                                <dt className="text-sm text-emerald-700">
                                    {t("imports.products.imported")}
                                </dt>
                                <dd className="text-xl font-semibold">
                                    {productImport.created_product_count}
                                </dd>
                            </div>
                            <div className="rounded-lg bg-slate-50 p-3">
                                <dt className="text-sm text-slate-500">
                                    {t("imports.products.skipped")}
                                </dt>
                                <dd className="text-xl font-semibold">
                                    {productImport.skipped_count}
                                </dd>
                            </div>
                            <div className="rounded-lg bg-red-50 p-3">
                                <dt className="text-sm text-red-700">
                                    {t("imports.products.failed")}
                                </dt>
                                <dd className="text-xl font-semibold">
                                    {productImport.failed_count}
                                </dd>
                            </div>
                            <div className="rounded-lg bg-amber-50 p-3">
                                <dt className="text-sm text-amber-700">
                                    {t("imports.products.warnings")}
                                </dt>
                                <dd className="text-xl font-semibold">
                                    {productImport.warning_count}
                                </dd>
                            </div>
                        </dl>
                        {resultRows.length > 0 && (
                            <div className="mt-8">
                                <h3 className="font-semibold">
                                    {t("imports.products.details")}
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">
                                    {t("imports.products.detailsHelp")}
                                </p>
                                <div className="mt-4 overflow-x-auto rounded-xl border">
                                    <table className="w-full min-w-[760px] text-start text-sm">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="p-3">
                                                    {t("imports.products.row")}
                                                </th>
                                                <th className="p-3">
                                                    {t("products.product")}
                                                </th>
                                                <th className="p-3">SKU</th>
                                                <th className="p-3">
                                                    {t("common.status")}
                                                </th>
                                                <th className="p-3">
                                                    {t(
                                                        "imports.products.errorWarning",
                                                    )}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {resultRows.map((row) => {
                                                const detail =
                                                    row.error_message ??
                                                    row.messages?.[0] ??
                                                    "—";
                                                return (
                                                    <tr
                                                        key={row.id}
                                                        className="border-t align-top"
                                                    >
                                                        <td className="p-3">
                                                            {row.row_number}
                                                        </td>
                                                        <td className="p-3 font-medium">
                                                            {row.normalized_data
                                                                ?.name || "—"}
                                                        </td>
                                                        <td className="p-3">
                                                            {row.normalized_data
                                                                ?.sku || "—"}
                                                        </td>
                                                        <td className="p-3">
                                                            <StatusBadge
                                                                status={
                                                                    row.status
                                                                }
                                                            />
                                                            <p className="mt-1 text-xs text-slate-500">
                                                                {row.error_phase ===
                                                                "preview"
                                                                    ? t(
                                                                          "imports.products.preview",
                                                                      )
                                                                    : row.error_phase ===
                                                                        "runtime"
                                                                      ? t(
                                                                            "imports.products.import",
                                                                        )
                                                                      : ""}
                                                            </p>
                                                        </td>
                                                        <td className="max-w-xl p-3">
                                                            <p>{detail}</p>
                                                            {row.error_code ===
                                                                "technical_error" && (
                                                                <p className="mt-1 text-xs text-slate-500">
                                                                    {t(
                                                                        "common.reference",
                                                                    )}{" "}
                                                                    : Import #
                                                                    {
                                                                        productImport.id
                                                                    }{" "}
                                                                    /{" "}
                                                                    {t(
                                                                        "imports.products.row",
                                                                    ).toLowerCase()}{" "}
                                                                    {
                                                                        row.row_number
                                                                    }
                                                                </p>
                                                            )}
                                                            {(row.messages
                                                                ?.length ?? 0) >
                                                                1 && (
                                                                <details className="mt-2">
                                                                    <summary className="cursor-pointer text-xs font-medium text-slate-600">
                                                                        {t(
                                                                            "imports.products.showDetails",
                                                                        )}
                                                                    </summary>
                                                                    <ul className="mt-2 space-y-1 text-xs text-slate-600">
                                                                        {row.messages?.map(
                                                                            (
                                                                                message,
                                                                                index,
                                                                            ) => (
                                                                                <li
                                                                                    key={
                                                                                        index
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        message
                                                                                    }
                                                                                </li>
                                                                            ),
                                                                        )}
                                                                    </ul>
                                                                </details>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                        <div className="mt-6 grid gap-3 sm:grid-cols-3">
                            <div className="rounded-lg bg-slate-50 p-3">
                                <p className="text-sm text-slate-500">
                                    {t("imports.products.createdVariants")}
                                </p>
                                <p className="font-semibold">
                                    {productImport.created_variant_count}
                                </p>
                            </div>
                            <div className="rounded-lg bg-slate-50 p-3">
                                <p className="text-sm text-slate-500">
                                    {t("imports.products.imageUrlsSummary")}
                                </p>
                                <p className="font-semibold">
                                    {productImport.linked_image_url_count} /{" "}
                                    {
                                        productImport.invalid_or_missing_image_url_count
                                    }
                                </p>
                            </div>
                            <div className="rounded-lg bg-slate-50 p-3">
                                <p className="text-sm text-slate-500">
                                    {t("imports.products.initializedStocks")}
                                </p>
                                <p className="font-semibold">
                                    {productImport.initialized_stock_count}
                                </p>
                            </div>
                        </div>
                        <div className="mt-6 flex flex-wrap justify-center gap-2">
                            <ButtonLink href="/catalog/products">
                                {t("imports.products.viewProducts")}
                            </ButtonLink>
                            <ButtonLink
                                href="/catalog/products/import"
                                variant="secondary"
                            >
                                {t("imports.products.importAnother")}
                            </ButtonLink>
                            <ButtonLink
                                href="/inventory/stock"
                                variant="secondary"
                            >
                                {t("imports.products.viewStock")}
                            </ButtonLink>
                        </div>
                    </div>
                </section>
            )}
        </CatalogLayout>
    );
}
