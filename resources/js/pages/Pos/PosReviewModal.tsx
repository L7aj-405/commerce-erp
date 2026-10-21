import { Money, Quantity } from "@/components/pos/primitives";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { useI18n } from "@/i18n";
import { useEffect } from "react";
import type { ReviewData } from "./pos-shared";
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
    useEffect(() => {
        if (!open) return;
        const handler = (event: KeyboardEvent) => {
            if (event.key === "Escape" && !busy) onBack();
        };
        window.addEventListener("keydown", handler);
        return () => window.removeEventListener("keydown", handler);
    }, [open, busy, onBack]);

    if (!open || !data) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink/25 p-4 backdrop-blur-sm">
            <button
                type="button"
                aria-label={t("pos.close")}
                className="absolute inset-0 cursor-default"
                onClick={() => !busy && onBack()}
            />
            <div className="relative flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-panel border border-line bg-surface shadow-pop">
                <div className="shrink-0 border-b border-line px-5 py-4">
                    <h2 className="text-base font-semibold text-ink">
                        {t("pos.confirmSale")}
                    </h2>
                    <p className="mt-0.5 text-[13px] text-ink-muted">
                        {t("pos.reviewHelp")}
                    </p>
                    <span
                        className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[12px] font-semibold ${data.fulfillment_mode === "delivery" ? "bg-sage text-ink" : "bg-success-soft text-success"}`}
                    >
                        {data.fulfillment_mode === "delivery"
                            ? `🚚 ${t("pos.deliveryLater")}`
                            : `⚡ ${t("pos.pickupNow")}`}
                    </span>
                </div>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 text-[13px]">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                            {t("pos.customer")}
                        </p>
                        <p className="mt-1 font-medium text-ink">
                            {data.customer?.display_name ??
                                t("pos.counterCustomer")}
                        </p>
                        {data.customer && (
                            <p className="text-ink-muted">
                                {[
                                    data.customer.company_name,
                                    data.customer.phone,
                                    data.customer.email,
                                ]
                                    .filter(Boolean)
                                    .join(" · ")}
                            </p>
                        )}
                    </div>

                    {data.delivery && (
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                                {t("pos.shipping")}
                            </p>
                            <p className="mt-1 text-ink-muted">
                                {data.delivery.address || "—"}
                            </p>
                            {data.delivery.phone && (
                                <p className="text-ink-muted">
                                    {data.delivery.phone}
                                </p>
                            )}
                        </div>
                    )}

                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                            {t("pos.items")}
                        </p>
                        <ul className="mt-1 space-y-1">
                            {sale.lines.map((line) => (
                                <li
                                    key={line.id}
                                    className="flex flex-col gap-0.5"
                                >
                                    <div className="flex justify-between gap-3">
                                        <span className="min-w-0 truncate text-ink">
                                            <Quantity value={line.quantity} /> ×{" "}
                                            {line.description}
                                        </span>
                                        <span className="shrink-0 text-ink">
                                            <Money
                                                value={line.line_total ?? "0"}
                                                currency={currencyCode}
                                            />
                                        </span>
                                    </div>
                                    {line.line_type === "catalog" &&
                                        Number(line.to_procure ?? "0") > 0 && (
                                            <span className="text-[11px] text-ink-muted">
                                                <Quantity
                                                    value={
                                                        line.company_covered ??
                                                        "0"
                                                    }
                                                />{" "}
                                                {t("pos.availableCompanyUnits")}{" "}
                                                ·{" "}
                                                <Quantity
                                                    value={
                                                        line.to_procure ?? "0"
                                                    }
                                                />{" "}
                                                {line.procurement?.supplier
                                                    ?.name
                                                    ? t(
                                                          "pos.confirmedAtSupplier",
                                                      ).replace(
                                                          "{supplier}",
                                                          line.procurement
                                                              .supplier.name,
                                                      )
                                                    : t(
                                                          "pos.toProcureSupplier",
                                                      )}
                                            </span>
                                        )}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <dl className="space-y-1 border-t border-line pt-3">
                        <div className="flex justify-between">
                            <dt className="text-ink-muted">
                                {t("pos.grossExcl")}
                            </dt>
                            <dd>
                                <Money
                                    value={data.totals.subtotal_excl_tax}
                                    currency={currencyCode}
                                />
                            </dd>
                        </div>
                        {Number(data.totals.discount) > 0 && (
                            <>
                                <div className="flex justify-between">
                                    <dt className="text-ink-muted">
                                        {t("pos.discountExcl")}
                                    </dt>
                                    <dd className="text-success">
                                        -{" "}
                                        <Money
                                            value={data.totals.discount}
                                            currency={currencyCode}
                                        />
                                    </dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-ink-muted">
                                        {t("pos.netExcl")}
                                    </dt>
                                    <dd>
                                        <Money
                                            value={data.totals.net_excl_tax}
                                            currency={currencyCode}
                                        />
                                    </dd>
                                </div>
                            </>
                        )}
                        <div className="flex justify-between">
                            <dt className="text-ink-muted">{t("pos.vat")}</dt>
                            <dd>
                                <Money
                                    value={data.totals.tax_total}
                                    currency={currencyCode}
                                />
                            </dd>
                        </div>
                        {Number(data.totals.shipping) > 0 && (
                            <div className="flex justify-between">
                                <dt className="text-ink-muted">
                                    {t("pos.shipping")}
                                </dt>
                                <dd>
                                    <Money
                                        value={data.totals.shipping}
                                        currency={currencyCode}
                                    />
                                </dd>
                            </div>
                        )}
                        <div className="flex items-baseline justify-between border-t border-line pt-1.5">
                            <dt className="text-sm font-semibold text-ink">
                                {t("pos.totalIncl")}
                            </dt>
                            <dd className="text-base font-semibold text-ink">
                                <Money
                                    value={data.totals.total}
                                    currency={currencyCode}
                                />
                            </dd>
                        </div>
                    </dl>

                    <div className="border-t border-line pt-3">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">
                            {t("pos.payment")}
                        </p>
                        <ul className="mt-1 space-y-1">
                            {data.payments.length === 0 && (
                                <li className="text-ink-muted">
                                    {t("pos.deferredHelp")}
                                </li>
                            )}
                            {data.payments.map((payment, index) => (
                                <li
                                    key={index}
                                    className="flex justify-between gap-3"
                                >
                                    <span className="text-ink">
                                        {payment.label}
                                        {payment.method === "cash" &&
                                        payment.cash_received ? (
                                            <span className="text-ink-muted">
                                                {" "}
                                                · {t("pos.received")}{" "}
                                                <Money
                                                    value={
                                                        payment.cash_received
                                                    }
                                                    currency={currencyCode}
                                                />
                                            </span>
                                        ) : null}
                                        {payment.reference ? (
                                            <span className="text-ink-muted">
                                                {" "}
                                                · {payment.reference}
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="shrink-0 text-ink">
                                        <Money
                                            value={payment.amount}
                                            currency={currencyCode}
                                        />
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-1.5 flex justify-between">
                            <span className="text-ink-muted">
                                {t("pos.receivedNow")}
                            </span>
                            <strong className="text-ink">
                                <Money
                                    value={data.paid}
                                    currency={currencyCode}
                                />
                            </strong>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-ink-muted">
                                {t("pos.remaining")}
                            </span>
                            <strong
                                className={
                                    Number(data.remaining) > 0
                                        ? "text-warning"
                                        : "text-ink"
                                }
                            >
                                <Money
                                    value={data.remaining}
                                    currency={currencyCode}
                                />
                            </strong>
                        </div>
                    </div>

                    {data.fulfillment_mode === "pickup" &&
                        data.requires_replenishment && (
                            <p className="rounded-field bg-warning-soft px-3 py-2 text-[12px] text-warning">
                                {t("pos.remotePreparation").replace(
                                    "{count}",
                                    String(Number(data.remote_required)),
                                )}
                            </p>
                        )}
                    {sale.awaiting_supplier_procurement && (
                        <p className="rounded-field bg-warning-soft px-3 py-2 text-[12px] text-warning">
                            {t("pos.specialOrderHelp")}
                        </p>
                    )}
                </div>

                <div className="shrink-0 border-t border-line bg-raised px-5 py-3.5">
                    {error && (
                        <p
                            role="alert"
                            className="mb-2.5 rounded-field bg-danger-soft px-3 py-2 text-[12px] text-danger"
                        >
                            {error}
                        </p>
                    )}
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Button
                            type="button"
                            loading={busy}
                            loadingText={t("pos.validating")}
                            onClick={() => onConfirm(false)}
                            className="order-1 min-h-11 px-3 py-2.5 sm:order-2 sm:flex-1"
                        >
                            {t("pos.validate")}
                        </Button>
                        <button
                            type="button"
                            disabled={busy}
                            aria-busy={busy || undefined}
                            onClick={() => onConfirm(true)}
                            className="order-2 inline-flex min-h-11 items-center justify-center gap-2 rounded-field border border-primary bg-surface px-3 py-2.5 text-sm font-semibold text-primary transition-soft hover:bg-sage disabled:opacity-50 sm:order-3 sm:flex-1"
                        >
                            {busy && <Spinner size="sm" />}
                            {busy
                                ? t("pos.validating")
                                : t("pos.validatePrint")}
                        </button>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={onBack}
                            className="order-3 min-h-11 rounded-field border border-line-strong bg-surface px-3 py-2.5 text-[13px] font-medium text-ink transition-soft hover:bg-sage disabled:opacity-50 sm:order-1"
                        >
                            {t("pos.back")}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
