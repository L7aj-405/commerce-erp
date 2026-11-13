import type { ReactNode } from 'react';

/*
 * Panel primitives from the approved Liquid Glass reference:
 *  - PanelTitle: a section heading led by a small tinted icon tile (28px), with an
 *    optional one-line description. Accent colours stay in the tile, not the text.
 *  - EmptyWell: the quiet inset empty state — a modest pictogram, one message and
 *    one explanation — instead of a sterile blank rectangle.
 */

export type PanelTone = 'teal' | 'amber' | 'blue' | 'green' | 'rose';

const toneClass: Record<PanelTone, string> = {
    teal: 'bg-accent-soft text-accent',
    amber: 'bg-amber-soft text-amber',
    blue: 'bg-blue-soft text-blue',
    green: 'bg-mint-soft text-mint',
    rose: 'bg-rose-soft text-rose',
};

export function PanelTitle({
    id,
    icon,
    title,
    description,
    tone = 'teal',
    as: Tag = 'h2',
    aside,
}: {
    id?: string;
    icon?: ReactNode;
    title: string;
    description?: string;
    tone?: PanelTone;
    as?: 'h2' | 'h3';
    aside?: ReactNode;
}) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div className="flex min-w-0 items-start gap-2.5">
                {icon && (
                    <span aria-hidden="true" className={`mt-px grid size-7 shrink-0 place-items-center rounded-[9px] [&_svg]:size-4 ${toneClass[tone]}`}>
                        {icon}
                    </span>
                )}
                <div className="min-w-0">
                    <Tag id={id} className="text-base leading-snug font-bold tracking-[-0.01em] text-ink">
                        {title}
                    </Tag>
                    {description && <p className="mt-0.5 text-[13px] leading-snug text-ink-muted">{description}</p>}
                </div>
            </div>
            {aside}
        </div>
    );
}

export function EmptyWell({ icon, title, description, className = '' }: { icon?: ReactNode; title: string; description?: string; className?: string }) {
    return (
        <div role="status" className={`surface-inset flex flex-col items-center justify-center gap-1.5 px-4 py-6 text-center ${className}`}>
            {icon && (
                <span aria-hidden="true" className="mb-1.5 text-accent opacity-70 [&_svg]:size-11">
                    {icon}
                </span>
            )}
            <p className="text-sm font-semibold text-ink">{title}</p>
            {description && <p className="max-w-sm text-[13px] text-ink-muted">{description}</p>}
        </div>
    );
}

/* Modest line pictograms for empty wells (currentColor, 1.5 stroke). */
const p = { width: 44, height: 44, viewBox: '0 0 48 48', fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };

export function PictoBars() {
    return (
        <svg {...p}>
            <path d="M8 40h32" />
            <rect x="12" y="26" width="6" height="14" rx="1.5" fill="currentColor" fillOpacity="0.12" />
            <rect x="21" y="18" width="6" height="22" rx="1.5" fill="currentColor" fillOpacity="0.18" />
            <rect x="30" y="10" width="6" height="30" rx="1.5" fill="currentColor" fillOpacity="0.24" />
        </svg>
    );
}

export function PictoBox() {
    return (
        <svg {...p}>
            <path d="M24 6 40 14v18L24 40 8 32V14L24 6Z" fill="currentColor" fillOpacity="0.1" />
            <path d="M8 14l16 8 16-8M24 22v18" />
        </svg>
    );
}

export function PictoCubes() {
    return (
        <svg {...p}>
            <path d="M24 6 32 10v8l-8 4-8-4v-8l8-4Z" fill="currentColor" fillOpacity="0.16" />
            <path d="M14 22 22 26v8l-8 4-8-4v-8l8-4ZM34 22l8 4v8l-8 4-8-4v-8l8-4Z" fill="currentColor" fillOpacity="0.1" />
            <path d="M16 10l8 4 8-4M24 14v8M6 26l8 4 8-4M14 30v8M26 26l8 4 8-4M34 30v8" />
        </svg>
    );
}
