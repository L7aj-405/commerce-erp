import { formatMoney, formatQuantity } from "@/utils/format";
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
    type ChartOptions,
    type TooltipItem,
} from "chart.js";
import { Bar, Doughnut, Line } from "react-chartjs-2";
import { useEffect, useState, type ReactNode } from "react";
import { useI18n } from "@/i18n";
import { EmptyWell, PanelTitle, PictoBars, PictoBox, PictoCubes, type PanelTone } from "@/components/ui/Panel";
import { IconPackage, IconTrending } from "@/layouts/navigation";

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Tooltip,
    Legend,
    Filler,
);

export const chartColors = {
    sales: "#117c79",
    collections: "#b06f1c",
    outgoing: "#b4483c",
    unpaid: "#b4483c",
    partial: "#c98a2e",
    paid: "#2c7a4b",
    muted: "#e7e7e2",
    salesFill: "rgba(17,124,121,.12)",
    collectionsFill: "rgba(176,111,28,.08)",
};

// Tuned for the graphite surface (#161a1b): every series keeps >=3:1 non-text contrast.
const darkChartColors: typeof chartColors = {
    sales: "#5cc0b8",
    collections: "#e8a65a",
    outgoing: "#ec9286",
    unpaid: "#ec9286",
    partial: "#e8a65a",
    paid: "#79c99a",
    muted: "#2b3234",
    salesFill: "rgba(92,192,184,.14)",
    collectionsFill: "rgba(232,166,90,.08)",
};

function useIsDarkTheme() {
    const read = () => typeof document !== "undefined" && document.documentElement.dataset.theme === "dark";
    const [dark, setDark] = useState(read);
    useEffect(() => {
        const observer = new MutationObserver(() => setDark(read()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
        return () => observer.disconnect();
    }, []);
    return dark;
}

/** Theme-aware series colors; also retunes Chart.js tick/grid defaults. */
export function useChartPalette() {
    const dark = useIsDarkTheme();
    ChartJS.defaults.color = dark ? "#aab4b2" : "#515b5b";
    ChartJS.defaults.borderColor = dark ? "#252b2c" : "#ecece7";
    ChartJS.defaults.font.family = document.documentElement.dir === "rtl" ? "'IBM Plex Sans Arabic', 'Plus Jakarta Sans', sans-serif" : "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";
    return dark ? darkChartColors : chartColors;
}

type TrendPoint = {
    date?: string;
    label: string;
    sales: string;
    collections: string;
};
type FlowPoint = {
    date?: string;
    label: string;
    incoming: string;
    outgoing: string;
};
type RankedPoint = { label: string; value: string };

const compactMoney = (value: number, locale: string) =>
    new Intl.NumberFormat(locale, {
        notation: "compact",
        maximumFractionDigits: 1,
    }).format(value);
const baseOptions = (locale: string): ChartOptions<"line"> => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: "index", intersect: false },
    plugins: {
        legend: {
            position: "top",
            align: "end",
            labels: { usePointStyle: true, pointStyle: "circle", boxWidth: 8, boxHeight: 8, padding: 16 },
        },
        tooltip: {
            callbacks: {
                label: (item: TooltipItem<"line">) =>
                    `${item.dataset.label}: ${formatMoney(item.parsed.y)}`,
            },
        },
    },
    scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
        y: {
            beginAtZero: true,
            ticks: { callback: (value) => compactMoney(Number(value), locale) },
        },
    },
});

const pointLabels = (
    points: Array<{ date?: string; label: string }>,
    locale: string,
) =>
    points.map((point) =>
        point.date
            ? new Intl.DateTimeFormat(locale, {
                  day: "numeric",
                  month: "short",
                  timeZone: "UTC",
              }).format(new Date(`${point.date}T00:00:00Z`))
            : point.label,
    );

