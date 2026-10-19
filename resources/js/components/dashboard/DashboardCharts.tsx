import { formatMoney, formatQuantity } from '@/utils/format';
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
} from 'chart.js';
import { Bar, Doughnut, Line } from 'react-chartjs-2';
import type { ReactNode } from 'react';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Tooltip, Legend, Filler);

export const chartColors = {
    sales: '#254536',
    collections: '#9b7b3f',
    outgoing: '#b15f4b',
    unpaid: '#b15f4b',
    partial: '#d09a47',
    paid: '#4f7c65',
    muted: '#d8d8ce',
};

type TrendPoint = { label: string; sales: string; collections: string };
type FlowPoint = { label: string; incoming: string; outgoing: string };
type RankedPoint = { label: string; value: string };

const compactMoney = (value: number) => new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
const baseOptions: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 18 } },
        tooltip: { callbacks: { label: (item: TooltipItem<'line'>) => `${item.dataset.label}: ${formatMoney(item.parsed.y)}` } },
    },
    scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
        y: { beginAtZero: true, ticks: { callback: (value) => compactMoney(Number(value)) } },
    },
};

export function ChartPanel({ title, description, children, empty = false, emptyLabel = 'Aucune donnée sur cette période.', className = '' }: {
    title: string; description?: string; children: ReactNode; empty?: boolean; emptyLabel?: string; className?: string;
}) {
    return (
        <section className={`rounded-card border border-line bg-surface p-5 shadow-card ${className}`} aria-label={title}>
            <div className="mb-4">
                <h2 className="font-semibold text-ink">{title}</h2>
                {description && <p className="mt-1 text-[13px] text-ink-muted">{description}</p>}
            </div>
            {empty ? <div className="grid h-64 place-items-center rounded-field bg-raised text-sm text-ink-faint">{emptyLabel}</div> : children}
        </section>
    );
}

export function SalesCollectionsChart({ points }: { points: TrendPoint[] }) {
    const empty = points.every((point) => Number(point.sales) === 0 && Number(point.collections) === 0);
    return (
        <ChartPanel title="Évolution des ventes et encaissements" description="Ventes selon sale_date · encaissements selon payment_date" empty={empty} emptyLabel="Aucune vente ni encaissement sur cette période.">
            <div className="h-72 sm:h-80">
                <Line
                    aria-label="Courbes des ventes nettes et encaissements nets"
                    data={{
                        labels: points.map((point) => point.label),
                        datasets: [
                            { label: 'Ventes nettes', data: points.map((point) => Number(point.sales)), borderColor: chartColors.sales, backgroundColor: 'rgba(37,69,54,.10)', fill: true, tension: 0.3, pointRadius: 2 },
                            { label: 'Encaissements nets', data: points.map((point) => Number(point.collections)), borderColor: chartColors.collections, backgroundColor: 'rgba(155,123,63,.08)', tension: 0.3, pointRadius: 2 },
                        ],
                    }}
                    options={baseOptions}
                />
            </div>
        </ChartPanel>
    );
}

export function OrderStatesChart({ states }: { states: { paid: number; partially_paid: number; unpaid: number } }) {
    const values = [states.paid, states.partially_paid, states.unpaid];
    return (
        <ChartPanel title="État des commandes" description="Commandes confirmées de la période" empty={values.every((value) => value === 0)} emptyLabel="Aucune commande confirmée sur cette période.">
            <div className="mx-auto h-64 max-w-sm">
                <Doughnut
                    aria-label="Répartition des commandes par statut de paiement"
                    data={{ labels: ['Payées', 'Partiellement payées', 'Non payées'], datasets: [{ data: values, backgroundColor: [chartColors.paid, chartColors.partial, chartColors.unpaid], borderWidth: 0 }] }}
                    options={{ responsive: true, maintainAspectRatio: false, cutout: '66%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: (item) => `${item.label}: ${formatQuantity(item.parsed)}` } } } }}
                />
            </div>
        </ChartPanel>
    );
}

export function RankedMoneyChart({ title, description, points, emptyLabel = 'Aucune donnée sur cette période.' }: { title: string; description?: string; points: RankedPoint[]; emptyLabel?: string }) {
    return (
        <ChartPanel title={title} description={description} empty={points.length === 0 || points.every((point) => Number(point.value) === 0)} emptyLabel={emptyLabel}>
            <div className="h-64 sm:h-72">
                <Bar
                    aria-label={title}
                    data={{ labels: points.map((point) => point.label), datasets: [{ label: 'Montant', data: points.map((point) => Number(point.value)), backgroundColor: chartColors.sales, borderRadius: 5 }] }}
                    options={{ indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (item) => formatMoney(item.parsed.x) } } }, scales: { x: { beginAtZero: true, ticks: { callback: (value) => compactMoney(Number(value)) } }, y: { grid: { display: false } } } }}
                />
            </div>
        </ChartPanel>
    );
}

export function CashFlowChart({ points }: { points: FlowPoint[] }) {
    const empty = points.every((point) => Number(point.incoming) === 0 && Number(point.outgoing) === 0);
    return (
        <ChartPanel title="Flux de trésorerie" description="Mouvements réels uniquement" empty={empty} emptyLabel="Aucun mouvement de trésorerie sur cette période.">
            <div className="h-64 sm:h-72">
                <Bar
                    aria-label="Entrées et sorties réelles de trésorerie"
                    data={{ labels: points.map((point) => point.label), datasets: [
                        { label: 'Entrées réelles', data: points.map((point) => Number(point.incoming)), backgroundColor: chartColors.collections, borderRadius: 4 },
                        { label: 'Sorties réelles', data: points.map((point) => Number(point.outgoing)), backgroundColor: chartColors.outgoing, borderRadius: 4 },
                    ] }}
                    options={{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: (item) => `${item.dataset.label}: ${formatMoney(item.parsed.y)}` } } }, scales: { x: { stacked: false, grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { callback: (value) => compactMoney(Number(value)) } } } }}
                />
            </div>
        </ChartPanel>
    );
}
