import ProductImage from "@/components/catalog/ProductImage";
import type { SupplierRelation } from "@/components/procurement/SupplierRelationDialog";
import VariantSuppliersSection from "@/components/procurement/VariantSuppliersSection";
import { Button, ButtonLink } from "@/components/ui/Button";
import PageHeader from "@/components/ui/PageHeader";
import StatusBadge from "@/components/ui/StatusBadge";
import CatalogLayout from "@/layouts/CatalogLayout";
import { useI18n } from "@/i18n";
import { formatMoney, formatQuantity } from "@/utils/format";
import { plainText } from "@/utils/plainText";
import { Head, router } from "@inertiajs/react";
import { useState, type ReactNode } from "react";

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
    supplierCatalog,
    defaultCurrency,
    can,
}: {
    product: Product;
    stock: Stock;
    priceByVariant: Record<string, PriceInfo>;
    supplierCatalog: Record<string, SupplierRelation[]> | null;
    defaultCurrency: string;
    can: {
        create: boolean;
        update: boolean;
        archive: boolean;
        stock: boolean;
        transfer: boolean;
        viewSuppliers: boolean;
        manageSuppliers: boolean;
        viewSupplierPrices: boolean;
    };
}) {
    const { t } = useI18n();
    const [descriptionOpen, setDescriptionOpen] = useState(false);
    const first = product.variants[0];
    const firstPrice = first ? priceByVariant[first.id] : undefined;
    const description = plainText(product.description);
    const longDescription = description.length > 320 || description.split("\n").length > 4;
    const stockSearch = encodeURIComponent(first?.sku ?? product.name);
    return (
        <CatalogLayout>
            <Head title={product.name} />
            <PageHeader
                title={product.name}
                description={t("products.show.catalogProduct")}
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
                            <ButtonLink href={`/inventory/stock?search=${stockSearch}`}>
                                {t("products.show.viewStock")}
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="space-y-6">
                <section className="grid gap-6 rounded-card border border-line bg-surface p-5 sm:p-6 md:grid-cols-[minmax(200px,260px)_1fr] lg:grid-cols-[minmax(240px,300px)_1fr]">
                    <div className="flex items-center justify-center self-start rounded-card bg-raised p-4">
                        <ProductImage
                            name={product.name}
                            imageUrl={product.image_url}
                            className="aspect-square h-auto w-full max-w-[16rem]"
                            textClassName="text-ink-faint text-4xl"
                        />
                    </div>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-base font-semibold text-ink">
                                {t("products.show.identity")}
                            </h2>
                            <StatusBadge status={product.status} />
                        </div>
                        <dl className="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 text-sm min-[420px]:grid-cols-2 xl:grid-cols-3">
                            <Fact label={t("products.brand")}>
                                <span className="font-medium">{product.brand?.name ?? t("products.noBrand")}</span>
                            </Fact>
                            <Fact label="SKU">
                                <bdi className="font-medium">{first?.sku ?? t("products.noSku")}</bdi>
                            </Fact>
                            <Fact label={t("common.reference")}>
                                <bdi>{first?.reference ?? t("products.noReference")}</bdi>
                            </Fact>
                            <Fact label={t("products.form.barcode")}>
                                <bdi>{first?.barcode ?? "—"}</bdi>
                            </Fact>
                            <Fact label={t("products.category")}>
                                {product.default_category?.name ?? "—"}
                            </Fact>
                            <Fact label="TVA">
                                {taxLabel(firstPrice, t("common.notConfigured"))}
                            </Fact>
                            <Fact label={t("products.show.publicPrice")}>
                                <span className="text-base font-semibold tabular-nums">
                                    {firstPrice?.ttc ? formatMoney(firstPrice.ttc) : "—"}
                                </span>
                            </Fact>
                            <Fact label={t("products.show.netPrice")}>
                                {firstPrice?.ht ? (
                                    <span className="tabular-nums">
                                        {formatMoney(firstPrice.ht)}
                                        {firstPrice.ht_source === "derived" && (
                                            <span className="ms-2 rounded bg-raised px-1.5 py-0.5 text-xs text-ink-muted">
                                                {t("products.form.calculated")}
                                            </span>
                                        )}
                                    </span>
                                ) : (
                                    "—"
                                )}
                            </Fact>
                            <Fact label={t("products.show.availableStock")}>
                                <span className="font-medium tabular-nums">
                                    {stock?.variants?.[0]
                                        ? formatQuantity(stock.variants[0].available_total)
                                        : "—"}
                                </span>
                            </Fact>
                        </dl>
                        {description !== "" && (
                            <div className="mt-5 border-t border-line pt-4">
                                <h3 className="text-xs font-medium uppercase tracking-wide text-ink-faint">
                                    {t("common.description")}
                                </h3>
                                <p
                                    className={`mt-1.5 max-w-3xl whitespace-pre-line break-words text-sm leading-6 text-ink-muted ${longDescription && !descriptionOpen ? "line-clamp-4" : ""}`}
                                >
                                    {description}
                                </p>
                                {longDescription && (
                                    <button
                                        type="button"
                                        onClick={() => setDescriptionOpen((open) => !open)}
                                        aria-expanded={descriptionOpen}
                                        className="mt-1 text-xs font-medium text-ink underline underline-offset-2"
                                    >
                                        {descriptionOpen ? t("products.show.readLess") : t("products.show.readMore")}
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                </section>

                {can.stock && stock && (
                    <section className="rounded-card border border-line bg-surface p-5 sm:p-6">
                        <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                            <div className="min-w-0">
                                <h2 className="text-base font-semibold text-ink">
                                    {t("products.show.availability")}
                                </h2>
                                <p className="mt-1 text-sm text-ink-muted">
                                    {t("products.show.availabilityDescription")}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {can.update && (
                                    <ButtonLink
                                        href={`/catalog/products/${product.id}/edit`}
                                        variant="secondary"
                                        size="sm"
                                    >
                                        {t("common.edit")}
                                    </ButtonLink>
                                )}
                                {can.transfer && first && (
                                    <ButtonLink
                                        href={`/inventory/transfers/create?variant_id=${first.id}`}
                                        size="sm"
                                    >
                                        {t("products.show.transfer")}
                                    </ButtonLink>
                                )}
                                {can.stock && (
                                    <ButtonLink
                                        href={`/inventory/stock?search=${stockSearch}`}
                                        variant="secondary"
                                        size="sm"
                                    >
                                        {t("products.show.adjustStock")}
                                    </ButtonLink>
                                )}
                                {can.stock && (
                                    <ButtonLink
                                        href={`/inventory/movements?search=${stockSearch}`}
                                        variant="secondary"
                                        size="sm"
                                    >
                                        {t("products.show.history")}
                                    </ButtonLink>
                                )}
                            </div>
                        </div>
                        <div className="mt-5 grid gap-4 lg:grid-cols-2">
                            {stock.variants.map((variant) => (
                                <article
                                    key={variant.id}
                                    className="flex flex-col rounded-card border border-line bg-raised p-4"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-ink">
                                                {variant.label ?? t("products.mainVariant")}
                                            </p>
                                            <p className="truncate text-xs text-ink-muted">
                                                <bdi>{variant.sku ?? t("products.show.noSkuShort")}</bdi>
                                            </p>
                                        </div>
                                        <div className="shrink-0 text-end">
                                            <p className="text-[11px] font-medium uppercase tracking-wide text-ink-faint">
                                                {t("products.show.totalAvailable")}
                                            </p>
                                            <p className="text-xl font-semibold tabular-nums text-ink">
                                                {formatQuantity(variant.available_total)}
                                            </p>
                                        </div>
                                    </div>
                                    {variant.warehouses.length > 0 && (
                                        <ul className="mt-3 divide-y divide-line rounded-field border border-line bg-surface">
                                            {variant.warehouses.map((row) => (
                                                <li
                                                    key={row.warehouse.id}
                                                    className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                                >
                                                    <span className="min-w-0 truncate text-ink-muted">{row.warehouse.name}</span>
                                                    <span className="shrink-0 font-medium tabular-nums text-ink">
                                                        {formatQuantity(row.available)}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {can.transfer && (
                                        <div className="mt-3 flex justify-end">
                                            <ButtonLink
                                                href={`/inventory/transfers/create?variant_id=${variant.id}`}
                                                variant="secondary"
                                                size="sm"
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

                <section className="rounded-card border border-line bg-surface p-5 sm:p-6">
                    <h2 className="text-base font-semibold text-ink">
                        {t("products.show.variants")}
                    </h2>
                    <div className="mt-4 overflow-x-auto rounded-field border border-line">
                        <table className="w-full min-w-[860px] text-start text-sm">
                            <thead className="bg-raised text-xs uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th className="w-14 px-3 py-2.5">
                                        <span className="sr-only">{t("products.show.image")}</span>
                                    </th>
                                    <th className="px-3 py-2.5 text-start font-medium">{t("products.variant")}</th>
                                    <th className="px-3 py-2.5 text-start font-medium">SKU</th>
                                    <th className="px-3 py-2.5 text-start font-medium">{t("common.reference")}</th>
                                    <th className="px-3 py-2.5 text-start font-medium">{t("products.form.barcode")}</th>
                                    <th className="px-3 py-2.5 text-end font-medium">{t("products.show.publicPrice")}</th>
                                    <th className="px-3 py-2.5 text-end font-medium">{t("products.show.netPrice")}</th>
                                    <th className="px-3 py-2.5 text-start font-medium">TVA</th>
                                    <th className="px-3 py-2.5 text-start font-medium">{t("common.status")}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {product.variants.map((variant) => {
                                    const p = priceByVariant[variant.id];
                                    return (
                                        <tr key={variant.id} className="align-middle">
                                            <td className="px-3 py-2">
                                                <div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded-field bg-raised">
                                                    <ProductImage
                                                        name={variant.label ?? product.name}
                                                        imageUrl={variant.image_url ?? product.image_url}
                                                        className="h-full w-full"
                                                        roundedClassName="rounded-none"
                                                    />
                                                </div>
                                            </td>
                                            <td className="max-w-[14rem] px-3 py-2">
                                                <span className="block truncate text-ink" title={variant.label ?? undefined}>
                                                    {variant.label ?? t("products.show.main")}
                                                </span>
                                            </td>
                                            <td className="max-w-[12rem] px-3 py-2">
                                                <bdi className="block truncate font-medium text-ink" title={variant.sku ?? undefined}>{variant.sku}</bdi>
                                            </td>
                                            <td className="max-w-[12rem] px-3 py-2">
                                                <bdi className="block truncate text-ink-muted" title={variant.reference ?? undefined}>{variant.reference ?? "—"}</bdi>
                                            </td>
                                            <td className="px-3 py-2">
                                                <bdi className="whitespace-nowrap text-ink-muted">{variant.barcode ?? "—"}</bdi>
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-end font-medium tabular-nums text-ink">
                                                {p?.ttc ? formatMoney(p.ttc) : "—"}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-end tabular-nums text-ink-muted">
                                                {p?.ht ? (
                                                    <>
                                                        {formatMoney(p.ht)}
                                                        {p.ht_source === "derived" && (
                                                            <span className="ms-1 text-xs text-ink-faint">(calc.)</span>
                                                        )}
                                                    </>
                                                ) : (
                                                    "—"
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-ink-muted">
                                                {taxLabel(p, t("common.notConfigured"))}
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={variant.status} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>

                {supplierCatalog !== null && (
                    <VariantSuppliersSection
                        productName={product.name}
                        variants={product.variants}
                        relationsByVariant={supplierCatalog}
                        canManage={can.manageSuppliers}
                        viewPrices={can.viewSupplierPrices}
                        defaultCurrency={defaultCurrency}
                    />
                )}
            </div>

            <div className="mt-8 flex flex-wrap items-center gap-2 border-t border-line pt-6">
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

/** One identity fact: a quiet label above an easy-to-scan value. */
function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-ink-faint">{label}</dt>
            <dd className="mt-0.5 break-words text-ink">{children}</dd>
        </div>
    );
}
