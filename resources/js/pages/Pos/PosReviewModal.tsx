import { Money, Quantity } from "@/components/pos/primitives";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Dialog } from "@/components/ui/Dialog";
import { Badge } from "@/components/ui/StatusBadge";
import { useI18n } from "@/i18n";
import type { ReactNode } from "react";
import type { ReviewData } from "./pos-shared";
import { originExplanation } from "./PosCheckoutPanel";
import type { ActiveSale } from "./types";

type Props = {
    open: boolean;
    data: ReviewData | null;
    sale: ActiveSale;
    currencyCode: string;
    busy: boolean;
    error?: string | null;
    onBack: () => void;
    onConfirm: (print: boolean) => void;
};

/**
 * Final confirmation before the sale is posted. Built on the shared Dialog:
 * Escape / backdrop / close = "back" (disabled while posting), focus trapped and
 * restored. Initial focus lands on the summary, not on "Valider", so a stray
 * Enter can never post a sale by accident.
 */
export default function PosReviewModal({
    open,
    data,
    sale,
    currencyCode,
    busy,
    error,
    onBack,
    onConfirm,
}: Props) {
    const { t } = useI18n();
    if (!data) return null;

    return (
        <Dialog
            open={open}
            onClose={() => !busy && onBack()}
            dismissible={!busy}
            size="sm"
            title={t("pos.confirmSale")}
            description={t("pos.reviewHelp")}
            footer={
                <>
                    {error && (
                        <Alert tone="danger" className="mb-2.5 px-3 py-2">
                            {error}
                        </Alert>
                    )}
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Button
                            type="button"
                            size="lg"
                            loading={busy}
                            loadingText={t("pos.validating")}
                            onClick={() => onConfirm(false)}
                            className="order-1 sm:order-2 sm:flex-1"
                        >
                            {t("pos.validate")}
                        </Button>
                        <Button
                            type="button"
                            size="lg"
                            variant="outline"
                            loading={busy}
                            loadingText={t("pos.validating")}
                            onClick={() => onConfirm(true)}
                            className="order-2 sm:order-3 sm:flex-1"
                        >
                            {t("pos.validatePrint")}
                        </Button>
                        <Button
                            type="button"
                            size="lg"
                            variant="secondary"
                            disabled={busy}
                            onClick={onBack}
                            className="order-3 sm:order-1"
                        >
                            {t("pos.back")}
                        </Button>
                    </div>
                </>
            }
        >
            <div
                data-autofocus
                tabIndex={-1}
                aria-label={t("pos.orderSummary")}
                className="space-y-4 px-4 py-4 text-sm focus:outline-none sm:px-6"
            >
                <div className="flex flex-wrap gap-1.5">
                    <Badge variant={data.fulfillment_mode === "delivery" ? "info" : "success"}>
                        {data.fulfillment_mode === "delivery" ? t("pos.deliveryLater") : t("pos.pickupNow")}
                    </Badge>
                    <Badge variant="neutral">
                        {data.fulfillment_origin === "direct" ? t("pos.originDirect") : t("pos.originShowroom")}
                    </Badge>
                </div>

                <Block title={t("pos.customer")}>
                    <p className="font-semibold text-ink">{data.customer?.display_name ?? t("pos.counterCustomer")}</p>
                    {data.customer && (
                        <p className="text-ink-muted">
                            {[data.customer.company_name, data.customer.phone, data.customer.email].filter(Boolean).join(" · ")}
                        </p>
                    )}
                </Block>

                {data.delivery && (
                    <Block title={t("pos.shipping")}>
                        <p className="text-ink-muted">{data.delivery.address || "—"}</p>
                        {data.delivery.phone && <p className="text-ink-muted">{data.delivery.phone}</p>}
                    </Block>
                )}

                <Block title={t("pos.items")}>
                    <ul className="space-y-1.5">
                        {sale.lines.map((line) => (
                            <li key={line.id} className="flex flex-col gap-0.5">
                                <div className="flex justify-between gap-3">
                                    <span className="min-w-0 truncate text-ink">
                                        <Quantity value={line.quantity} /> × {line.description}
                                    </span>
                                    <span className="shrink-0 font-medium text-ink tabular-nums">
                                        <Money value={line.line_total ?? "0"} currency={currencyCode} />
                                    </span>
                                </div>
                                {line.line_type === "catalog" && Number(line.to_procure ?? "0") > 0 && (
                                    <span className="text-[13px] text-ink-muted">
                                        <Quantity value={line.company_covered ?? "0"} /> {t("pos.availableCompanyUnits")} ·{" "}
                                        <Quantity value={line.to_procure ?? "0"} />{" "}
                                        {line.procurement?.supplier?.name
                                            ? t("pos.confirmedAtSupplier").replace("{supplier}", line.procurement.supplier.name)
                                            : t("pos.toProcureSupplier")}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </Block>

                <dl className="space-y-1 border-t border-line pt-3 tabular-nums">
                    <Row label={t("pos.grossExcl")}>
                        <Money value={data.totals.subtotal_excl_tax} currency={currencyCode} />
                    </Row>
                    {Number(data.totals.discount) > 0 && (
                        <>
                            <Row label={t("pos.discountExcl")} className="text-success">
                                - <Money value={data.totals.discount} currency={currencyCode} />
                            </Row>
                            <Row label={t("pos.netExcl")}>
                                <Money value={data.totals.net_excl_tax} currency={currencyCode} />
                            </Row>
                        </>
                    )}
                    <Row label={t("pos.vat")}>
                        <Money value={data.totals.tax_total} currency={currencyCode} />
                    </Row>
                    {Number(data.totals.shipping) > 0 && (
                        <Row label={t("pos.shipping")}>
                            <Money value={data.totals.shipping} currency={currencyCode} />
                        </Row>
                    )}
                    <div className="flex items-baseline justify-between border-t border-line pt-1.5">
                        <dt className="font-semibold text-ink">{t("pos.totalIncl")}</dt>
                        <dd className="metric-value text-xl font-semibold text-ink">
                            <Money value={data.totals.total} currency={currencyCode} />
                        </dd>
                    </div>
                </dl>

                <Block title={t("pos.payment")} bordered>
                    <ul className="space-y-1">
                        {data.payments.length === 0 && <li className="text-ink-muted">{t("pos.deferredHelp")}</li>}
                        {data.payments.map((payment, index) => (
                            <li key={index} className="flex justify-between gap-3">
                                <span className="text-ink">
                                    {payment.label}
                                    {payment.method === "cash" && payment.cash_received ? (
                                        <span className="text-ink-muted">
                                            {" "}
                                            · {t("pos.received")} <Money value={payment.cash_received} currency={currencyCode} />
                                        </span>
                                    ) : null}
                                    {payment.reference ? <span className="text-ink-muted"> · {payment.reference}</span> : null}
                                </span>
                                <span className="shrink-0 text-ink tabular-nums">
                                    <Money value={payment.amount} currency={currencyCode} />
                                </span>
                            </li>
                        ))}
                    </ul>
                    <dl className="mt-2 space-y-0.5 tabular-nums">
                        <Row label={t("pos.receivedNow")} strong>
                            <Money value={data.paid} currency={currencyCode} />
                        </Row>
                        <Row label={t("pos.remaining")} strong className={Number(data.remaining) > 0 ? "text-warning" : "text-success"}>
                            <Money value={data.remaining} currency={currencyCode} />
                        </Row>
                    </dl>
                </Block>

                <p
                    className={`rounded-field border px-3 py-2 text-[13px] leading-5 ${
                        data.fulfillment_origin === "showroom" && data.requires_replenishment
                            ? "border-warning/30 bg-warning-soft text-warning"
                            : "border-line bg-raised text-ink-muted"
                    }`}
                >
                    {originExplanation(
                        data.fulfillment_origin,
                        data.requires_replenishment,
                        String(Number(data.remote_required)),
                        sale.warehouse?.name ?? t("pos.originShowroomFallback"),
                        t,
                    )}
                </p>
                {sale.awaiting_supplier_procurement && (
                    <Alert tone="warning" className="px-3 py-2">
                        {t("pos.specialOrderHelp")}
                    </Alert>
                )}
            </div>
        </Dialog>
    );
}

function Block({ title, bordered = false, children }: { title: string; bordered?: boolean; children: ReactNode }) {
    return (
        <section className={bordered ? "border-t border-line pt-3" : undefined}>
            <h3 className="mb-1 text-[13px] font-semibold text-ink-muted">{title}</h3>
            {children}
        </section>
    );
}

function Row({ label, strong = false, className = "", children }: { label: string; strong?: boolean; className?: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-ink-muted">{label}</dt>
            <dd className={`${strong ? "font-semibold" : ""} ${className || "text-ink"}`}>{children}</dd>
        </div>
    );
}
