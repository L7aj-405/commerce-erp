import { useI18n } from '@/i18n';
import { router } from '@inertiajs/react';
import type { MouseEvent, ReactNode } from 'react';
import Pagination from './Pagination';
import { Button } from './Button';
import { SkeletonRows } from './Skeleton';

/**
 * Wrapper for `<table className="erp-table">`: solid surface, horizontal scroll
 * on narrow screens (never page overflow), keyboard-scrollable region, and the
 * standard loading / error / empty / forbidden states so tables never render blank.
 */
export function TableShell({
    label,
    toolbar,
    footer,
    state = 'ready',
    emptyTitle,
    emptyDescription,
    emptyAction,
    errorMessage,
    onRetry,
    stickyHeader = false,
    busy = false,
    maxHeight,
    className = '',
    children,
}: {
    label: string;
    toolbar?: ReactNode;
    footer?: ReactNode;
    state?: 'ready' | 'loading' | 'empty' | 'error' | 'forbidden';
    emptyTitle?: string;
    emptyDescription?: string;
    emptyAction?: ReactNode;
    errorMessage?: string;
    onRetry?: () => void;
    stickyHeader?: boolean;
    /** Results are being refreshed (filters/pagination): dims rows, keeps layout stable. */
    busy?: boolean;
    maxHeight?: string;
    className?: string;
    children?: ReactNode;
}) {
    const { t } = useI18n();
    const message = (title: string, description?: string, action?: ReactNode, tone: 'neutral' | 'danger' = 'neutral') => (
        <div className="px-6 py-12 text-center" role={tone === 'danger' ? 'alert' : 'status'}>
            <p className={`text-base font-semibold ${tone === 'danger' ? 'text-danger' : 'text-ink'}`}>{title}</p>
            {description && <p className="mx-auto mt-1.5 max-w-md text-sm leading-6 text-ink-muted">{description}</p>}
            {action && <div className="mt-4 flex justify-center gap-2">{action}</div>}
        </div>
    );

    return (
        <section aria-label={label} className={`surface-card min-w-0 overflow-hidden ${className}`}>
            {toolbar && <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">{toolbar}</div>}
            {state === 'loading' ? (
                <div className="p-4" role="status" aria-label={t('common.loading')}>
                    <SkeletonRows rows={6} />
                </div>
            ) : state === 'error' ? (
                message(errorMessage ?? t('ui.loadError'), undefined, onRetry && <Button variant="secondary" onClick={onRetry}>{t('ui.retry')}</Button>, 'danger')
            ) : state === 'forbidden' ? (
                message(t('ui.forbidden'))
            ) : state === 'empty' ? (
                message(emptyTitle ?? t('ui.noResults'), emptyDescription, emptyAction)
            ) : (
                <div
                    tabIndex={0}
                    role="region"
                    aria-label={label}
                    aria-busy={busy || undefined}
                    className={`relative overflow-x-auto transition-opacity focus-visible:outline-offset-[-2px] ${busy ? 'opacity-55' : ''} ${stickyHeader ? 'overflow-y-auto [&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-[1] [&_thead_th]:bg-raised' : ''}`}
                    style={maxHeight ? { maxHeight } : undefined}
                >
                    {children}
                </div>
            )}
            {footer && <div className="border-t border-line px-4 py-3">{footer}</div>}
        </section>
    );
}

/**
 * Whole-row navigation for mouse users. The row's first cell must still contain a
 * real <Link> (keyboard + screen readers); clicks on links, buttons, inputs or
 * text selections inside the row are left alone.
 */
export function rowLink(href: string) {
    return {
        onClick: (event: MouseEvent<HTMLTableRowElement>) => {
            const target = event.target as HTMLElement;
            if (target.closest('a, button, input, select, textarea, label, [role="menu"]')) return;
            if (window.getSelection()?.toString()) return;
            if (event.metaKey || event.ctrlKey) {
                window.open(href, '_blank', 'noopener');
                return;
            }
            router.visit(href);
        },
        className: 'cursor-pointer',
    };
}

/** Footer for paginated tables: real paginator range + page links. */
export function TablePager({ links, from, to, total }: { links: { url: string | null; label: string; active: boolean }[]; from?: number | null; to?: number | null; total?: number }) {
    const { t } = useI18n();
    const showLinks = links.length > 3;
    if (!showLinks && !total) return null;
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-[13px] text-ink-muted" aria-live="polite">
                {total !== undefined && from != null && to != null
                    ? t('table.range').replace('{from}', String(from)).replace('{to}', String(to)).replace('{total}', String(total))
                    : null}
            </p>
            {showLinks && <Pagination links={links} className="mt-0" />}
        </div>
    );
}
