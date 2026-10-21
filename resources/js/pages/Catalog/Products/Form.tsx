import { Button, ButtonLink } from "@/components/ui/Button";
import FormField from "@/components/ui/FormField";
import PageHeader from "@/components/ui/PageHeader";
import CatalogLayout from "@/layouts/CatalogLayout";
import { useI18n } from "@/i18n";
import { Head, useForm } from "@inertiajs/react";
import type { FormEvent } from "react";

type Option = {
    id: number;
    name: string;
    symbol?: string;
    rate?: string;
    is_default?: boolean;
};
type Product = {
    id: number;
    name: string;
    description: string | null;
    image_url: string | null;
    brand_id: number | null;
    default_category_id: number | null;
    default_unit_id: number | null;
    status: string;
};
type Props = {
    product: Product | null;
    brands: Option[];
    categories: Option[];
    units: Option[];
    taxRates: Option[];
    canManageCost: boolean;
};
type ProductFormData = {
    name: string;
    description: string;
    image_url: string;
    brand_id: number | null;
    category_id: number | null;
    unit_id: number | null;
    status: string;
    variant: {
        label: string;
        sku: string;
        reference: string;
        barcode: string;
        purchase_price?: string;
        public_price_ttc: string;
        unit_price_ht: string;
        tax_rate_id: number | null;
        status: string;
    };
};

