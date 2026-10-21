import { BrandMark } from "@/components/ui/brand";
import { useI18n } from "@/i18n";

/*
 * Representational preview of the product — NOT the real dashboard. Built from the
 * app's own tokens so the welcome and auth screens feel like the same product.
 */
export default function AppPreview({ className = "" }: { className?: string }) {
    const { t } = useI18n();
    const bars = [38, 52, 44, 61, 70, 58, 82];

    return (
        <div
            className={`overflow-hidden rounded-panel border border-line bg-surface shadow-card ${className}`}
            role="img"
            aria-label={t("home.previewLabel")}
        >
            <div className="flex items-center gap-2 border-b border-line bg-raised px-4 py-2.5">
                <span className="size-2.5 rounded-full bg-line-strong" />
                <span className="size-2.5 rounded-full bg-line-strong" />
                <span className="size-2.5 rounded-full bg-line-strong" />
                <span className="ml-3 h-4 w-40 rounded bg-sage" />
            </div>

            <div className="grid grid-cols-[132px_1fr]">
                <div className="hidden flex-col gap-1 border-r border-line bg-raised p-3 sm:flex">
                    <div className="mb-2 flex items-center gap-2">
                        <BrandMark size={20} />
                        <span className="h-2.5 w-14 rounded bg-sage-deep" />
                    </div>
                    {[
                        t("nav.dashboard"),
                        t("nav.sales"),
                        t("nav.pos"),
                        t("nav.products"),
                        t("nav.stock"),
                        t("nav.customers"),
                    ].map((label, index) => (
                        <div
                            key={label}
                            className={`flex items-center gap-2 rounded-lg px-2 py-1.5 ${index === 2 ? "bg-sage" : ""}`}
                        >
                            <span
                                className={`size-2 rounded-sm ${index === 2 ? "bg-primary" : "bg-line-strong"}`}
                            />
                            <span
                                className={`h-2 rounded ${index === 2 ? "w-16 bg-ink/70" : "w-14 bg-sage-deep"}`}
                            />
                        </div>
                    ))}
                </div>

                <div className="space-y-3 p-4">
                    <div className="flex items-center justify-between">
                        <span className="h-3 w-28 rounded bg-sage-deep" />
                        <span className="h-7 w-24 rounded-lg bg-primary" />
                    </div>

                    <div className="grid grid-cols-3 gap-2.5">
                        {[
                            [t("home.preview.todaySales"), "18"],
                            [t("home.preview.inStock"), "432"],
                            [t("home.preview.toCollect"), "5"],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="rounded-xl border border-line bg-surface p-2.5"
                            >
                                <p className="text-[10px] text-ink-muted">
                                    {label}
                                </p>
                                <p className="mt-1 text-lg font-semibold tabular-nums text-ink">
                                    {value}
                                </p>
                            </div>
                        ))}
                    </div>

                    <div className="grid grid-cols-[1.4fr_1fr] gap-2.5">
                        <div className="rounded-xl border border-line bg-surface p-3">
                            <p className="text-[10px] text-ink-muted">
                                {t("home.preview.revenue7Days")}
                            </p>
                            <div className="mt-3 flex h-16 items-end gap-1.5">
                                {bars.map((height, index) => (
                                    <span
                                        key={index}
                                        className="flex-1 rounded-t bg-primary/80"
                                        style={{
                                            height: `${height}%`,
                                            opacity: 0.35 + index * 0.09,
                                        }}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className="rounded-xl border border-line bg-sage p-3">
                            <p className="text-[10px] text-ink-muted">
                                {t("nav.pos")}
                            </p>
                            <p className="mt-1 text-[13px] font-semibold text-ink">
                                {t("home.preview.receipt")} #1042
                            </p>
                            <div className="mt-2 space-y-1">
                                <span className="block h-2 w-full rounded bg-surface" />
                                <span className="block h-2 w-4/5 rounded bg-surface" />
                                <span className="block h-2 w-2/3 rounded bg-surface" />
                            </div>
                            <div className="mt-3 flex items-center justify-between">
                                <span className="h-2 w-10 rounded bg-sage-deep" />
                                <span className="text-[13px] font-semibold text-ink">
                                    1 480,00 DH
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
