import { useCallback, useLayoutEffect, useState } from 'react';
import type { CSSProperties, RefObject } from 'react';

/**
 * Fixed-position placement for portalled popovers (menus, filter pickers).
 * - never clipped by table/card overflow (the panel lives in document.body)
 * - clamped inside the viewport with an 8px gutter
 * - flips above the trigger when there is more room there
 * - `align: 'end'` mirrors automatically in RTL
 * Recomputes on scroll (capture) and resize while open.
 */
export function useFloatingPanel(
    open: boolean,
    triggerRef: RefObject<HTMLElement | null>,
    panelRef: RefObject<HTMLElement | null>,
    { align = 'start', matchWidth = false }: { align?: 'start' | 'end'; matchWidth?: boolean } = {},
) {
    const [position, setPosition] = useState<{ top: number; left: number; maxHeight: number; minWidth?: number } | null>(null);

    const place = useCallback(() => {
        const panel = panelRef.current;
        const trigger = triggerRef.current;
        if (!panel || !trigger) return;
        const gutter = 8;
        const gap = 6;
        const rect = trigger.getBoundingClientRect();
        const panelWidth = Math.max(panel.offsetWidth, matchWidth ? rect.width : 0);
        const rtl = getComputedStyle(trigger).direction === 'rtl';
        const alignRightEdges = (align === 'end') !== rtl;
        let left = alignRightEdges ? rect.right - panelWidth : rect.left;
        left = Math.min(Math.max(gutter, left), window.innerWidth - gutter - panelWidth);
        const below = window.innerHeight - rect.bottom - gap - gutter;
        const above = rect.top - gap - gutter;
        const needed = panel.scrollHeight;
        const up = needed > below && above > below;
        const maxHeight = Math.max(160, Math.min(window.innerHeight * 0.7, up ? above : below));
        const top = up ? Math.max(gutter, rect.top - gap - Math.min(needed, maxHeight)) : rect.bottom + gap;
        setPosition({ top, left, maxHeight, minWidth: matchWidth ? rect.width : undefined });
    }, [align, matchWidth, panelRef, triggerRef]);

    useLayoutEffect(() => {
        if (!open) {
            setPosition(null);
            return;
        }
        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [open, place]);

    const style: CSSProperties = {
        position: 'fixed',
        top: position?.top ?? 0,
        left: position?.left ?? 0,
        maxHeight: position?.maxHeight,
        minWidth: position?.minWidth,
        // Opacity (not visibility) until placed, so autofocus inside the panel still works.
        opacity: position ? undefined : 0,
        pointerEvents: position ? undefined : 'none',
    };
    const direction = triggerRef.current ? (getComputedStyle(triggerRef.current).direction as 'ltr' | 'rtl') : undefined;

    return { style, direction, reposition: place };
}
