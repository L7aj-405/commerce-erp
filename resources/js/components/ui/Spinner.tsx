const sizes = { xs: 12, sm: 14, md: 16, lg: 20 } as const;

/**
 * The one spinner for the whole app. Inherits `currentColor`, so it takes the
 * colour of whatever it sits inside (a button, a muted label, an error line).
 */
export function Spinner({ size = 'sm', className = '' }: { size?: keyof typeof sizes; className?: string }) {
    const px = sizes[size];
    return (
        <svg
            width={px}
            height={px}
            viewBox="0 0 24 24"
            fill="none"
            role="presentation"
            aria-hidden="true"
            className={`animate-spin ${className}`}
        >
            <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </svg>
    );
}

/** Spinner + short text, e.g. "Recalcul…". Used inline near a value that is refreshing. */
export function InlineLoader({ label, className = '', size = 'xs' }: { label?: string; className?: string; size?: keyof typeof sizes }) {
    return (
        <span className={`inline-flex items-center gap-1.5 text-ink-muted ${className}`} role="status" aria-live="polite">
            <Spinner size={size} />
            {label && <span>{label}</span>}
        </span>
    );
}
