/*
 * Canonical public product name. Nothing else should hardcode the name.
 */
export const PRODUCT_NAME = '10xScale ERP';

/** Official 10X app mark (public/android-chrome-192x192.png — 22 KB; the 700 KB master
 *  in public/branding is only for print/export). The PNG carries transparent padding
 *  around its rounded tile, so it is scaled slightly inside a clipped box. */
const MARK_SRC = '/android-chrome-192x192.png';

export function BrandMark({ size = 28, className = '' }: { size?: number; className?: string }) {
    return (
        <span
            className={`inline-grid shrink-0 place-items-center overflow-hidden rounded-[22%] bg-[#0b0b0c] shadow-[inset_0_0_0_1px_rgb(255_255_255/0.06),0_1px_2px_rgb(21_25_26/0.25)] ${className}`}
            style={{ width: size, height: size }}
            aria-hidden
        >
            <img src={MARK_SRC} alt="" width={size} height={size} decoding="async" className="size-full scale-[1.22] object-cover" draggable={false} />
        </span>
    );
}

/** Mark + typographic wordmark ("10xScale" in ink, "ERP" in muted ink). */
export function BrandLockup({ size = 28, className = '', subtitle }: { size?: number; className?: string; subtitle?: string }) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`} dir="ltr">
            <BrandMark size={size} />
            <span className="leading-tight">
                <span className="block text-[17px] font-bold tracking-[-0.02em] text-ink">
                    10xScale <span className="font-normal text-ink-muted">ERP</span>
                </span>
                {subtitle && <span className="block text-xs text-ink-muted">{subtitle}</span>}
            </span>
        </span>
    );
}
