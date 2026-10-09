/** KPI card used across the commission dashboard, drill-down and own view. */
export default function CommissionMetric({ label, value, hint, tone, strong = false }: { label: string; value: string; hint?: string; tone?: 'danger'; strong?: boolean }) {
    return (
        <div className={`rounded-card border bg-surface p-4 ${strong ? 'border-primary' : 'border-line'}`}>
            <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-faint">{label}</p>
            <p className={`mt-1 text-lg font-semibold ${tone === 'danger' ? 'text-danger' : 'text-ink'}`}>{value}</p>
            {hint && <p className="mt-0.5 text-[11px] text-ink-faint">{hint}</p>}
        </div>
    );
}
