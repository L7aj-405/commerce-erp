import { useEffect } from 'react';
import type { ReactNode } from 'react';

type Side = 'left' | 'right' | 'bottom';

/**
 * Shared overlay panel for mobile-first drawers, filter panels and bottom
 * sheets. `side="bottom"` is the usual choice on phones (thumb-reachable,
 * capped at 85dvh with internal scroll); `left`/`right` behave like a classic
 * slide-over and are what desktop-triggered panels usually want.
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
    useEffect(() => {
        if (!open) return;
        const previousOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        const handler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', handler);
        return () => {
            document.documentElement.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handler);
        };
    }, [open, onClose]);

    if (!open) return null;

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

    return (
        <div className={`fixed inset-0 z-40 flex ${wrapperPosition[side]}`} role="dialog" aria-modal="true">
            <button type="button" className="absolute inset-0 bg-ink/40" onClick={onClose} aria-label="Fermer" />
            <div className={`relative flex min-h-0 flex-col overflow-hidden bg-surface shadow-pop ${panelShape[side]}`}>
                {(title || description) && (
                    <div className="flex shrink-0 items-start justify-between gap-3 border-b border-line px-4 py-3.5">
                        <div className="min-w-0">
                            {title && <h2 className="text-base font-semibold text-ink">{title}</h2>}
                            {description && <p className="mt-0.5 text-[13px] text-ink-muted">{description}</p>}
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Fermer"
                            className="flex size-9 shrink-0 items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M18 6 6 18M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                )}
                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                {footer && <div className="shrink-0 border-t border-line bg-raised px-4 py-3">{footer}</div>}
            </div>
        </div>
    );
}
