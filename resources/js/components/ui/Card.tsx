import type { ReactNode } from 'react';

/**
 * Solid data surface (never glass). Optional header with title, description and
 * actions; `padding="none"` for tables that run edge to edge.
 */
export function Card({
    title,
    description,
    actions,
    footer,
    padding = 'md',
    as: Tag = 'section',
    className = '',
    children,
}: {
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    footer?: ReactNode;
    padding?: 'none' | 'sm' | 'md';
    as?: 'section' | 'div' | 'article';
    className?: string;
    children?: ReactNode;
}) {
    const pad = padding === 'none' ? '' : padding === 'sm' ? 'p-4' : 'p-5';
    const hasHeader = title || description || actions;
    return (
        <Tag className={`surface-card min-w-0 overflow-hidden ${className}`}>
            {hasHeader && (
                <header className={`flex flex-wrap items-start justify-between gap-3 ${padding === 'none' ? 'border-b border-line px-5 py-4' : `${pad} pb-0`}`}>
                    <div className="min-w-0">
                        {title && <h2 className="text-base font-semibold text-ink">{title}</h2>}
                        {description && <p className="mt-0.5 text-sm leading-5 text-ink-muted">{description}</p>}
                    </div>
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </header>
            )}
            {children !== undefined && <div className={hasHeader && padding !== 'none' ? `${pad} pt-4` : pad}>{children}</div>}
            {footer && <footer className="border-t border-line bg-raised px-5 py-3">{footer}</footer>}
        </Tag>
    );
}
