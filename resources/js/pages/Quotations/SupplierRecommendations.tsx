import { Button } from "@/components/ui/Button";
import { useI18n } from "@/i18n";
import type { TranslationKey } from "@/i18n/translations";
import { formatDate, formatMoney, formatQuantity } from "@/utils/format";

/** One advisory recommendation from SupplierRecommendationService (B2). */
export type SupplierRecommendation = {
    rank: number;
    tier: "A" | "B" | "C" | "D";
    source: "catalog" | "legacy";
    supplier: { id: number; name: string };
    is_preferred: boolean;
    purchase_price: string | null;
    currency_code: string | null;
    prices_visible: boolean;
    lead_time_days: number | null;
    quality_rating: number | null;
    reliability_rating: number | null;
    quantity: { minimum_order_quantity: string | null; suggested_order_quantity: string | null };
    last_purchase: { purchase_order_number: string; order_date: string; unit_price: string | null; currency_code: string } | null;
    reasons: { code: string; params: Record<string, string | number> }[];
    warnings: { code: string; params: Record<string, string | number> }[];
    eligible_for_request: boolean;
};

const fill = (text: string, params: Record<string, string | number>) =>
    Object.entries(params).reduce((out, [key, value]) => out.split(`{${key}}`).join(String(value)), text);

/** Translate a reason/warning code; dates and amounts are formatted for display. */
function useExplain() {
    const { t } = useI18n();
    return (code: string, params: Record<string, string | number>) => {
        const shown: Record<string, string | number> = { ...params };
        if (typeof params.date === "string") shown.date = formatDate(params.date);
        if (params.price !== undefined) shown.price = formatMoney(String(params.price), String(params.currency ?? "MAD"));
        for (const key of ["minimum", "requested", "suggested", "multiple"]) {
            if (params[key] !== undefined) shown[key] = formatQuantity(String(params[key]));
        }
        return fill(t(`supplierReco.code.${code}` as TranslationKey), shown);
    };
}

/**
 * "Fournisseurs recommandés": advisory ranking for one sourcing line. Each card's
 * "Demander disponibilité" is an explicit choice that uses the canonical request.
 */
export default function SupplierRecommendations({
    recommendations,
    loading,
    processingId,
    onRequest,
}: {
    recommendations: SupplierRecommendation[];
    loading: boolean;
    processingId: number | null;
    onRequest: (supplierId: number) => void;
}) {
    const { t } = useI18n();
    const explain = useExplain();

    return (
        <section aria-labelledby="supplier-reco-title">
            <h3 id="supplier-reco-title" className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                {t("supplierReco.title")}
            </h3>
            <p className="mt-0.5 text-[11px] text-ink-faint">{t("supplierReco.advisory")}</p>
            {recommendations.length === 0 ? (
                <p className="mt-2 rounded-field border border-dashed border-line px-3 py-2 text-xs text-ink-muted">
                    {loading ? t("common.loading") : t("supplierReco.none")}
                </p>
            ) : (
                <ol className="mt-2 space-y-2">
                    {recommendations.map((r) => (
                        <li key={r.supplier.id} className="rounded-field border border-line bg-surface p-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium text-ink">
                                        <span className="tabular-nums text-ink-faint">{r.rank}.</span>
                                        {r.supplier.name}
                                        {r.is_preferred && (
                                            <span className="rounded-full bg-success-soft px-2 py-0.5 text-[10px] text-success">{t("supplierCatalog.preferred")}</span>
                                        )}
                                        <span className="rounded-full bg-raised px-2 py-0.5 text-[10px] text-ink-muted">{t(`supplierReco.tier.${r.tier}` as TranslationKey)}</span>
                                    </p>
                                    <dl className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-ink-muted">
                                        {r.prices_visible && r.purchase_price !== null && (
                                            <div><dt className="inline">{t("supplierCatalog.purchasePrice")} : </dt><dd className="inline tabular-nums text-ink">{formatMoney(r.purchase_price, r.currency_code ?? "MAD")}</dd></div>
                                        )}
                                        {r.lead_time_days !== null && (
                                            <div><dt className="inline">{t("supplierCatalog.leadTime")} : </dt><dd className="inline text-ink">{r.lead_time_days} {t("supplierCatalog.days")}</dd></div>
                                        )}
                                        {r.quantity.minimum_order_quantity !== null && (
                                            <div><dt className="inline">{t("supplierCatalog.moq")} : </dt><dd className="inline tabular-nums text-ink">{formatQuantity(r.quantity.minimum_order_quantity)}</dd></div>
                                        )}
                                        {r.reliability_rating !== null && (
                                            <div><dt className="inline">{t("supplierReco.reliability")} : </dt><dd className="inline text-ink">{r.reliability_rating}/5</dd></div>
                                        )}
                                        {r.quality_rating !== null && (
                                            <div><dt className="inline">{t("supplierReco.quality")} : </dt><dd className="inline text-ink">{r.quality_rating}/5</dd></div>
                                        )}
                                        {r.last_purchase && (
                                            <div>
                                                <dt className="inline">{t("supplierCatalog.lastPurchase")} : </dt>
                                                <dd className="inline text-ink">
                                                    <bdi>{r.last_purchase.purchase_order_number}</bdi> · {formatDate(r.last_purchase.order_date)}
                                                    {r.last_purchase.unit_price !== null && ` · ${formatMoney(r.last_purchase.unit_price, r.last_purchase.currency_code)}`}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                                <Button
                                    size="sm"
                                    onClick={() => onRequest(r.supplier.id)}
                                    disabled={!r.eligible_for_request || processingId !== null}
                                    loading={processingId === r.supplier.id}
                                    className="whitespace-nowrap"
                                >
                                    {t("supplierReco.askAvailability")}
                                </Button>
                            </div>
                            {r.reasons.length > 0 && (
                                <ul className="mt-2 flex flex-wrap gap-1">
                                    {r.reasons.map((reason) => (
                                        <li key={reason.code} className="rounded bg-success-soft px-1.5 py-0.5 text-[11px] text-success">{explain(reason.code, reason.params)}</li>
                                    ))}
                                </ul>
                            )}
                            {r.warnings.length > 0 && (
                                <ul className="mt-1 flex flex-wrap gap-1">
                                    {r.warnings.map((warning) => (
                                        <li key={warning.code} className="rounded bg-warning-soft px-1.5 py-0.5 text-[11px] text-warning">{explain(warning.code, warning.params)}</li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
