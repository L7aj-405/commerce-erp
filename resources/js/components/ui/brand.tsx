/*
 * Temporary brand lockup. The product has no permanent identity yet — swap the
 * mark and `PRODUCT_NAME` here when one exists. Nothing else should hardcode the name.
 */
export const PRODUCT_NAME = 'Commerce ERP';

export function BrandMark({ size = 28, className = '' }: { size?: number; className?: string }) {
    return (
        <span
            className={`inline-grid shrink-0 place-items-center rounded-[10px] bg-primary text-primary-fg ${className}`}
            style={{ width: size, height: size }}
            aria-hidden
        >
            <svg width={size * 0.58} height={size * 0.58} viewBox="0 0 24 24" fill="none">
                <path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" opacity="0.55" />
                <path d="M12 12 4 7.5M12 12v9M12 12l8-4.5" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" />
            </svg>
        </span>
    );
}

export function BrandLockup({ size = 28, className = '', subtitle }: { size?: number; className?: string; subtitle?: string }) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <BrandMark size={size} />
            <span className="leading-tight">
                <span className="block text-[15px] font-semibold tracking-tight text-ink">{PRODUCT_NAME}</span>
                {subtitle && <span className="block text-xs text-ink-muted">{subtitle}</span>}
            </span>
        </span>
    );
}
