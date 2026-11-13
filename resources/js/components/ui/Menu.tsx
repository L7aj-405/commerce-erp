import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent, ReactNode, RefObject } from 'react';
import { createPortal } from 'react-dom';
import { useFloatingPanel } from '@/hooks/useFloatingPanel';

/*
 * Shared disclosure menu (header tabs, More overflow, create/profile menus,
 * row actions). Closes on outside pointer, Escape (focus returns to the
 * trigger) and link activation. The panel is portalled with fixed, collision-aware
 * placement so table/card overflow never clips it.
 * Items are plain links/buttons in tab order — no roving-focus menu semantics.
 */
export function useDismiss(open: boolean, close: () => void, refs: RefObject<HTMLElement | null>[]) {
    useEffect(() => {
        if (!open) return;
        const onPointer = (event: PointerEvent) => {
            if (refs.some((ref) => ref.current?.contains(event.target as Node))) return;
            close();
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close();
            }
        };
        document.addEventListener('pointerdown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, close]);
}

export function Dropdown({
    label,
    icon,
    active = false,
    buttonClassName,
    hideLabel = false,
    align = 'start',
    width = 'w-64',
    children,
}: {
    label: string;
    icon?: ReactNode;
    active?: boolean;
    buttonClassName: string;
    hideLabel?: boolean;
    align?: 'start' | 'end';
    width?: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const buttonRef = useRef<HTMLButtonElement | null>(null);
    const panelRef = useRef<HTMLDivElement | null>(null);
    const close = useCallback(() => {
        setOpen(false);
        buttonRef.current?.focus();
    }, []);
    const dismiss = useCallback(() => setOpen(false), []);
    useDismiss(open, dismiss, [buttonRef, panelRef]);
    // Portal + fixed placement: never clipped by overflow, flips near the viewport edge, RTL-aware.
    const floating = useFloatingPanel(open, buttonRef, panelRef, { align });
    // Which item receives focus when the panel opens (ArrowUp on the trigger → last).
    const focusTarget = useRef<'first' | 'last'>('first');
    const items = () => Array.from(panelRef.current?.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), select:not([disabled])') ?? []);
    useEffect(() => {
        if (!open) return;
        const list = items();
        (focusTarget.current === 'last' ? list[list.length - 1] : list[0])?.focus();
        focusTarget.current = 'first';
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function onPanelKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
            return;
        }
        if (event.key === 'Tab') {
            // The panel lives in a portal: return to the trigger so the browser's default
            // Tab continues to the control after it (WAI-ARIA menu button behaviour).
            setOpen(false);
            buttonRef.current?.focus();
            return;
        }
        // Native selects/inputs keep their own arrow behaviour.
        const target = event.target as HTMLElement;
        if (target.tagName === 'SELECT' || target.tagName === 'INPUT') return;
        const list = items();
        if (list.length === 0) return;
        const index = list.indexOf(document.activeElement as HTMLElement);
        let next: number | null = null;
        if (event.key === 'ArrowDown') next = (index + 1) % list.length;
        else if (event.key === 'ArrowUp') next = (index - 1 + list.length) % list.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = list.length - 1;
        if (next === null) return;
        event.preventDefault();
        list[next]?.focus();
    }

    return (
        <div className="relative">
            <button
                ref={buttonRef}
                type="button"
                aria-haspopup="true"
                aria-expanded={open}
                aria-label={hideLabel ? label : undefined}
                title={hideLabel ? label : undefined}
                data-active={active || undefined}
                onClick={() => setOpen((value) => !value)}
                onKeyDown={(event) => {
                    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
                    event.preventDefault();
                    focusTarget.current = event.key === 'ArrowUp' ? 'last' : 'first';
                    if (open) {
                        const list = items();
                        (event.key === 'ArrowUp' ? list[list.length - 1] : list[0])?.focus();
                    } else setOpen(true);
                }}
                className={buttonClassName}
            >
                {icon}
                {!hideLabel && <span>{label}</span>}
                {!hideLabel && <IconChevronDown />}
            </button>
            {open &&
                createPortal(
                    <div
                        ref={panelRef}
                        dir={floating.direction}
                        style={floating.style}
                        onKeyDown={onPanelKeyDown}
                        onBlur={(event) => {
                            // Focus leaving the panel closes it (focus continues naturally).
                            const next = event.relatedTarget as Node | null;
                            if (next && !panelRef.current?.contains(next) && !buttonRef.current?.contains(next)) setOpen(false);
                        }}
                        onClick={(event) => {
                            if ((event.target as HTMLElement).closest('a, button')) setOpen(false);
                        }}
                        className={`animate-overlay-in glass-popover z-[80] max-w-[calc(100vw-1rem)] overflow-y-auto overscroll-contain rounded-xl p-1.5 ${width}`}
                    >
                        {children}
                    </div>,
                    document.body,
                )}
        </div>
    );
}

export function MenuLink({ href, active = false, icon, children }: { href: string; active?: boolean; icon?: ReactNode; children: ReactNode }) {
    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={`flex min-h-10 items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-soft focus-visible:bg-sage focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 ${
                active ? 'bg-accent-soft font-semibold text-accent' : 'text-ink hover:bg-sage'
            }`}
        >
            {icon && (
                <span aria-hidden="true" className={active ? 'text-accent' : 'text-ink-faint'}>
                    {icon}
                </span>
            )}
            <span className="min-w-0 truncate">{children}</span>
        </Link>
    );
}


function IconChevronDown() {
    return (
        <svg aria-hidden="true" width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round">
            <path d="m6 9 6 6 6-6" />
        </svg>
    );
}

/** Button row inside a Dropdown (e.g. row actions that post instead of navigate). */
export function MenuButton({ onClick, icon, tone = 'default', disabled, children }: { onClick: () => void; icon?: ReactNode; tone?: 'default' | 'danger'; disabled?: boolean; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={`flex min-h-10 w-full cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-start text-sm transition-soft focus-visible:bg-sage focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-50 ${
                tone === 'danger' ? 'text-danger hover:bg-danger-soft' : 'text-ink hover:bg-sage'
            }`}
        >
            {icon && (
                <span aria-hidden="true" className={tone === 'danger' ? 'text-danger' : 'text-ink-faint'}>
                    {icon}
                </span>
            )}
            <span className="min-w-0 truncate">{children}</span>
        </button>
    );
}
