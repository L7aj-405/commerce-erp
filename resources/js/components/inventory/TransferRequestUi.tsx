import { useI18n } from '@/i18n';
import type { ReactNode } from 'react';

/**
 * Shared visual vocabulary of the transfer screens: status, type, priority,
 * lifecycle and route. Every badge carries a text label (and, for type and
 * priority, a glyph) so meaning never relies on colour alone.
 */

export type TransferStatus = 'requested' | 'preparing' | 'shipped' | 'received' | 'cancelled';
export type TransferType = 'order' | 'replenishment' | 'manual';
export type TransferPriority = 'high' | 'normal';
export type WarehouseRef = { id: number; name: string; code: string } | null;

const statusStyle: Record<string, string> = {
    requested: 'border-warning/25 bg-warning-soft text-warning',
    preparing: 'border-info/25 bg-info-soft text-info',
    shipped: 'border-primary/25 bg-sage text-primary',
    received: 'border-success/25 bg-success-soft text-success',
    cancelled: 'border-line bg-raised text-ink-muted',
};

const statusDot: Record<string, string> = {
    requested: 'bg-warning',
    preparing: 'bg-info',
    shipped: 'bg-primary',
    received: 'bg-success',
    cancelled: 'bg-ink-faint',
};

export function useTransferLabels() {
    const { t } = useI18n();
    return {
        status: {
            requested: t('inventory.requests.requested'),
            preparing: t('inventory.requests.preparing'),
            shipped: t('inventory.requests.shipped'),
            received: t('inventory.requests.received'),
            cancelled: t('inventory.requests.cancelled'),
        } as Record<string, string>,
        type: {
            order: t('inventory.trq.typeOrder'),
            replenishment: t('inventory.trq.typeReplenishment'),
            manual: t('inventory.trq.typeManual'),
        } as Record<string, string>,
        reason: {
            order_fulfillment: t('inventory.trq.reasonOrder'),
            minimum_replenishment: t('inventory.trq.reasonReplenishment'),
            manual: t('inventory.trq.reasonManual'),
        } as Record<string, string>,
    };
}

export function TransferStatusBadge({ status, className = '' }: { status: string; className?: string }) {
    const labels = useTransferLabels();
    return (
        <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold ${statusStyle[status] ?? statusStyle.cancelled} ${className}`}>
            <span aria-hidden="true" className={`size-1.5 rounded-full ${statusDot[status] ?? 'bg-ink-faint'}`} />
            {labels.status[status] ?? status}
        </span>
    );
}

const typeGlyph: Record<string, string> = { order: '◆', replenishment: '↻', manual: '✎' };
const typeStyle: Record<string, string> = {
    order: 'border-primary/30 bg-primary text-primary-fg',
    replenishment: 'border-line-strong bg-surface text-ink',
    manual: 'border-line bg-raised text-ink-muted',
};

export function TransferTypeBadge({ type, className = '' }: { type: string; className?: string }) {
    const labels = useTransferLabels();
    return (
        <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-2 py-0.5 text-[11px] font-semibold ${typeStyle[type] ?? typeStyle.manual} ${className}`}>
            <span aria-hidden="true">{typeGlyph[type] ?? '•'}</span>
            {labels.type[type] ?? type}
        </span>
    );
}

