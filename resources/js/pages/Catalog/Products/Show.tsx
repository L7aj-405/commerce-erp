import ProductImage from "@/components/catalog/ProductImage";
import { Button, ButtonLink } from "@/components/ui/Button";
import PageHeader from "@/components/ui/PageHeader";
import StatusBadge from "@/components/ui/StatusBadge";
import CatalogLayout from "@/layouts/CatalogLayout";
import { useI18n } from "@/i18n";
import { formatMoney, formatQuantity } from "@/utils/format";
import { Head, router } from "@inertiajs/react";

type Variant = {
    id: number;
    label: string | null;
    sku: string | null;
    reference: string | null;
    barcode: string | null;
    image_url: string | null;
    regular_sale_price: string;
    promotional_sale_price: string | null;
    default_sale_price: string;
    status: string;
    tax_rate: { name: string; rate: string } | null;
};
type Product = {
    id: number;
    name: string;
    description: string | null;
    image_url: string | null;
    status: string;
    brand: { name: string } | null;
    default_category: { name: string } | null;
    default_unit: { name: string; symbol: string } | null;
    variants: Variant[];
};
type Stock = {
    variants: Array<{
        id: number;
        label: string | null;
        sku: string;
        available_total: string;
        warehouses: Array<{
            warehouse: { id: number; name: string; code: string };
            on_hand: string;
            reserved: string;
            available: string;
        }>;
    }>;
} | null;
type PriceInfo = {
    ttc: string | null;
    ht: string | null;
    ht_source: string;
    tax_name: string | null;
    tax_rate: string;
    config_missing: boolean;
};

function taxLabel(info: PriceInfo | undefined, notConfigured: string): string {
    if (!info) return "—";
    if (info.config_missing) return notConfigured;
    return info.tax_name
        ? `${info.tax_name} (${Number(info.tax_rate)}%)`
        : `${Number(info.tax_rate)}%`;
}