export function ChartPanel({
    title,
    description,
    children,
    empty = false,
    emptyLabel,
    emptyHelp,
    emptyIcon,
    icon,
    tone = "teal",
    glass = false,
    className = "",
}: {
    title: string;
    description?: string;
    children: ReactNode;
    empty?: boolean;
    emptyLabel?: string;
    emptyHelp?: string;
    emptyIcon?: ReactNode;
    icon?: ReactNode;
    tone?: PanelTone;
    /** Medium Liquid Glass (dashboard); default solid surface for dense finance pages. */
    glass?: boolean | "soft";
    className?: string;
}) {
    const { t } = useI18n();
    return (
        <section className={`${glass === "soft" ? "surface-glass glass-soft" : glass ? "surface-glass" : "surface-card"} flex flex-col p-4 sm:p-5 ${className}`} aria-label={title}>
            <div className="mb-3.5">
                <PanelTitle icon={icon} tone={tone} title={title} description={description} />
            </div>
            {empty ? (
                <EmptyWell
                    className="min-h-40 flex-1"
                    icon={emptyIcon ?? <PictoBars />}
                    title={emptyLabel ?? t("dashboardCharts.empty")}
                    description={emptyHelp ?? t("dashboardCharts.emptyHelp")}
                />
            ) : (
                children
            )}
        </section>
    );
}

/** Crown glyph for ranking panels (16px tile icon). */
function IconCrown() {
    return (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <path d="m3 8 4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8Z" />
        </svg>
    );
}

export function SalesCollectionsChart({ points, compact = false }: { points: TrendPoint[]; compact?: boolean }) {
    const { locale, t } = useI18n();
    const colors = useChartPalette();
    const intlLocale = locale === "ar" ? "ar-MA" : "fr-FR";
    const empty = points.every(
        (point) => Number(point.sales) === 0 && Number(point.collections) === 0,
    );
    return (
        <ChartPanel
            title={t("finance.salesCollectionsTrend")}
            description={t("finance.salesCollectionsSemantics")}
            empty={empty}
            emptyLabel={t("finance.noSalesCollections")}
            emptyHelp={t("dashboardCharts.salesEmptyHelp")}
            icon={<IconTrending />}
            glass={compact}
        >
            <div className={compact ? "h-56 xl:h-[13.5rem]" : "h-72 sm:h-80"}>
                <Line
                    aria-label={t("finance.salesCollectionsTrend")}
                    data={{
                        labels: pointLabels(points, intlLocale),
                        datasets: [
                            {
                                label: t("finance.netSales"),
                                data: points.map((point) =>
                                    Number(point.sales),
                                ),
                                borderColor: colors.sales,
                                backgroundColor: colors.salesFill,
                                fill: true,
                                tension: 0.42,
                                borderWidth: 2.5,
                                pointRadius: 0,
                                pointHoverRadius: 5,
                                pointBackgroundColor: colors.sales,
                            },
                            {
                                label: t("finance.netCollected"),
                                data: points.map((point) =>
                                    Number(point.collections),
                                ),
                                borderColor: colors.collections,
                                backgroundColor: colors.collectionsFill,
                                fill: true,
                                tension: 0.42,
                                borderWidth: 2.5,
                                pointRadius: 0,
                                pointHoverRadius: 5,
                                pointBackgroundColor: colors.collections,
                            },
                        ],
                    }}
                    options={baseOptions(intlLocale)}
                />
            </div>
        </ChartPanel>
    );
}

export function OrderStatesChart({
    states,
    compact = false,
}: {
    states: { paid: number; partially_paid: number; unpaid: number };
    compact?: boolean;
}) {
    const { t } = useI18n();
    const colors = useChartPalette();
    const values = [states.paid, states.partially_paid, states.unpaid];
    return (
        <ChartPanel
            title={t("finance.orderState")}
            description={t("finance.confirmedOrdersPeriod")}
            empty={values.every((value) => value === 0)}
            emptyLabel={t("finance.noConfirmedOrders")}
            emptyHelp={t("dashboardCharts.ordersEmptyHelp")}
            emptyIcon={<PictoBox />}
            icon={<IconPackage />}
            glass={compact ? "soft" : false}
        >
            <div className={compact ? "mx-auto h-52 w-full max-w-sm xl:h-[13.5rem]" : "mx-auto h-64 max-w-sm"}>
                <Doughnut
                    aria-label={t("finance.orderState")}
                    data={{
                        labels: [
                            t("finance.paid"),
                            t("finance.partiallyPaid"),
                            t("finance.unpaid"),
                        ],
                        datasets: [
                            {
                                data: values,
                                backgroundColor: [
                                    colors.paid,
                                    colors.partial,
                                    colors.unpaid,
                                ],
                                borderWidth: 0,
                            },
                        ],
                    }}
                    options={{
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: "66%",
                        plugins: {
                            legend: {
                                position: "bottom",
                                labels: { usePointStyle: true, boxWidth: 8 },
                            },
                            tooltip: {
                                callbacks: {
                                    label: (item) =>
                                        `${item.label}: ${formatQuantity(item.parsed)}`,
                                },
                            },
                        },
                    }}
                />
            </div>
        </ChartPanel>
    );
}

