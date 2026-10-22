import { useEffect, useId, useRef } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent, MouseEvent as ReactMouseEvent, ReactNode } from 'react';
import { useI18n } from '@/i18n';

export function Dialog({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    dismissible = true,
    danger = false,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    dismissible?: boolean;
    danger?: boolean;
}) {
    const { direction, t } = useI18n();
    const titleId = useId();
    const descriptionId = useId();
    const dialogRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const previousOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        window.setTimeout(() => {
            const target = dialogRef.current?.querySelector<HTMLElement>('[data-autofocus], input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
            target?.focus();
        }, 0);

        return () => {
            document.documentElement.style.overflow = previousOverflow;
            previouslyFocused?.focus();
        };
    }, [open]);

    if (!open) return null;

    const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape' && dismissible) {
            event.preventDefault();
            onClose();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(dialogRef.current?.querySelectorAll<HTMLElement>(
            'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
        ) ?? []).filter((element) => !element.hidden);
        if (focusable.length === 0) {
            event.preventDefault();
            dialogRef.current?.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const onBackdropMouseDown = (event: ReactMouseEvent<HTMLDivElement>) => {
        if (dismissible && event.target === event.currentTarget) onClose();
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-ink/45 p-2 sm:items-center sm:p-6"
            onMouseDown={onBackdropMouseDown}
            dir={direction}
        >
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={description ? descriptionId : undefined}
                tabIndex={-1}
                onKeyDown={onKeyDown}
                className={`flex max-h-[calc(100dvh-1rem)] w-full max-w-3xl flex-col overflow-hidden rounded-t-panel border bg-surface shadow-pop sm:max-h-[90dvh] sm:rounded-panel ${danger ? 'border-danger/40' : 'border-line'}`}
            >
                <header className={`flex shrink-0 items-start justify-between gap-4 border-b px-4 py-4 sm:px-6 ${danger ? 'border-danger/20 bg-danger-soft/45' : 'border-line'}`}>
                    <div className="min-w-0">
                        <h2 id={titleId} className={`text-lg font-semibold ${danger ? 'text-danger' : 'text-ink'}`}>{title}</h2>
                        {description && <p id={descriptionId} className="mt-1 text-sm leading-5 text-ink-muted">{description}</p>}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={!dismissible}
                        aria-label={t('common.close')}
                        className="flex size-9 shrink-0 items-center justify-center rounded-field text-ink-muted transition-soft hover:bg-sage hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                {footer && <footer className="shrink-0 border-t border-line bg-raised px-4 py-3 sm:px-6">{footer}</footer>}
            </div>
        </div>
    );
}
