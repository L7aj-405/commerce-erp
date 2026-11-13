import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';

// Stack of open traps: only the top-most overlay reacts to Escape/Tab (nested Sheet → Dialog).
const stack: symbol[] = [];

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Modal focus management shared by Dialog and Sheet:
 * moves focus inside on open ([data-autofocus] → first content control → close
 * button marked [data-dismiss]), keeps Tab/Shift+Tab
 * inside, locks page scroll, closes on Escape (when allowed) and restores focus
 * to the opener on close.
 */
export function useFocusTrap(
    open: boolean,
    containerRef: RefObject<HTMLElement | null>,
    { onEscape, lockScroll = true }: { onEscape?: () => void; lockScroll?: boolean } = {},
) {
    const escapeRef = useRef(onEscape);
    escapeRef.current = onEscape;
    useEffect(() => {
        if (!open) return;
        const token = Symbol('focus-trap');
        stack.push(token);
        const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const previousOverflow = document.documentElement.style.overflow;
        if (lockScroll) document.documentElement.style.overflow = 'hidden';

        const focusables = () =>
            Array.from(containerRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter((element) => !element.hidden && element.offsetParent !== null);

        const timer = window.setTimeout(() => {
            const container = containerRef.current;
            if (!container || container.contains(document.activeElement)) return;
            // Purpose-first: explicit [data-autofocus], then the first real control in the
            // content, and only then the close button ([data-dismiss]) or the container.
            const preferred = container.querySelector<HTMLElement>('[data-autofocus]');
            const items = focusables();
            const content = items.find((element) => !element.hasAttribute('data-dismiss'));
            (preferred ?? content ?? items[0] ?? container).focus();
        }, 0);

        const onKeyDown = (event: KeyboardEvent) => {
            if (stack[stack.length - 1] !== token) return;
            if (event.key === 'Escape' && escapeRef.current) {
                event.preventDefault();
                escapeRef.current();
                return;
            }
            if (event.key !== 'Tab') return;
            const items = focusables();
            if (items.length === 0) {
                event.preventDefault();
                containerRef.current?.focus();
                return;
            }
            const first = items[0]!;
            const last = items[items.length - 1]!;
            const active = document.activeElement;
            if (!containerRef.current?.contains(active)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', onKeyDown);

        return () => {
            window.clearTimeout(timer);
            stack.splice(stack.indexOf(token), 1);
            document.removeEventListener('keydown', onKeyDown);
            if (lockScroll) document.documentElement.style.overflow = previousOverflow;
            if (opener?.isConnected) opener.focus();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
}
