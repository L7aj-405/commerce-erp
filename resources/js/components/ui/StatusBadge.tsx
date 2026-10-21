import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import type { ReactNode } from 'react';

export type StatusVariant = 'success' | 'warning' | 'danger' | 'neutral' | 'info';

const styles: Record<StatusVariant, string> = {
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    neutral: 'bg-raised text-ink-muted',
    info: 'bg-info-soft text-info',
};

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
    return <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${styles[resolved]} ${className}`}>{label}</span>;
}
