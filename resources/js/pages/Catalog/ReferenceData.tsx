import { Button } from "@/components/ui/Button";
import PageHeader from "@/components/ui/PageHeader";
import { useI18n } from "@/i18n";
import CatalogLayout from "@/layouts/CatalogLayout";
import { Head, router, useForm } from "@inertiajs/react";
import { FormEvent, useState } from "react";

type RecordItem = {
    id: number;
    name: string;
    slug?: string;
    symbol?: string;
    rate?: string;
    is_default?: boolean;
    parent_id?: number | null;
    status: string;
    parent?: { name: string } | null;
    variants_count?: number;
    store_default_count?: number;
};
type StoreRow = {
    id: number;
    name: string;
    code: string;
    default_tax_rate_id: number | null;
};
type Props = {
    kind: "brands" | "categories" | "units" | "tax-rates";
    title: string;
    records: RecordItem[];
    parents?: { id: number; name: string }[];
    stores?: StoreRow[];
};

const fieldClass =
    "mt-1.5 h-10 w-full rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none transition-soft focus:border-primary";
const labelClass = "block text-[13px] font-medium text-ink";

export default function ReferenceData({
    kind,
    title,
    records,
    parents = [],
    stores = [],
}: Props) {
    const { t } = useI18n();
    const translatedTitle = t(
        `referenceData.kind.${kind}` as Parameters<typeof t>[0],
    );
    const singular = t(
        `referenceData.singular.${kind}` as Parameters<typeof t>[0],
    );
    const [editing, setEditing] = useState<number | null>(null);
    const isTaxRates = kind === "tax-rates";
    const form = useForm({
        name: "",
        slug: "",
        symbol: "",
        rate: "",
        is_default: false,
        parent_id: null as number | null,
        status: "active",
    });

    function select(record: RecordItem) {
        setEditing(record.id);
        form.setData({
            name: record.name,
            slug: record.slug ?? "",
            symbol: record.symbol ?? "",
            rate: record.rate ?? "",
            is_default: record.is_default ?? false,
            parent_id: record.parent_id ?? null,
            status: record.status,
        });
    }
    function reset() {
        setEditing(null);
        form.reset();
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: reset };
        editing
            ? form.patch(`/catalog/${kind}/${editing}`, options)
            : form.post(`/catalog/${kind}`, options);
    }

    const deactivating =
        form.data.status === "inactive" &&
        (!editing ||
            records.find((r) => r.id === editing)?.status === "active");

    return (
        <CatalogLayout>
            <Head title={translatedTitle} />
            <PageHeader
                title={translatedTitle}
                description={
                    isTaxRates
                        ? t("referenceData.taxDescription")
                        : t("referenceData.description").replace(
                              "{title}",
                              translatedTitle.toLowerCase(),
                          )
                }
            />

            <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
                <section className="overflow-x-auto rounded-card border border-line bg-surface">
                    <table className="w-full min-w-[520px] text-start text-sm">
                        <thead className="bg-raised text-[11px] uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th className="px-4 py-2.5">
                                    {t("common.name")}
                                </th>
                                <th className="px-4 py-2.5">
                                    {isTaxRates
                                        ? t("referenceData.rate")
                                        : t("referenceData.details")}
                                </th>
                                {isTaxRates && (
                                    <th className="px-4 py-2.5">
                                        {t("referenceData.usageScope")}
                                    </th>
                                )}
                                <th className="px-4 py-2.5">
                                    {t("common.status")}
                                </th>
                                <th className="px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {records.map((record) => (
                                <tr
                                    key={record.id}
                                    className="border-t border-line align-top"
                                >
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-ink">
                                            {record.name}
                                        </p>
                                        {record.is_default && (
                                            <span className="mt-1 inline-flex rounded-full bg-sage px-2 py-0.5 text-[11px] font-semibold text-ink">
                                                {t("referenceData.default")}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-ink-muted">
                                        {isTaxRates
                                            ? `${Number(record.rate ?? 0).toLocaleString("fr-FR", { minimumFractionDigits: 0, maximumFractionDigits: 4 })} %`
                                            : (record.parent?.name ??
                                              record.symbol ??
                                              record.slug ??
                                              "—")}
                                    </td>
                                    {isTaxRates && (
                                        <td className="px-4 py-3 text-[13px] text-ink-muted">
                                            {t(
                                                "referenceData.productsCount",
                                            ).replace(
                                                "{count}",
                                                String(
                                                    record.variants_count ?? 0,
                                                ),
                                            )}
                                            {stores.length > 0 &&
                                                (record.store_default_count ??
                                                    0) > 0 && (
                                                    <>
                                                        {" "}
                                                        ·{" "}
                                                        {
                                                            record.store_default_count
                                                        }{" "}
                                                        {t(
                                                            "referenceData.defaultStores",
                                                        )}
                                                    </>
                                                )}
                                        </td>
                                    )}
                                    <td className="px-4 py-3">
                                        <span
                                            className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${record.status === "active" ? "bg-success-soft text-success" : "bg-raised text-ink-muted"}`}
                                        >
                                            {record.status === "active"
                                                ? t("status.active")
                                                : t("status.inactive")}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            onClick={() => select(record)}
                                            className="text-[13px] font-medium text-ink-muted underline transition-soft hover:text-ink"
                                        >
                                            {t("common.edit")}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {records.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={isTaxRates ? 4 : 3}
                                        className="px-4 py-8 text-center text-ink-faint"
                                    >
                                        {t("referenceData.empty")}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>

                <form
                    onSubmit={submit}
                    className="h-fit space-y-3.5 rounded-card border border-line bg-surface p-5"
                >
                    <h3 className="text-sm font-semibold text-ink">
                        {editing
                            ? `${t("common.edit")} ${singular}`
                            : `${t("common.add")} ${singular}`}
                    </h3>

                    <label className={labelClass}>
                        {t("common.name")}
                        <input
                            className={fieldClass}
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData("name", e.target.value)
                            }
                            required
                        />
                    </label>

                    {(kind === "brands" || kind === "categories") && (
                        <label className={labelClass}>
                            {t("referenceData.slug")}
                            <input
                                className={fieldClass}
                                value={form.data.slug}
                                onChange={(e) =>
                                    form.setData("slug", e.target.value)
                                }
                                required
                            />
                        </label>
                    )}

                    {kind === "categories" && (
                        <label className={labelClass}>
                            {t("referenceData.parentCategory")}
                            <select
                                className={fieldClass}
                                value={form.data.parent_id ?? ""}
                                onChange={(e) =>
                                    form.setData(
                                        "parent_id",
                                        e.target.value
                                            ? Number(e.target.value)
                                            : null,
                                    )
                                }
                            >
                                <option value="">{t("common.none")}</option>
                                {parents
                                    .filter((x) => x.id !== editing)
                                    .map((x) => (
                                        <option key={x.id} value={x.id}>
                                            {x.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
                    )}

                    {kind === "units" && (
                        <label className={labelClass}>
                            {t("referenceData.symbol")}
                            <input
                                className={fieldClass}
                                value={form.data.symbol}
                                onChange={(e) =>
                                    form.setData("symbol", e.target.value)
                                }
                                required
                            />
                        </label>
                    )}

                    {isTaxRates && (
                        <>
                            <label className={labelClass}>
                                {t("referenceData.ratePercent")}
                                <input
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.0001"
                                    className={fieldClass}
                                    value={form.data.rate}
                                    onChange={(e) =>
                                        form.setData("rate", e.target.value)
                                    }
                                    required
                                />
                            </label>
                            <label className="flex items-center gap-2 text-[13px] text-ink">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_default}
                                    onChange={(e) =>
                                        form.setData(
                                            "is_default",
                                            e.target.checked,
                                        )
                                    }
                                    className="size-4 rounded border-line-strong text-primary focus:ring-primary"
                                />
                                {t("referenceData.defaultNewProductsTax")}
                            </label>
                        </>
                    )}

                    <label className={labelClass}>
                        {t("common.status")}
                        <select
                            className={fieldClass}
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData("status", e.target.value)
                            }
                        >
                            <option value="active">{t("status.active")}</option>
                            <option value="inactive">
                                {t("status.inactive")}
                            </option>
                        </select>
                    </label>

                    {deactivating && (
                        <p className="rounded-field border border-warning/30 bg-warning-soft px-3 py-2 text-[12px] text-warning">
                            {t("referenceData.deactivationHelp")}
                        </p>
                    )}

                    {Object.keys(form.errors).length > 0 && (
                        <p className="text-[13px] text-danger">
                            {t("referenceData.correctErrors")}
                        </p>
                    )}

                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            loading={form.processing}
                            loadingText={t("common.saving")}
                        >
                            {t("common.save")}
                        </Button>
                        {editing && (
                            <button
                                type="button"
                                onClick={reset}
                                className="inline-flex min-h-10 items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink transition-soft hover:bg-raised"
                            >
                                {t("common.cancel")}
                            </button>
                        )}
                    </div>
                </form>
            </div>

            {isTaxRates && stores.length > 0 && (
                <section className="mt-8 max-w-2xl">
                    <h2 className="text-base font-semibold text-ink">
                        {t("referenceData.storeDefaultTax")}
                    </h2>
                    <p className="mt-1 text-[13px] text-ink-muted">
                        {t("referenceData.storeDefaultTaxHelp")}
                    </p>
                    <div className="mt-4 divide-y divide-line rounded-card border border-line bg-surface">
                        {stores.map((store) => (
                            <div
                                key={store.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div>
                                    <p className="text-sm font-medium text-ink">
                                        {store.name}
                                    </p>
                                    <p className="text-[12px] text-ink-faint">
                                        {store.code}
                                    </p>
                                </div>
                                <select
                                    value={store.default_tax_rate_id ?? ""}
                                    onChange={(e) =>
                                        router.patch(
                                            `/stores/${store.id}`,
                                            {
                                                name: store.name,
                                                code: store.code,
                                                default_tax_rate_id: e.target
                                                    .value
                                                    ? Number(e.target.value)
                                                    : null,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="h-10 min-w-56 rounded-field border border-line-strong bg-surface px-3 text-sm text-ink outline-none focus:border-primary"
                                >
                                    <option value="">
                                        {t("referenceData.useOrganizationTax")}
                                    </option>
                                    {records
                                        .filter(
                                            (rate) => rate.status === "active",
                                        )
                                        .map((rate) => (
                                            <option
                                                key={rate.id}
                                                value={rate.id}
                                            >
                                                {rate.name} ({rate.rate} %)
                                            </option>
                                        ))}
                                </select>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </CatalogLayout>
    );
}
