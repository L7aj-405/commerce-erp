import { CircleAlertIcon } from '@/components/ui/icons';
import type { ReactNode } from 'react';
import { useEffect, useId, useRef, useState } from 'react';

/**
 * Small info icon with an explanation bubble: shown on hover, on keyboard focus and
 * on click/tap (which pins it; Escape or an outside click closes it). Use it to say
 * WHY a control is unavailable instead of a permanent paragraph under it.
 *
 * `label` is the accessible name of the icon button; the bubble text is exposed
 * through aria-describedby while visible. Theme tokens only (no fixed colors).
 */
export default function InfoTip({ label, children, className = '' }: { label: string; children: ReactNode; className?: string }) {
    const id = useId();
    const root = useRef<HTMLSpanElement>(null);
    const [hover, setHover] = useState(false);
    const [focus, setFocus] = useState(false);
    const [pinned, setPinned] = useState(false);
    const visible = hover || focus || pinned;

    useEffect(() => {
        if (!pinned) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setPinned(false);
        };
        const onPointer = (event: PointerEvent) => {
            if (!root.current?.contains(event.target as Node)) setPinned(false);
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onPointer);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onPointer);
        };
    }, [pinned]);

    return (
        <span
            ref={root}
            className={`relative inline-flex ${className}`}
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
        >
            <button
                type="button"
                aria-label={label}
                aria-describedby={visible ? id : undefined}
                aria-expanded={visible}
                onClick={() => setPinned((current) => !current)}
                onFocus={() => setFocus(true)}
                onBlur={() => setFocus(false)}
                className="inline-flex size-8 cursor-help items-center justify-center rounded-full text-ink-muted transition-soft hover:bg-sage hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                <CircleAlertIcon size={16} />
            </button>
            {visible && (
                <span
                    id={id}
                    role="tooltip"
                    className="absolute bottom-full left-1/2 z-50 mb-2 w-max max-w-[min(18rem,calc(100vw-2rem))] -translate-x-1/2 rounded-lg bg-ink px-3 py-2 text-start text-[13px] font-medium leading-snug text-surface shadow-pop"
                >
                    {children}
                </span>
            )}
        </span>
    );
}
