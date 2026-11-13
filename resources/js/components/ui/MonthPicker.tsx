import { useFloatingPanel } from '@/hooks/useFloatingPanel';
import { useI18n } from '@/i18n';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { createPortal } from 'react-dom';

/** `YYYY-MM` helpers (local calendar; no time zone involved). */
const parse = (value: string) => {
    const [y, m] = value.split('-').map(Number);
    return { year: y, month: m - 1 };
};
const format = (year: number, month: number) => `${year}-${String(month + 1).padStart(2, '0')}`;
const shift = (value: string, months: number) => {
    const { year, month } = parse(value);
    const index = year * 12 + month + months;
    return format(Math.floor(index / 12), ((index % 12) + 12) % 12);
};
export const currentMonthValue = () => {
    const now = new Date();
    return format(now.getFullYear(), now.getMonth());
};

/**
 * Month selector (value `YYYY-MM`) for period filters: a glass pill with previous /
 * next month steppers and a floating month grid with year navigation.
 * Keyboard: arrows move within the grid (mirrored in RTL), PageUp/PageDown change
 * year, Home/End jump to Jan/Dec, Enter/Space select, Escape closes; focus returns
 * to the trigger. Months after `max` are disabled.
 */
export default function MonthPicker({ value, onChange, max, label }: { value: string; onChange: (value: string) => void; max?: string; label: string }) {
    const { t, locale, direction } = useI18n();
    const intlLocale = locale === 'ar' ? 'ar-MA' : 'fr-FR';
    const [open, setOpen] = useState(false);
    const [viewYear, setViewYear] = useState(() => parse(value).year);
    const [focused, setFocused] = useState(value);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const gridRef = useRef<HTMLDivElement>(null);
    // Move DOM focus into the grid only on open and keyboard navigation (not when the
    // year buttons are clicked, which must keep their own focus).
    const focusGrid = useRef(false);
    const titleId = useId();
    const { style } = useFloatingPanel(open, triggerRef, panelRef, { align: 'end' });

    const monthNames = useMemo(
        () => Array.from({ length: 12 }, (_, m) => new Intl.DateTimeFormat(intlLocale, { month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, m, 1)))),
        [intlLocale],
    );
    const longLabel = (v: string) => {
        const { year, month } = parse(v);
        const text = new Intl.DateTimeFormat(intlLocale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month, 1)));
        return text.charAt(0).toLocaleUpperCase(intlLocale) + text.slice(1);
    };
    const disabled = (v: string) => Boolean(max && v > max);
    const current = currentMonthValue();

    const close = (restore = true) => {
        setOpen(false);
        if (restore) requestAnimationFrame(() => triggerRef.current?.focus());
    };
    const select = (v: string) => {
        if (disabled(v)) return;
        close();
        if (v !== value) onChange(v);
    };
    const openPicker = () => {
        setViewYear(parse(value).year);
        setFocused(value);
        focusGrid.current = true;
        setOpen(true);
    };

    // Focus the active month once the panel is placed; follow keyboard focus moves.
    useEffect(() => {
        if (!open || !focusGrid.current) return;
        const frame = requestAnimationFrame(() => {
            focusGrid.current = false;
            gridRef.current?.querySelector<HTMLButtonElement>(`[data-month="${focused}"]`)?.focus();
        });
        return () => cancelAnimationFrame(frame);
    }, [open, focused, viewYear]);

    // Outside press closes without stealing focus from whatever was pressed.
    useEffect(() => {
        if (!open) return;
        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;
            if (panelRef.current?.contains(target) || triggerRef.current?.contains(target)) return;
            close(false);
        };
        // Escape closes from anywhere (focus may still be on the trigger).
        const onKey = (event: KeyboardEvent) => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            close();
        };
        document.addEventListener('pointerdown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const moveFocus = (months: number) => {
        let next = shift(focused, months);
        if (disabled(next)) next = max ?? next;
        focusGrid.current = true;
        setFocused(next);
        setViewYear(parse(next).year);
    };
    const onGridKey = (event: ReactKeyboardEvent) => {
        const horizontal = direction === 'rtl' ? -1 : 1;
        const { year } = parse(focused);
        const keys: Record<string, () => void> = {
            ArrowRight: () => moveFocus(horizontal),
            ArrowLeft: () => moveFocus(-horizontal),
            ArrowDown: () => moveFocus(3),
            ArrowUp: () => moveFocus(-3),
            PageDown: () => moveFocus(12),
            PageUp: () => moveFocus(-12),
            Home: () => {
                focusGrid.current = true;
                setFocused(format(year, 0));
            },
            End: () => {
                focusGrid.current = true;
                setFocused(disabled(format(year, 11)) ? (max ?? format(year, 11)) : format(year, 11));
            },
        };
        const handler = keys[event.key];
        if (handler) {
            event.preventDefault();
            handler();
        }
    };
    const onPanelKey = (event: ReactKeyboardEvent) => {
        if (event.key === 'Tab') {
            // Keep focus inside the dialog while it is open.
            const items = Array.from(panelRef.current?.querySelectorAll<HTMLElement>('button:not([disabled]):not([tabindex="-1"])') ?? []);
            if (items.length === 0) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    };

    const step = (months: number) => {
        const next = shift(value, months);
        if (!disabled(next)) onChange(next);
    };
    const prevYearDisabled = false;
    // Exactly one tabbable month in the visible year (roving tabindex).
    const tabbable = parse(focused).year === viewYear ? focused : format(viewYear, 0);
    const nextYearDisabled = Boolean(max && format(viewYear + 1, 0) > max);
    const chevron = (dir: 'prev' | 'next') => (
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="rtl:-scale-x-100">
            <path d={dir === 'prev' ? 'm15 6-6 6 6 6' : 'm9 6 6 6-6 6'} />
        </svg>
    );
    const iconButton = 'grid size-9 shrink-0 place-items-center rounded-full text-ink-muted transition-soft hover:bg-sage hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:pointer-events-none disabled:opacity-35';

    return (
        <div className="glass-pill flex min-h-10 items-center gap-0.5 rounded-full p-0.5" role="group" aria-label={label}>
            <button type="button" className={iconButton} onClick={() => step(-1)} aria-label={t('monthPicker.previousMonth')}>
                {chevron('prev')}
            </button>
            <button
                ref={triggerRef}
                type="button"
                onClick={() => (open ? close() : openPicker())}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={`${label} : ${longLabel(value)}`}
                className={`flex min-h-9 items-center gap-2 rounded-full px-3 text-sm font-semibold whitespace-nowrap text-ink transition-soft hover:bg-sage focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary ${open ? 'bg-sage' : ''}`}
            >
                <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" className="text-ink-muted">
                    <rect x="3" y="5" width="18" height="16" rx="2.5" />
                    <path d="M8 3v4M16 3v4M3 10h18" />
                </svg>
                <span className="min-w-[7.5rem] text-start tabular-nums">{longLabel(value)}</span>
            </button>
            <button type="button" className={iconButton} onClick={() => step(1)} disabled={disabled(shift(value, 1))} aria-label={t('monthPicker.nextMonth')}>
                {chevron('next')}
            </button>

            {open &&
                createPortal(
                    <div
                        ref={panelRef}
                        role="dialog"
                        aria-modal="false"
                        aria-labelledby={titleId}
                        dir={direction}
                        style={style}
                        onKeyDown={onPanelKey}
                        className="animate-overlay-in glass-popover z-[80] w-[18.5rem] max-w-[calc(100vw-1rem)] overflow-y-auto overscroll-contain rounded-2xl p-3"
                    >
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <button type="button" className={iconButton} onClick={() => setViewYear((y) => y - 1)} disabled={prevYearDisabled} aria-label={t('monthPicker.previousYear')}>
                                {chevron('prev')}
                            </button>
                            <p id={titleId} className="text-base font-bold tracking-[-0.01em] text-ink tabular-nums" aria-live="polite">
                                {viewYear}
                            </p>
                            <button type="button" className={iconButton} onClick={() => setViewYear((y) => y + 1)} disabled={nextYearDisabled} aria-label={t('monthPicker.nextYear')}>
                                {chevron('next')}
                            </button>
                        </div>

                        <div ref={gridRef} role="grid" aria-labelledby={titleId} onKeyDown={onGridKey} className="grid grid-cols-3 gap-1.5">
                            {[0, 1, 2, 3].map((row) => (
                                <div role="row" key={row} className="contents">
                                    {[0, 1, 2].map((col) => {
                                        const m = row * 3 + col;
                                        const v = format(viewYear, m);
                                        const selected = v === value;
                                        const isCurrent = v === current;
                                        const isDisabled = disabled(v);
                                        return (
                                            <button
                                                key={v}
                                                type="button"
                                                role="gridcell"
                                                data-month={v}
                                                aria-selected={selected}
                                                aria-current={isCurrent ? 'date' : undefined}
                                                aria-label={longLabel(v)}
                                                disabled={isDisabled}
                                                tabIndex={v === tabbable ? 0 : -1}
                                                onClick={() => select(v)}
                                                onFocus={() => setFocused(v)}
                                                className={`relative min-h-11 rounded-xl px-2 text-sm font-medium capitalize transition-soft focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-35 ${
                                                    selected ? 'bg-primary font-semibold text-primary-fg shadow-[0_4px_10px_rgb(0_114_103/0.22)]' : 'text-ink hover:bg-sage'
                                                } ${isCurrent && !selected ? 'ring-1 ring-primary/45 ring-inset' : ''}`}
                                            >
                                                {monthNames[m]}
                                            </button>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>

                        <div className="mt-3 flex items-center justify-between gap-2 border-t border-line pt-2.5">
                            <p className="text-xs text-ink-muted">{t('monthPicker.keyboardHint')}</p>
                            <button
                                type="button"
                                onClick={() => select(current)}
                                disabled={value === current}
                                className="min-h-9 rounded-lg px-2.5 text-[13px] font-semibold text-primary transition-soft hover:bg-accent-soft focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-default disabled:text-ink-muted disabled:hover:bg-transparent"
                            >
                                {t('monthPicker.thisMonth')}
                            </button>
                        </div>
                    </div>,
                    document.body,
                )}
        </div>
    );
}
