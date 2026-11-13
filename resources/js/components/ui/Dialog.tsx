import { useFocusTrap } from '@/hooks/useFocusTrap';
import { useI18n } from '@/i18n';
import { useId, useRef } from 'react';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';

const widths = {
    sm: 'max-w-md',
    md: 'max-w-xl',
    lg: 'max-w-3xl',
    xl: 'max-w-5xl',
} as const;

/**
 * Modal dialog. Bottom sheet on phones, centered from `sm:`. Focus is trapped,
 * restored on close, Escape/backdrop close it when `dismissible`.
 * Use `size="sm"` for confirmations, the default `lg` for forms.
 */
export function Dialog({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    dismissible = true,
    danger = false,
    size = 'lg',
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    dismissible?: boolean;
    danger?: boolean;
    size?: keyof typeof widths;
}) {
    const { direction, t } = useI18n();
    const titleId = useId();
    const descriptionId = useId();
    const dialogRef = useRef<HTMLDivElement>(null);
    useFocusTrap(open, dialogRef, { onEscape: dismissible ? onClose : undefined });

    if (!open) return null;

    const onBackdropMouseDown = (event: ReactMouseEvent<HTMLDivElement>) => {
        if (dismissible && event.target === event.currentTarget) onClose();
    };

    return (
        <div
            className="animate-overlay-in fixed inset-0 z-[60] flex items-end justify-center bg-ink/45 p-2 sm:items-center sm:p-6"
            onMouseDown={onBackdropMouseDown}
            dir={direction}
        >
            <div
                ref={dialogRef}
                role={danger ? 'alertdialog' : 'dialog'}
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={description ? descriptionId : undefined}
                tabIndex={-1}
                className={`animate-panel-in flex max-h-[calc(100dvh-1rem)] w-full ${widths[size]} flex-col overflow-hidden rounded-t-panel border bg-surface shadow-pop focus:outline-none sm:max-h-[90dvh] sm:rounded-panel ${danger ? 'border-danger/40' : 'border-line'}`}
            >
                <header className={`flex shrink-0 items-start justify-between gap-4 border-b px-4 py-4 sm:px-6 ${danger ? 'border-danger/20 bg-danger-soft' : 'border-line'}`}>
                    <div className="min-w-0">
                        <h2 id={titleId} className={`text-lg font-semibold ${danger ? 'text-danger' : 'text-ink'}`}>
                            {title}
                        </h2>
                        {description && (
                            <p id={descriptionId} className="mt-1 text-sm leading-6 text-ink-muted">
                                {description}
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        data-dismiss
                        onClick={onClose}
                        disabled={!dismissible}
                        aria-label={t('common.close')}
                        className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                {footer && <footer className="shrink-0 border-t border-line bg-raised px-4 py-3 sm:px-6">{footer}</footer>}
            </div>
        </div>
    );
}