export function RankedMoneyChart({
    title,
    description,
    points,
    emptyLabel,
    emptyHelp,
    compact = false,
}: {
    title: string;
    description?: string;
    points: RankedPoint[];
    emptyLabel?: string;
    emptyHelp?: string;
    compact?: boolean;
}) {
    const { locale, t } = useI18n();
    const colors = useChartPalette();
    const intlLocale = locale === "ar" ? "ar-MA" : "fr-FR";
    return (
        <ChartPanel
            title={title}
            description={description}
            empty={
                points.length === 0 ||
                points.every((point) => Number(point.value) === 0)
            }
            emptyLabel={emptyLabel}
            emptyHelp={emptyHelp}
            emptyIcon={<PictoCubes />}
            icon={<IconCrown />}
            glass={compact ? "soft" : false}
        >
            <div className={compact ? "h-52 xl:h-[13.5rem]" : "h-64 sm:h-72"}>
                <Bar
                    aria-label={title}
                    data={{
                        labels: points.map((point) => point.label),
                        datasets: [
                            {
                                label: t("common.amount"),
                                data: points.map((point) =>
                                    Number(point.value),
                                ),
                                backgroundColor: colors.sales,
                                borderRadius: 5,
                            },
                        ],
                    }}
                    options={{
                        indexAxis: "y",
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (item) => formatMoney(item.parsed.x),
                                },
                            },
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    callback: (value) =>
                                        compactMoney(Number(value), intlLocale),
                                },
                            },
                            y: { grid: { display: false } },
                        },
                    }}
                />
            </div>
        </ChartPanel>
    );
}

export function CashFlowChart({ points }: { points: FlowPoint[] }) {
    const { locale, t } = useI18n();
    const colors = useChartPalette();
    const intlLocale = locale === "ar" ? "ar-MA" : "fr-FR";
    const empty = points.every(
        (point) => Number(point.incoming) === 0 && Number(point.outgoing) === 0,
    );
    return (
        <ChartPanel
            title={t("finance.cashFlow")}
            description={t("finance.actualMovements")}
            empty={empty}
            emptyLabel={t("finance.noCashMovement")}
        >
            <div className="h-64 sm:h-72">
                <Bar
                    aria-label={t("finance.cashFlow")}
                    data={{
                        labels: pointLabels(points, intlLocale),
                        datasets: [
                            {
                                label: t("finance.actualInflows"),
                                data: points.map((point) =>
                                    Number(point.incoming),
                                ),
                                backgroundColor: colors.paid,
                                borderRadius: 4,
                            },
                            {
                                label: t("finance.actualOutflows"),
                                data: points.map((point) =>
                                    Number(point.outgoing),
                                ),
                                backgroundColor: colors.outgoing,
                                borderRadius: 4,
                            },
                        ],
                    }}
                    options={{
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: "bottom",
                                rtl: locale === "ar",
                                labels: { usePointStyle: true, boxWidth: 8 },
                            },
                            tooltip: {
                                rtl: locale === "ar",
                                callbacks: {
                                    label: (item) =>
                                        `${item.dataset.label}: ${formatMoney(item.parsed.y)}`,
                                },
                            },
                        },
                        scales: {
                            x: {
                                stacked: false,
                                grid: { display: false },
                                ticks: { maxTicksLimit: 10 },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: (value) =>
                                        compactMoney(Number(value), intlLocale),
                                },
                            },
                        },
                    }}
                />
            </div>
        </ChartPanel>
    );
}
