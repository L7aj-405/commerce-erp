/** KPI card used across the commission dashboard, drill-down and own view. */
export default function CommissionMetric({ label, value, hint, tone, strong = false }: { label: string; value: string; hint?: string; tone?: 'danger'; strong?: boolean }) {
    return (
        <div className={`surface-card p-4 ${strong ? 'ring-1 ring-primary/45' : ''}`}>
            <p className="truncate text-[13px] font-medium text-ink-muted">{label}</p>
            <p className={`metric-value mt-1 text-xl font-bold tracking-[-0.02em] ${tone === 'danger' ? 'text-danger' : 'text-ink'}`}>
                <bdi>{value}</bdi>
            </p>
            {hint && <p className="mt-0.5 text-xs text-ink-muted">{hint}</p>}
        </div>
    );
}
