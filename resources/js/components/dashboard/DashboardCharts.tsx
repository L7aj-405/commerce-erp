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
import type { ReactNode } from "react";
import { useI18n } from "@/i18n";

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
    sales: "#254536",
    collections: "#4f7c65",
    outgoing: "#b15f4b",
    unpaid: "#b15f4b",
    partial: "#d09a47",
    paid: "#4f7c65",
    muted: "#d8d8ce",
};

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
            position: "bottom",
            labels: { usePointStyle: true, boxWidth: 8, padding: 18 },
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
    className = "",
}: {
    title: string;
    description?: string;
    children: ReactNode;
    empty?: boolean;
    emptyLabel?: string;
    className?: string;
}) {
    const { t } = useI18n();
    return (
        <section
            className={`rounded-card border border-line bg-surface p-5 shadow-card ${className}`}
            aria-label={title}
        >
            <div className="mb-4">
                <h2 className="font-semibold text-ink">{title}</h2>
                {description && (
                    <p className="mt-1 text-[13px] text-ink-muted">
                        {description}
                    </p>
                )}
            </div>
            {empty ? (
                <div className="grid h-64 place-items-center rounded-field bg-raised text-sm text-ink-faint">
                    {emptyLabel ?? t("dashboardCharts.empty")}
                </div>
            ) : (
                children
            )}
        </section>
    );
}

export function SalesCollectionsChart({ points }: { points: TrendPoint[] }) {
    const { locale, t } = useI18n();
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
        >
            <div className="h-72 sm:h-80">
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
                                borderColor: chartColors.sales,
                                backgroundColor: "rgba(37,69,54,.10)",
                                fill: true,
                                tension: 0.3,
                                pointRadius: 2,
                            },
                            {
                                label: t("finance.netCollected"),
                                data: points.map((point) =>
                                    Number(point.collections),
                                ),
                                borderColor: chartColors.collections,
                                backgroundColor: "rgba(63,122,86,.08)",
                                tension: 0.3,
                                pointRadius: 2,
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
}: {
    states: { paid: number; partially_paid: number; unpaid: number };
}) {
    const { t } = useI18n();
    const values = [states.paid, states.partially_paid, states.unpaid];
    return (
        <ChartPanel
            title={t("finance.orderState")}
            description={t("finance.confirmedOrdersPeriod")}
            empty={values.every((value) => value === 0)}
            emptyLabel={t("finance.noConfirmedOrders")}
        >
            <div className="mx-auto h-64 max-w-sm">
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
                                    chartColors.paid,
                                    chartColors.partial,
                                    chartColors.unpaid,
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
}: {
    title: string;
    description?: string;
    points: RankedPoint[];
    emptyLabel?: string;
}) {
    const { locale, t } = useI18n();
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
        >
            <div className="h-64 sm:h-72">
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
                                backgroundColor: chartColors.sales,
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
                                backgroundColor: chartColors.paid,
                                borderRadius: 4,
                            },
                            {
                                label: t("finance.actualOutflows"),
                                data: points.map((point) =>
                                    Number(point.outgoing),
                                ),
                                backgroundColor: chartColors.outgoing,
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