export function PriorityBadge({ priority, className = '' }: { priority: string; className?: string }) {
    const { t } = useI18n();
    if (priority !== 'high') {
        return <span className={`text-xs text-ink-faint ${className}`}>{t('inventory.trq.priorityNormal')}</span>;
    }
    return (
        <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-danger/25 bg-danger-soft px-2 py-0.5 text-[11px] font-semibold text-danger ${className}`}>
            <span aria-hidden="true">▲</span>
            {t('inventory.trq.priorityHigh')}
        </span>
    );
}

export function RouteLabel({ source, destination, compact = false }: { source: WarehouseRef; destination: WarehouseRef; compact?: boolean }) {
    return (
        <span className="inline-flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
            <span className="min-w-0 truncate font-medium text-ink">{source?.name ?? '—'}</span>
            <span aria-hidden="true" className="inline-block text-ink-faint rtl:-scale-x-100">→</span>
            <span className="min-w-0 truncate font-medium text-ink">{destination?.name ?? '—'}</span>
            {!compact && (source?.code || destination?.code) && (
                <span className="w-full text-xs text-ink-faint" dir="ltr">
                    {source?.code ?? '—'} → {destination?.code ?? '—'}
                </span>
            )}
        </span>
    );
}

const steps: TransferStatus[] = ['requested', 'preparing', 'shipped', 'received'];

/** Demandée → Préparation → Expédiée → Réceptionnée, with who/when under each step. */
export function LifecycleProgress({ status, events }: { status: string; events: Partial<Record<TransferStatus, { at: string | null; by?: string | null }>> }) {
    const { t } = useI18n();
    const labels: Record<TransferStatus, string> = {
        requested: t('inventory.trq.stepRequested'),
        preparing: t('inventory.trq.stepPreparing'),
        shipped: t('inventory.trq.stepShipped'),
        received: t('inventory.trq.stepReceived'),
        cancelled: t('inventory.requests.cancelled'),
    };
    const cancelled = status === 'cancelled';
    const currentIndex = cancelled ? -1 : steps.indexOf(status as TransferStatus);

    return (
        <ol className="grid grid-cols-4 gap-1.5 sm:gap-3" aria-label={t('inventory.trq.lifecycle')}>
            {steps.map((step, index) => {
                const done = !cancelled && index < currentIndex;
                const current = !cancelled && index === currentIndex;
                const reached = Boolean(events[step]?.at);
                return (
                    <li key={step} className="min-w-0" aria-current={current ? 'step' : undefined}>
                        <div className={`h-1.5 rounded-full ${done || (current && step === 'received') ? 'bg-success' : current ? 'bg-primary' : cancelled && reached ? 'bg-ink-faint/50' : 'bg-line'}`} />
                        <p className={`mt-2 flex items-center gap-1 truncate text-xs ${current ? 'font-semibold text-ink' : done ? 'font-medium text-ink-muted' : 'text-ink-faint'}`}>
                            <span aria-hidden="true" className="hidden sm:inline">{done || (current && step === 'received') ? '✓' : `${index + 1}.`}</span>
                            {labels[step]}
                        </p>
                        {events[step]?.at && (
                            <p className="hidden truncate text-[11px] text-ink-faint sm:block">
                                {new Date(events[step]!.at as string).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })}
                                {events[step]?.by ? ` · ${events[step]?.by}` : ''}
                            </p>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

export function SectionCard({ title, description, actions, children, className = '', bodyClassName = 'p-4 sm:p-5' }: {
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
}) {
    return (
        <section className={`overflow-hidden rounded-card border border-line bg-surface shadow-soft ${className}`}>
            {(title || actions) && (
                <header className="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
                    <div className="min-w-0">
                        {title && <h2 className="text-sm font-semibold text-ink">{title}</h2>}
                        {description && <p className="mt-0.5 text-xs text-ink-muted">{description}</p>}
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
                </header>
            )}
            <div className={bodyClassName}>{children}</div>
        </section>
    );
}

export function Notice({ tone = 'info', title, children }: { tone?: 'info' | 'warning' | 'danger' | 'success'; title?: ReactNode; children?: ReactNode }) {
    const styles = {
        info: 'border-info/25 bg-info-soft text-info',
        warning: 'border-warning/30 bg-warning-soft text-warning',
        danger: 'border-danger/30 bg-danger-soft text-danger',
        success: 'border-success/25 bg-success-soft text-success',
    };
    const glyph = { info: 'i', warning: '!', danger: '!', success: '✓' };
    return (
        <div className={`flex gap-3 rounded-card border px-4 py-3 text-sm ${styles[tone]}`} role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'}>
            <span aria-hidden="true" className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border border-current text-[11px] font-bold">{glyph[tone]}</span>
            <div className="min-w-0">
                {title && <p className="font-semibold">{title}</p>}
                {children && <div className={`${title ? 'mt-0.5' : ''} text-ink-muted`}>{children}</div>}
            </div>
        </div>
    );
}