export default function ProductShow({
    product,
    stock,
    priceByVariant,
    can,
}: {
    product: Product;
    stock: Stock;
    priceByVariant: Record<string, PriceInfo>;
    can: {
        create: boolean;
        update: boolean;
        archive: boolean;
        stock: boolean;
        transfer: boolean;
    };
}) {
    const { t } = useI18n();
    const first = product.variants[0];
    const firstPrice = first ? priceByVariant[first.id] : undefined;
    return (
        <CatalogLayout>
            <Head title={product.name} />
            <PageHeader
                title={product.name}
                description={
                    product.description ?? t("products.show.catalogProduct")
                }
                actions={
                    <>
                        {can.create && (
                            <ButtonLink
                                href="/catalog/products/create"
                                variant="secondary"
                            >
                                {t("products.show.addAnother")}
                            </ButtonLink>
                        )}
                        {can.update && (
                            <ButtonLink
                                href={`/catalog/products/${product.id}/edit`}
                                variant="secondary"
                            >
                                {t("common.edit")}
                            </ButtonLink>
                        )}
                        {can.stock && (
                            <ButtonLink
                                href={`/inventory/stock?search=${encodeURIComponent(first?.sku ?? product.name)}`}
                            >
                                {t("products.show.viewStock")}
                            </ButtonLink>
                        )}
                    </>
                }
            />
            <section className="grid gap-6 rounded-2xl border bg-white p-6 lg:grid-cols-[minmax(260px,320px)_1fr]">
                <div className="flex items-center justify-center rounded-2xl bg-slate-50 p-4">
                    <ProductImage
                        name={product.name}
                        imageUrl={product.image_url}
                        className="h-64 w-full max-w-[18rem]"
                        textClassName="text-slate-400 text-4xl"
                    />
                </div>
                <div>
                    <div className="flex items-center gap-3">
                        <h2 className="text-lg font-semibold">
                            {t("products.show.identity")}
                        </h2>
                        <StatusBadge status={product.status} />
                    </div>
                    <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <dt className="text-slate-500">
                                {t("products.brand")}
                            </dt>
                            <dd className="font-medium">
                                {product.brand?.name ?? t("products.noBrand")}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">SKU</dt>
                            <dd className="font-medium">
                                {first?.sku ?? t("products.noSku")}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">
                                {t("common.reference")}
                            </dt>
                            <dd>
                                {first?.reference ?? t("products.noReference")}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">
                                {t("products.form.barcode")}
                            </dt>
                            <dd>{first?.barcode ?? "—"}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">
                                {t("products.category")}
                            </dt>
                            <dd>{product.default_category?.name ?? "—"}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">
                                {t("products.show.publicPrice")}
                            </dt>
                            <dd className="font-semibold">
                                {firstPrice?.ttc
                                    ? formatMoney(firstPrice.ttc)
                                    : "—"}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">
                                {t("products.show.netPrice")}
                            </dt>
                            <dd>
                                {firstPrice?.ht ? (
                                    <>
                                        {formatMoney(firstPrice.ht)}
                                        {firstPrice.ht_source === "derived" && (
                                            <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">
                                                {t("products.form.calculated")}
                                            </span>
                                        )}
                                    </>
                                ) : (
                                    "—"
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">TVA</dt>
                            <dd>
                                {taxLabel(
                                    firstPrice,
                                    t("common.notConfigured"),
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">
                                {t("products.show.availableStock")}
                            </dt>
                            <dd className="font-medium">
                                {stock?.variants?.[0]
                                    ? formatQuantity(
                                          stock.variants[0].available_total,
                                      )
                                    : "—"}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>
            {can.stock && stock && (
                <section className="mt-8 rounded-2xl border bg-white p-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">
                                {t("products.show.availability")}
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                {t("products.show.availabilityDescription")}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {can.update && (
                                <ButtonLink
                                    href={`/catalog/products/${product.id}/edit`}
                                    variant="secondary"
                                >
                                    {t("common.edit")}
                                </ButtonLink>
                            )}
                            {can.transfer && first && (
                                <ButtonLink
                                    href={`/inventory/transfers/create?variant_id=${first.id}`}
                                >
                                    {t("products.show.transfer")}
                                </ButtonLink>
                            )}
                            {can.stock && (
                                <ButtonLink
                                    href={`/inventory/stock?search=${encodeURIComponent(first?.sku ?? product.name)}`}
                                    variant="secondary"
                                >
                                    {t("products.show.adjustStock")}
                                </ButtonLink>
                            )}
                            {can.stock && (
                                <ButtonLink
                                    href={`/inventory/movements?search=${encodeURIComponent(first?.sku ?? product.name)}`}
                                    variant="secondary"
                                >
                                    {t("products.show.history")}
                                </ButtonLink>
                            )}
                        </div>
                    </div>
                    <div className="mt-5 space-y-6">
                        {stock.variants.map((variant) => (
                            <article
                                key={variant.id}
                                className="rounded-xl bg-slate-50 p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-semibold">
                                            {variant.label ??
                                                t("products.mainVariant")}
                                        </p>
                                        <p className="text-sm text-slate-500">
                                            {variant.sku ??
                                                t("products.show.noSkuShort")}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xs uppercase tracking-wide text-slate-400">
                                            {t("products.show.totalAvailable")}
                                        </p>
                                        <p className="text-lg font-semibold">
                                            {formatQuantity(
                                                variant.available_total,
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <div className="mt-4 space-y-2">
                                    {variant.warehouses.map((row) => (
                                        <div
                                            key={row.warehouse.id}
                                            className="flex justify-between gap-3 border-t border-slate-200 pt-2 text-sm"
                                        >
                                            <span>{row.warehouse.name}</span>
                                            <span className="font-medium">
                                                {formatQuantity(row.available)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                                {can.transfer && (
                                    <div className="mt-4">
                                        <ButtonLink
                                            href={`/inventory/transfers/create?variant_id=${variant.id}`}
                                            variant="secondary"
                                        >
                                            {t("products.show.transferVariant")}
                                        </ButtonLink>
                                    </div>
                                )}
                            </article>
                        ))}
                    </div>
                </section>
            )}
            <section className="mt-8">
                <h2 className="mb-3 text-lg font-semibold">
                    {t("products.show.variants")}
                </h2>
                <div className="overflow-x-auto rounded-xl border bg-white">
                    <table className="w-full min-w-[1000px] text-left text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="p-3">
                                    <span className="sr-only">
                                        {t("products.show.image")}
                                    </span>
                                </th>
                                <th className="p-3">{t("products.variant")}</th>
                                <th className="p-3">SKU</th>
                                <th className="p-3">{t("common.reference")}</th>
                                <th className="p-3">
                                    {t("products.form.barcode")}
                                </th>
                                <th className="p-3 text-right">
                                    {t("products.show.publicPrice")}
                                </th>
                                <th className="p-3 text-end">
                                    {t("products.show.netPrice")}
                                </th>
                                <th className="p-3">TVA</th>
                                <th className="p-3">{t("common.status")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {product.variants.map((variant) => {
                                const p = priceByVariant[variant.id];
                                return (
                                    <tr key={variant.id} className="border-t">
                                        <td className="p-3">
                                            <div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded-lg bg-slate-50">
                                                <ProductImage
                                                    name={
                                                        variant.label ??
                                                        product.name
                                                    }
                                                    imageUrl={
                                                        variant.image_url ??
                                                        product.image_url
                                                    }
                                                    className="h-full w-full"
                                                    roundedClassName="rounded-none"
                                                />
                                            </div>
                                        </td>
                                        <td className="p-3">
                                            {variant.label ??
                                                t("products.show.main")}
                                        </td>
                                        <td className="p-3 font-medium">
                                            {variant.sku}
                                        </td>
                                        <td className="p-3">
                                            {variant.reference ?? "—"}
                                        </td>
                                        <td className="p-3">
                                            {variant.barcode ?? "—"}
                                        </td>
                                        <td className="p-3 text-right font-medium">
                                            {p?.ttc ? formatMoney(p.ttc) : "—"}
                                        </td>
                                        <td className="p-3 text-right">
                                            {p?.ht ? (
                                                <>
                                                    {formatMoney(p.ht)}
                                                    {p.ht_source ===
                                                        "derived" && (
                                                        <span className="ml-1 text-xs text-slate-400">
                                                            (calc.)
                                                        </span>
                                                    )}
                                                </>
                                            ) : (
                                                "—"
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {taxLabel(
                                                p,
                                                t("common.notConfigured"),
                                            )}
                                        </td>
                                        <td className="p-3">
                                            <StatusBadge
                                                status={variant.status}
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </section>
            <div className="mt-8 flex flex-wrap gap-2">
                <ButtonLink href="/catalog/products" variant="secondary">
                    {t("imports.products.backToProducts")}
                </ButtonLink>
                {can.archive && product.status === "active" && (
                    <Button
                        variant="danger"
                        type="button"
                        onClick={() =>
                            window.confirm(t("products.show.archiveConfirm")) &&
                            router.patch(
                                `/catalog/products/${product.id}/archive`,
                            )
                        }
                    >
                        {t("products.show.archive")}
                    </Button>
                )}
            </div>
        </CatalogLayout>
    );
}
