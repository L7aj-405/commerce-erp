import type { ReactNode } from 'react';

/**
 * Honest empty state: says what is missing and offers the next real action.
 * `compact` fits inside cards/tables; default is a full section.
 */
export default function EmptyState({
    title,
    description,
    actions,
    icon,
    compact = false,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    icon?: ReactNode;
    compact?: boolean;
}) {
    return (
        <section className={`rounded-card border border-dashed border-line-strong bg-raised text-center ${compact ? 'px-5 py-8' : 'px-6 py-14'}`}>
            <div className="mx-auto max-w-lg">
                {icon && (
                    <span aria-hidden="true" className="mx-auto mb-4 grid size-11 place-items-center rounded-xl border border-line bg-surface text-ink-muted [&_svg]:size-5">
                        {icon}
                    </span>
                )}
                <h2 className={`${compact ? 'text-base' : 'text-lg'} font-semibold text-ink`}>{title}</h2>
                {description && <p className="mt-2 text-sm leading-6 text-ink-muted">{description}</p>}
                {actions && <div className="mt-6 flex flex-wrap justify-center gap-2">{actions}</div>}
            </div>
        </section>
    );
}