export default function ProductForm({
    product,
    brands,
    categories,
    units,
    taxRates,
    canManageCost,
}: Props) {
    const { t } = useI18n();
    const defaultTaxId = taxRates.find((rate) => rate.is_default)?.id ?? null;
    const form = useForm<ProductFormData>({
        name: product?.name ?? "",
        description: product?.description ?? "",
        image_url: product?.image_url ?? "",
        brand_id: product?.brand_id ?? null,
        category_id: product?.default_category_id ?? null,
        unit_id: product?.default_unit_id ?? null,
        status: product?.status ?? "active",
        variant: {
            label: "",
            sku: "",
            reference: "",
            barcode: "",
            ...(canManageCost ? { purchase_price: "" } : {}),
            public_price_ttc: "",
            unit_price_ht: "",
            tax_rate_id: defaultTaxId as number | null,
            status: "active",
        },
    });
    const creating = product === null;
    const errors = form.errors as Record<string, string>;
    const selectedRate = Number(
        taxRates.find((rate) => rate.id === form.data.variant.tax_rate_id)
            ?.rate ?? "0",
    );
    const ttcNum = Number(form.data.variant.public_price_ttc || "");
    const derivedHt =
        Number.isFinite(ttcNum) && ttcNum > 0 && Number.isFinite(selectedRate)
            ? (ttcNum / (1 + selectedRate / 100)).toFixed(2)
            : null;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        creating
            ? form.post("/catalog/products")
            : form.patch(`/catalog/products/${product.id}`);
    };
    const selectId = (value: string) => (value ? Number(value) : null);

    return (
        <CatalogLayout>
            <Head
                title={
                    creating
                        ? t("products.add")
                        : `${t("common.edit")} ${product.name}`
                }
            />
            <PageHeader
                title={creating ? t("products.add") : t("products.form.edit")}
                description={
                    creating
                        ? t("products.form.createDescription")
                        : product.name
                }
                actions={
                    <ButtonLink
                        href={
                            creating
                                ? "/catalog/products"
                                : `/catalog/products/${product.id}`
                        }
                        variant="secondary"
                    >
                        {t("common.cancel")}
                    </ButtonLink>
                }
            />
            <form onSubmit={submit} className="max-w-4xl space-y-6">
                <section className="rounded-2xl border bg-white p-6">
                    <h2 className="font-semibold">
                        {t("products.form.essentialInfo")}
                    </h2>
                    <div className="mt-5 grid gap-5 md:grid-cols-2">
                        <div className="md:col-span-2">
                            <FormField
                                label={t("products.form.name")}
                                required
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData("name", event.target.value)
                                }
                                error={errors.name}
                            />
                        </div>
                        <div className="md:col-span-2">
                            <FormField
                                label={t("products.form.imageUrl")}
                                type="url"
                                value={form.data.image_url}
                                onChange={(event) =>
                                    form.setData(
                                        "image_url",
                                        event.target.value,
                                    )
                                }
                                error={errors.image_url}
                            />
                        </div>
                        {creating && (
                            <>
                                <FormField
                                    label={t("common.reference")}
                                    value={form.data.variant.reference}
                                    onChange={(event) =>
                                        form.setData("variant", {
                                            ...form.data.variant,
                                            reference: event.target.value,
                                        })
                                    }
                                    error={errors["variant.reference"]}
                                />
                                <FormField
                                    label="SKU *"
                                    required
                                    value={form.data.variant.sku}
                                    onChange={(event) =>
                                        form.setData("variant", {
                                            ...form.data.variant,
                                            sku: event.target.value,
                                        })
                                    }
                                    error={errors["variant.sku"]}
                                />
                                <FormField
                                    label={t("products.form.barcode")}
                                    value={form.data.variant.barcode}
                                    onChange={(event) =>
                                        form.setData("variant", {
                                            ...form.data.variant,
                                            barcode: event.target.value,
                                        })
                                    }
                                    error={errors["variant.barcode"]}
                                />
                                {canManageCost && (
                                    <FormField
                                        label={t("products.form.purchasePrice")}
                                        inputMode="decimal"
                                        value={
                                            form.data.variant.purchase_price ??
                                            ""
                                        }
                                        onChange={(event) =>
                                            form.setData("variant", {
                                                ...form.data.variant,
                                                purchase_price:
                                                    event.target.value,
                                            })
                                        }
                                        error={errors["variant.purchase_price"]}
                                    />
                                )}
                                <div>
                                    <FormField
                                        label={t("products.form.publicPrice")}
                                        required
                                        inputMode="decimal"
                                        placeholder="1 200,00"
                                        value={
                                            form.data.variant.public_price_ttc
                                        }
                                        onChange={(event) =>
                                            form.setData("variant", {
                                                ...form.data.variant,
                                                public_price_ttc:
                                                    event.target.value,
                                            })
                                        }
                                        error={
                                            errors["variant.public_price_ttc"]
                                        }
                                    />
                                    <p className="mt-1 text-xs text-slate-500">
                                        {t("products.form.publicPriceHelp")}
                                    </p>
                                </div>
                                <label className="text-sm font-medium text-slate-800">
                                    {t("products.tax")}
                                    <select
                                        value={
                                            form.data.variant.tax_rate_id ?? ""
                                        }
                                        onChange={(event) =>
                                            form.setData("variant", {
                                                ...form.data.variant,
                                                tax_rate_id: event.target.value
                                                    ? Number(event.target.value)
                                                    : null,
                                            })
                                        }
                                        className="mt-1 w-full rounded-lg border px-3 py-2"
                                    >
                                        <option value="">
                                            {t("products.form.noTax")}
                                        </option>
                                        {taxRates.map((item) => (
                                            <option
                                                key={item.id}
                                                value={item.id}
                                            >
                                                {item.name} ({item.rate}%)
                                                {item.is_default
                                                    ? ` — ${t("common.default")}`
                                                    : ""}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <div className="md:col-span-2">
                                    <FormField
                                        label={t(
                                            "products.form.optionalNetPrice",
                                        )}
                                        inputMode="decimal"
                                        placeholder={
                                            derivedHt
                                                ? `${derivedHt} (${t("products.form.calculated")})`
                                                : ""
                                        }
                                        value={form.data.variant.unit_price_ht}
                                        onChange={(event) =>
                                            form.setData("variant", {
                                                ...form.data.variant,
                                                unit_price_ht:
                                                    event.target.value,
                                            })
                                        }
                                        error={errors["variant.unit_price_ht"]}
                                    />
                                    <p className="mt-1 text-xs text-slate-500">
                                        {t("products.form.netPriceHelp")}
                                        {derivedHt ? ` : ${derivedHt} DH` : ""}.
                                    </p>
                                </div>
                            </>
                        )}
                    </div>
                </section>
                <section className="rounded-2xl border bg-white p-6">
                    <h2 className="font-semibold">
                        {t("products.form.classification")}
                    </h2>
                    <div className="mt-5 grid gap-5 md:grid-cols-2">
                        <label className="text-sm font-medium">
                            {t("products.category")}
                            <select
                                value={form.data.category_id ?? ""}
                                onChange={(event) =>
                                    form.setData(
                                        "category_id",
                                        selectId(event.target.value),
                                    )
                                }
                                className="mt-1 w-full rounded-lg border px-3 py-2"
                            >
                                <option value="">{t("common.none")}</option>
                                {categories.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            {t("products.brand")}
                            <select
                                value={form.data.brand_id ?? ""}
                                onChange={(event) =>
                                    form.setData(
                                        "brand_id",
                                        selectId(event.target.value),
                                    )
                                }
                                className="mt-1 w-full rounded-lg border px-3 py-2"
                            >
                                <option value="">{t("common.none")}</option>
                                {brands.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            {t("products.unit")}
                            <select
                                value={form.data.unit_id ?? ""}
                                onChange={(event) =>
                                    form.setData(
                                        "unit_id",
                                        selectId(event.target.value),
                                    )
                                }
                                className="mt-1 w-full rounded-lg border px-3 py-2"
                            >
                                <option value="">{t("common.none")}</option>
                                {units.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name} ({item.symbol})
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                </section>
                <section className="rounded-2xl border bg-white p-6">
                    <h2 className="font-semibold">
                        {t("products.form.optionalInfo")}
                    </h2>
                    <label className="mt-5 block text-sm font-medium">
                        {t("common.description")}
                        <textarea
                            rows={4}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData("description", event.target.value)
                            }
                            className="mt-1 w-full rounded-lg border px-3 py-2"
                        />
                    </label>
                    <details className="mt-5">
                        <summary className="cursor-pointer text-sm font-medium">
                            {t("products.form.advancedOptions")}
                        </summary>
                        <label className="mt-4 block max-w-xs text-sm">
                            {t("common.status")}
                            <select
                                value={form.data.status}
                                onChange={(event) =>
                                    form.setData("status", event.target.value)
                                }
                                className="mt-1 w-full rounded-lg border px-3 py-2"
                            >
                                <option value="active">
                                    {t("status.active")}
                                </option>
                                <option value="inactive">
                                    {t("status.inactive")}
                                </option>
                            </select>
                        </label>
                    </details>
                </section>
                {Object.keys(form.errors).length > 0 && (
                    <p className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
                        {t("products.form.validationError")}
                    </p>
                )}
                <div className="flex items-center gap-3">
                    <Button
                        type="submit"
                        loading={form.processing}
                        loadingText={t("common.saving")}
                    >
                        {t("products.form.save")}
                    </Button>
                    <p className="text-sm text-slate-500">
                        {t("products.form.stockSeparate")}
                    </p>
                </div>
            </form>
        </CatalogLayout>
    );
}
