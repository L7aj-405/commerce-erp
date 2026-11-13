import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import type { ReactNode } from 'react';

export type StatusVariant = 'success' | 'warning' | 'danger' | 'neutral' | 'info' | 'accent';

/*
 * One badge style for the whole ERP. Tinted fill + 1px tone border + a dot, so
 * badges stay distinct on white and dark surfaces; the text label always states
 * the status (color is never the only signal).
 */
const styles: Record<StatusVariant, { pill: string; dot: string }> = {
    success: { pill: 'border-success/25 bg-success-soft text-success', dot: 'bg-success' },
    warning: { pill: 'border-warning/30 bg-warning-soft text-warning', dot: 'bg-warning' },
    danger: { pill: 'border-danger/25 bg-danger-soft text-danger', dot: 'bg-danger' },
    neutral: { pill: 'border-line-strong bg-sage text-ink-muted', dot: 'bg-ink-faint' },
    info: { pill: 'border-info/25 bg-info-soft text-info', dot: 'bg-info' },
    accent: { pill: 'border-accent/25 bg-accent-soft text-accent', dot: 'bg-accent' },
};

export function Badge({
    variant = 'neutral',
    dot = true,
    size = 'md',
    className = '',
    children,
}: {
    variant?: StatusVariant;
    dot?: boolean;
    size?: 'sm' | 'md';
    className?: string;
    children: ReactNode;
}) {
    const style = styles[variant];
    return (
        <span
            className={`inline-flex max-w-full items-center gap-1.5 rounded-full border font-semibold whitespace-nowrap ${
                size === 'sm' ? 'px-2 py-px text-[11.5px]' : 'px-2.5 py-0.5 text-xs'
            } ${style.pill} ${className}`}
        >
            {dot && <span aria-hidden="true" className={`size-1.5 shrink-0 rounded-full ${style.dot}`} />}
            <span className="truncate">{children}</span>
        </span>
    );
}

const success = new Set(['active', 'ready', 'imported', 'completed', 'paid', 'confirmed', 'posted', 'issued', 'fulfilled', 'validated']);
const warning = new Set(['warning', 'pending', 'processing', 'previewed', 'uploaded', 'partially_paid', 'partial', 'upcoming']);
const danger = new Set(['error', 'failed', 'cancelled', 'suspended', 'unpaid', 'overdue', 'reversed', 'completed_with_errors']);
const neutral = new Set(['inactive', 'draft', 'skipped', 'archived']);
const translatedStatuses = new Set(['active','inactive','suspended','ready','warning','skipped','error','failed','imported','uploaded','previewed','processing','completed','completed_with_errors','paid','partially_paid','unpaid','cancelled','draft','confirmed','pending','posted','reversed','partial','issued','fulfilled','validated','upcoming','overdue','archived']);

export function statusVariant(status: string): StatusVariant {
    if (success.has(status)) return 'success';
    if (warning.has(status)) return 'warning';
    if (danger.has(status)) return 'danger';
    if (neutral.has(status)) return 'neutral';
    return 'info';
}

export default function StatusBadge({ status, variant, children, className = '' }: { status?: string; variant?: StatusVariant; children?: ReactNode; className?: string }) {
    const { t } = useI18n();
    const resolved = variant ?? statusVariant(status ?? '');
    const label = children ?? (status && translatedStatuses.has(status) ? t(`status.${status}` as TranslationKey) : status?.replaceAll('_', ' ')) ?? '—';
    return (
        <Badge variant={resolved} className={className}>
            {label}
        </Badge>
    );
}
