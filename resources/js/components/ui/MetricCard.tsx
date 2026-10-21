import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Skeleton } from './Skeleton';

export type MetricTone = 'neutral' | 'positive' | 'negative' | 'warning' | 'forecast';
export type MetricLevel = 'primary' | 'secondary';

const tones: Record<MetricTone, { border: string; surface: string; value: string; accent: string }> = {
    neutral: { border: 'border-line', surface: 'bg-surface', value: 'text-ink', accent: 'bg-primary' },
    positive: { border: 'border-success/20', surface: 'bg-success-soft/55', value: 'finance-inflow', accent: 'bg-success' },
    negative: { border: 'border-danger/20', surface: 'bg-danger-soft/55', value: 'finance-outflow', accent: 'bg-danger' },
    warning: { border: 'border-warning/20', surface: 'bg-warning-soft/55', value: 'text-warning', accent: 'bg-warning' },
    forecast: { border: 'border-info/20', surface: 'bg-info-soft/55', value: 'finance-forecast', accent: 'bg-info' },
};

export function MetricCard({ label, value, helper, trend, icon, tone = 'neutral', level = 'primary', href, loading = false, unconfigured = false }: {
    label: string;
    value: ReactNode;
    helper?: ReactNode;
    trend?: ReactNode;
    icon?: ReactNode;
    tone?: MetricTone;
    level?: MetricLevel;
    href?: string;
    loading?: boolean;
    unconfigured?: boolean;
}) {
    const style = tones[tone];
    const content = (
        <div className={`relative h-full overflow-hidden rounded-card border ${style.border} ${style.surface} ${level === 'primary' ? 'p-5 shadow-card' : 'px-4 py-3'} transition-soft hover:border-line-strong`}>
            {level === 'primary' && <span aria-hidden="true" className={`absolute inset-y-0 start-0 w-1 ${style.accent}`} />}
            <div className="flex items-start justify-between gap-3">
                <p className="text-[11px] font-semibold uppercase tracking-[0.055em] text-ink-faint">{label}</p>
                {icon && <span className="text-ink-faint" aria-hidden="true">{icon}</span>}
            </div>
            {loading ? <Skeleton className="mt-3 h-9 w-2/3" /> : (
                <p className={`metric-value ${level === 'primary' ? 'mt-3 text-3xl font-semibold tracking-tight' : 'mt-1.5 text-base font-semibold'} ${unconfigured ? 'text-ink-faint' : style.value}`} dir="ltr">
                    {value}
                </p>
            )}
            {(helper || trend) && <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-[12px] text-ink-muted"><span>{helper}</span>{trend && <span className="font-medium">{trend}</span>}</div>}
        </div>
    );

    return href ? <Link href={href} className="block h-full">{content}</Link> : content;
}
