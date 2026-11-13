import { useFocusTrap } from '@/hooks/useFocusTrap';
import { useI18n } from '@/i18n';
import { useId, useRef } from 'react';
import type { ReactNode } from 'react';

type Side = 'left' | 'right' | 'bottom';

/**
 * Shared overlay panel for mobile-first drawers, filter panels and bottom
 * sheets. `side="bottom"` is the usual choice on phones (thumb-reachable,
 * capped at 85dvh with internal scroll); `left`/`right` behave like a classic
 * slide-over. Focus is trapped and restored; Escape and the backdrop close it.
 */
export function Sheet({
    open,
    onClose,
    side = 'right',
    title,
    description,
    children,
    widthClassName = 'max-w-md',
    footer,
}: {
    open: boolean;
    onClose: () => void;
    side?: Side;
    title?: string;
    description?: string;
    children: ReactNode;
    widthClassName?: string;
    footer?: ReactNode;
}) {
    const { direction, t } = useI18n();
    const titleId = useId();
    const panelRef = useRef<HTMLDivElement>(null);
    useFocusTrap(open, panelRef, { onEscape: onClose });

    if (!open) return null;

    const effectiveSide = direction === 'rtl' && side !== 'bottom' ? (side === 'right' ? 'left' : 'right') : side;
    const wrapperPosition: Record<Side, string> = {
        right: 'justify-end',
        left: 'justify-start',
        bottom: 'items-end',
    };
    const panelShape: Record<Side, string> = {
        right: `h-full w-full ${widthClassName}`,
        left: `h-full w-full ${widthClassName}`,
        bottom: 'w-full max-h-[85dvh] rounded-t-panel',
    };

    const closeButton = (
        <button
            type="button"
            data-dismiss
            onClick={onClose}
            aria-label={t('common.close')}
            className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink"
        >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12" />
            </svg>
        </button>
    );

    return (
        <div className={`animate-overlay-in fixed inset-0 z-[60] flex ${wrapperPosition[effectiveSide]}`} dir={direction}>
            <button type="button" tabIndex={-1} className="absolute inset-0 cursor-default bg-ink/40" onClick={onClose} aria-label={t('common.close')} />
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                aria-label={title ? undefined : t('common.close')}
                tabIndex={-1}
                className={`animate-panel-in relative flex min-h-0 flex-col overflow-hidden bg-surface shadow-pop focus:outline-none ${panelShape[effectiveSide]}`}
            >
                {title || description ? (
                    <div className="flex shrink-0 items-start justify-between gap-3 border-b border-line px-4 py-3.5">
                        <div className="min-w-0">
                            {title && (
                                <h2 id={titleId} className="text-base font-semibold text-ink">
                                    {title}
                                </h2>
                            )}
                            {description && <p className="mt-0.5 text-[13px] leading-5 text-ink-muted">{description}</p>}
                        </div>
                        {closeButton}
                    </div>
                ) : null /* untitled sheets (e.g. POS filters) close via footer, Escape or backdrop */}
                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                {footer && <div className="shrink-0 border-t border-line bg-raised px-4 py-3">{footer}</div>}
            </div>
        </div>
    );
}
