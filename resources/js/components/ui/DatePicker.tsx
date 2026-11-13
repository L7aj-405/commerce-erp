import { useFloatingPanel } from '@/hooks/useFloatingPanel';
import { useI18n } from '@/i18n';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { createPortal } from 'react-dom';

/*
 * `YYYY-MM-DD` calendar helpers. Values are plain calendar days, exactly what a native
 * `<input type="date">` produces: no time zone conversion happens anywhere. Weekday and
 * month arithmetic use Date.UTC so the browser's offset can never shift a day.
 */
type Ymd = { y: number; m: number; d: number };
const pad = (n: number) => String(n).padStart(2, '0');
export const isIsoDate = (value: string | null | undefined): value is string => Boolean(value && /^\d{4}-\d{2}-\d{2}$/.test(value));
const parse = (value: string): Ymd => {
    const [y, m, d] = value.split('-').map(Number);
    return { y, m: m - 1, d };
};
const format = ({ y, m, d }: Ymd) => `${String(y).padStart(4, '0')}-${pad(m + 1)}-${pad(d)}`;
const utc = ({ y, m, d }: Ymd) => new Date(Date.UTC(y, m, d));
const fromUtc = (date: Date): Ymd => ({ y: date.getUTCFullYear(), m: date.getUTCMonth(), d: date.getUTCDate() });
const addDays = (value: string, days: number) => {
    const date = utc(parse(value));
    date.setUTCDate(date.getUTCDate() + days);
    return format(fromUtc(date));
};
const addMonths = (value: string, months: number) => {
    const { y, m, d } = parse(value);
    const index = y * 12 + m + months;
    const ny = Math.floor(index / 12);
    const nm = ((index % 12) + 12) % 12;
    const last = new Date(Date.UTC(ny, nm + 1, 0)).getUTCDate();
    return format({ y: ny, m: nm, d: Math.min(d, last) });
};
/** Today in the viewer's local calendar, matching what a native date input offers. */
export const todayValue = () => {
    const now = new Date();
    return format({ y: now.getFullYear(), m: now.getMonth(), d: now.getDate() });
};
const clamp = (value: string, min?: string, max?: string) => (min && value < min ? min : max && value > max ? max : value);

export type DatePickerProps = {
    /** `YYYY-MM-DD`, or empty for no date. Omit to use the component uncontrolled (`defaultValue`). */
    value?: string | null;
    defaultValue?: string | null;
    /** Receives `YYYY-MM-DD`, or `''` when cleared. */
    onChange?: (value: string) => void;
    min?: string | null;
    max?: string | null;
    id?: string;
    /** Rendered as a hidden input so classic `<form>` submissions keep working. */
    name?: string;
    disabled?: boolean;
    required?: boolean;
    invalid?: boolean;
    /** Show a clear button when a value is set. Defaults to `!required`. */
    clearable?: boolean;
    placeholder?: string;
    /** `field` = full control-input look; `bare` = borderless, for compound filter pills. */
    variant?: 'field' | 'bare';
    className?: string;
    'aria-label'?: string;
    'aria-labelledby'?: string;
    'aria-describedby'?: string;
};

/**
 * Single-day date field with a Liquid Glass calendar popover.
 * Keyboard (grid): arrows move by day/week (mirrored in RTL), PageUp/PageDown by month,
 * Shift+PageUp/PageDown by year, Home/End to the start/end of the week, Enter/Space
 * select, Escape closes and returns focus to the field. Days outside `min`/`max` are
 * disabled; no other restriction is applied.
 */
export default function DatePicker({
    value: controlled,
    defaultValue,
    onChange,
    min,
    max,
    id,
    name,
    disabled = false,
    required = false,
    invalid = false,
    clearable,
    placeholder,
    variant = 'field',
    className = '',
    'aria-label': ariaLabel,
    'aria-labelledby': ariaLabelledBy,
    'aria-describedby': ariaDescribedBy,
}: DatePickerProps) {
    const { t, locale, direction } = useI18n();
    const intlLocale = locale === 'ar' ? 'ar-MA' : 'fr-FR';
    const [internal, setInternal] = useState(defaultValue ?? '');
    const value = (controlled === undefined ? internal : controlled) ?? '';
    const hasValue = isIsoDate(value);
    const minValue = isIsoDate(min) ? min : undefined;
    const maxValue = isIsoDate(max) ? max : undefined;
    const today = todayValue();

    const [open, setOpen] = useState(false);
    const [view, setView] = useState<'days' | 'months'>('days');
    const [focused, setFocused] = useState(() => clamp(hasValue ? value : today, minValue, maxValue));
    const triggerRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const gridRef = useRef<HTMLDivElement>(null);
    const focusGrid = useRef(false);
    const titleId = useId();
    const valueId = useId();
    const { style } = useFloatingPanel(open, triggerRef, panelRef, { align: 'start' });

    const fmt = useMemo(() => {
        const opts = (o: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(intlLocale, { timeZone: 'UTC', ...o });
        return {
            display: opts({ day: 'numeric', month: 'short', year: 'numeric' }),
            long: opts({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
            monthYear: opts({ month: 'long', year: 'numeric' }),
            monthShort: opts({ month: 'short' }),
            weekday: opts({ weekday: 'narrow' }),
            weekdayLong: opts({ weekday: 'long' }),
            day: opts({ day: 'numeric' }),
        };
    }, [intlLocale]);
    const cap = (text: string) => text.charAt(0).toLocaleUpperCase(intlLocale) + text.slice(1);

    const isDisabledDay = (v: string) => Boolean((minValue && v < minValue) || (maxValue && v > maxValue));
    const commit = (next: string) => {
        if (controlled === undefined) setInternal(next);
        if (next !== value) onChange?.(next);
    };

    const close = (restore = true) => {
        setOpen(false);
        if (restore) requestAnimationFrame(() => triggerRef.current?.focus());
    };
    const select = (v: string) => {
        if (isDisabledDay(v)) return;
        close();
        commit(v);
    };
    const openPicker = () => {
        if (disabled) return;
        setFocused(clamp(hasValue ? value : today, minValue, maxValue));
        setView('days');
        focusGrid.current = true;
        setOpen(true);
    };

    useEffect(() => {
        if (!open || !focusGrid.current) return;
        const frame = requestAnimationFrame(() => {
            focusGrid.current = false;
            const selector = view === 'days' ? `[data-day="${focused}"]` : `[data-month="${focused.slice(0, 7)}"]`;
            gridRef.current?.querySelector<HTMLButtonElement>(selector)?.focus();
        });
        return () => cancelAnimationFrame(frame);
    }, [open, focused, view]);

    useEffect(() => {
        if (!open) return;
        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;
            if (panelRef.current?.contains(target) || triggerRef.current?.contains(target)) return;
            close(false);
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close();
                return;
            }
            // Tab cycles inside the calendar. Handled here (not by an enclosing Dialog/Sheet
            // focus trap, which would pull focus back because the panel is portalled).
            const panel = panelRef.current;
            if (event.key !== 'Tab' || !panel || !panel.contains(document.activeElement)) return;
            const items = Array.from(panel.querySelectorAll<HTMLElement>('button:not([disabled]):not([tabindex="-1"])'));
            if (items.length === 0) return;
            event.preventDefault();
            event.stopPropagation();
            const index = items.indexOf(document.activeElement as HTMLElement);
            const next = (index + (event.shiftKey ? -1 : 1) + items.length) % items.length;
            items[next].focus();
        };
        document.addEventListener('pointerdown', onPointer);
        // Capture so an enclosing Dialog does not also react to the same Escape/Tab.
        document.addEventListener('keydown', onKey, true);
        return () => {
            document.removeEventListener('pointerdown', onPointer);
            document.removeEventListener('keydown', onKey, true);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const moveFocus = (next: string) => {
        focusGrid.current = true;
        setFocused(clamp(next, minValue, maxValue));
    };
    const onDaysKey = (event: ReactKeyboardEvent) => {
        const horizontal = direction === 'rtl' ? -1 : 1;
        const weekday = (utc(parse(focused)).getUTCDay() + 6) % 7; // Monday = 0
        const keys: Record<string, () => void> = {
            ArrowRight: () => moveFocus(addDays(focused, horizontal)),
            ArrowLeft: () => moveFocus(addDays(focused, -horizontal)),
            ArrowDown: () => moveFocus(addDays(focused, 7)),
            ArrowUp: () => moveFocus(addDays(focused, -7)),
            PageDown: () => moveFocus(addMonths(focused, event.shiftKey ? 12 : 1)),
            PageUp: () => moveFocus(addMonths(focused, event.shiftKey ? -12 : -1)),
            Home: () => moveFocus(addDays(focused, -weekday)),
            End: () => moveFocus(addDays(focused, 6 - weekday)),
        };
        const handler = keys[event.key];
        if (handler) {
            event.preventDefault();
            handler();
        }
    };
    const onMonthsKey = (event: ReactKeyboardEvent) => {
        const horizontal = direction === 'rtl' ? -1 : 1;
        const keys: Record<string, number> = { ArrowRight: horizontal, ArrowLeft: -horizontal, ArrowDown: 3, ArrowUp: -3, PageDown: 12, PageUp: -12 };
        if (event.key in keys) {
            event.preventDefault();
            focusGrid.current = true;
            setFocused(addMonths(focused, keys[event.key]));
        }
    };
    const { y: viewYear, m: viewMonth } = parse(focused);
    const firstOfMonth = format({ y: viewYear, m: viewMonth, d: 1 });
    const lead = (utc(parse(firstOfMonth)).getUTCDay() + 6) % 7;
    const gridStart = addDays(firstOfMonth, -lead);
    const weeks = Array.from({ length: 6 }, (_, w) => Array.from({ length: 7 }, (_, d) => addDays(gridStart, w * 7 + d)));
    const prevMonthDisabled = Boolean(minValue && addMonths(firstOfMonth, -1).slice(0, 7) < minValue.slice(0, 7));
    const nextMonthDisabled = Boolean(maxValue && addMonths(firstOfMonth, 1).slice(0, 7) > maxValue.slice(0, 7));
    const monthOutOfRange = (ym: string) => Boolean((minValue && ym < minValue.slice(0, 7)) || (maxValue && ym > maxValue.slice(0, 7)));
    const todayDisabled = isDisabledDay(today);
    const canClear = (clearable ?? !required) && hasValue && !disabled;

    const chevron = (dir: 'prev' | 'next') => (
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="rtl:-scale-x-100">
            <path d={dir === 'prev' ? 'm15 6-6 6 6 6' : 'm9 6 6 6-6 6'} />
        </svg>
    );
    const iconButton =
        'grid size-9 shrink-0 place-items-center rounded-full text-ink-muted transition-soft hover:bg-sage hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:pointer-events-none disabled:opacity-35';
    const shell =
        variant === 'field'
            ? `control-input flex items-center gap-2 text-start ${canClear ? 'pe-9' : ''} ${disabled ? '' : 'cursor-pointer'}`
            : `flex h-8 items-center gap-1.5 rounded-lg bg-transparent px-1 text-start text-sm font-medium focus-visible:outline-none ${canClear ? 'pe-7' : ''}`;

    return (
        <div className={`relative min-w-0 ${variant === 'field' && !/(^|\s)w-/.test(className) ? 'w-full' : ''} ${className}`}>
            {name && <input type="hidden" name={name} value={hasValue ? value : ''} />}
            <button
                ref={triggerRef}
                id={id}
                type="button"
                disabled={disabled}
                onClick={() => (open ? close() : openPicker())}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' && !open) {
                        event.preventDefault();
                        openPicker();
                    }
                }}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-invalid={invalid || undefined}
                aria-required={required || undefined}
                aria-label={ariaLabel ? `${ariaLabel} : ${hasValue ? cap(fmt.long.format(utc(parse(value)))) : t('datePicker.empty')}` : undefined}
                aria-labelledby={ariaLabel || !ariaLabelledBy ? undefined : `${ariaLabelledBy} ${valueId}`}
                aria-describedby={ariaDescribedBy}
                className={`${shell} w-full min-w-0`}
            >
                <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" className="shrink-0 text-ink-muted">
                    <rect x="3" y="5" width="18" height="16" rx="2.5" />
                    <path d="M8 3v4M16 3v4M3 10h18" />
                </svg>
                <span id={valueId} className={`min-w-0 flex-1 truncate tabular-nums ${hasValue ? 'text-ink' : 'text-ink-faint'}`}>
                    {hasValue ? fmt.display.format(utc(parse(value))) : (placeholder ?? t('datePicker.placeholder'))}
                </span>
            </button>
            {canClear && (
                <button
                    type="button"
                    onClick={() => {
                        commit('');
                        triggerRef.current?.focus();
                    }}
                    aria-label={t('datePicker.clear')}
                    className={`absolute top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-full text-ink-muted transition-soft hover:bg-sage hover:text-ink focus-visible:outline-2 focus-visible:outline-primary ${variant === 'field' ? 'end-1.5' : 'end-0'}`}
                >
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            )}

            {open &&
                createPortal(
                    <div
                        ref={panelRef}
                        role="dialog"
                        aria-modal="false"
                        aria-labelledby={titleId}
                        dir={direction}
                        style={style}
                        className="animate-overlay-in glass-popover z-[90] w-[19.5rem] max-w-[calc(100vw-1rem)] overflow-y-auto overscroll-contain rounded-2xl p-3"
                    >
                        <div className="mb-2 flex items-center justify-between gap-1">
                            <button
                                type="button"
                                className={iconButton}
                                onClick={() => setFocused((f) => clamp(addMonths(f, view === 'days' ? -1 : -12), minValue, maxValue))}
                                disabled={view === 'days' ? prevMonthDisabled : Boolean(minValue && String(viewYear - 1) < minValue.slice(0, 4))}
                                aria-label={view === 'days' ? t('monthPicker.previousMonth') : t('monthPicker.previousYear')}
                            >
                                {chevron('prev')}
                            </button>
                            <button
                                type="button"
                                id={titleId}
                                onClick={() => {
                                    focusGrid.current = true;
                                    setView((v) => (v === 'days' ? 'months' : 'days'));
                                }}
                                aria-live="polite"
                                aria-label={view === 'days' ? `${cap(fmt.monthYear.format(utc(parse(firstOfMonth))))} — ${t('datePicker.chooseMonth')}` : `${viewYear} — ${t('datePicker.backToDays')}`}
                                className="min-h-9 rounded-lg px-3 text-base font-bold tracking-[-0.01em] text-ink tabular-nums transition-soft hover:bg-sage focus-visible:outline-2 focus-visible:outline-primary"
                            >
                                {view === 'days' ? cap(fmt.monthYear.format(utc(parse(firstOfMonth)))) : viewYear}
                            </button>
                            <button
                                type="button"
                                className={iconButton}
                                onClick={() => setFocused((f) => clamp(addMonths(f, view === 'days' ? 1 : 12), minValue, maxValue))}
                                disabled={view === 'days' ? nextMonthDisabled : Boolean(maxValue && String(viewYear + 1) > maxValue.slice(0, 4))}
                                aria-label={view === 'days' ? t('monthPicker.nextMonth') : t('monthPicker.nextYear')}
                            >
                                {chevron('next')}
                            </button>
                        </div>

                        {view === 'days' ? (
                            <div ref={gridRef} role="grid" aria-labelledby={titleId} onKeyDown={onDaysKey}>
                                <div role="row" className="mb-1 grid grid-cols-7">
                                    {weeks[0].map((day) => (
                                        <span key={day} role="columnheader" aria-label={cap(fmt.weekdayLong.format(utc(parse(day))))} className="grid h-7 place-items-center text-xs font-semibold text-ink-muted">
                                            {fmt.weekday.format(utc(parse(day)))}
                                        </span>
                                    ))}
                                </div>
                                {weeks.map((week) => (
                                    <div role="row" key={week[0]} className="grid grid-cols-7 gap-0.5">
                                        {week.map((day) => {
                                            const inMonth = parse(day).m === viewMonth;
                                            const selected = hasValue && day === value;
                                            const isToday = day === today;
                                            const isDisabled = isDisabledDay(day);
                                            return (
                                                <button
                                                    key={day}
                                                    type="button"
                                                    role="gridcell"
                                                    data-day={day}
                                                    aria-selected={selected}
                                                    aria-current={isToday ? 'date' : undefined}
                                                    aria-label={cap(fmt.long.format(utc(parse(day))))}
                                                    disabled={isDisabled}
                                                    tabIndex={day === focused ? 0 : -1}
                                                    onClick={() => select(day)}
                                                    onFocus={() => setFocused(day)}
                                                    className={`relative grid h-10 place-items-center rounded-xl text-sm tabular-nums transition-soft focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-30 ${
                                                        selected
                                                            ? 'bg-primary font-semibold text-primary-fg shadow-[0_4px_10px_rgb(0_114_103/0.22)]'
                                                            : `${inMonth ? 'text-ink' : 'text-ink-faint'} hover:bg-sage`
                                                    } ${isToday && !selected ? 'font-semibold ring-1 ring-primary/45 ring-inset' : ''}`}
                                                >
                                                    {fmt.day.format(utc(parse(day)))}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div ref={gridRef} role="grid" aria-labelledby={titleId} onKeyDown={onMonthsKey} className="grid grid-cols-3 gap-1.5">
                                {[0, 1, 2, 3].map((row) => (
                                    <div role="row" key={row} className="contents">
                                        {[0, 1, 2].map((col) => {
                                            const m = row * 3 + col;
                                            const ym = `${viewYear}-${pad(m + 1)}`;
                                            const out = monthOutOfRange(ym);
                                            return (
                                                <button
                                                    key={ym}
                                                    type="button"
                                                    role="gridcell"
                                                    data-month={ym}
                                                    aria-selected={hasValue && value.startsWith(ym)}
                                                    aria-label={cap(fmt.monthYear.format(new Date(Date.UTC(viewYear, m, 1))))}
                                                    disabled={out}
                                                    tabIndex={m === viewMonth ? 0 : -1}
                                                    onClick={() => {
                                                        focusGrid.current = true;
                                                        setFocused(clamp(format({ y: viewYear, m, d: Math.min(parse(focused).d, new Date(Date.UTC(viewYear, m + 1, 0)).getUTCDate()) }), minValue, maxValue));
                                                        setView('days');
                                                    }}
                                                    className={`min-h-11 rounded-xl px-2 text-sm font-medium capitalize transition-soft focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-35 ${
                                                        hasValue && value.startsWith(ym) ? 'bg-primary font-semibold text-primary-fg' : 'text-ink hover:bg-sage'
                                                    }`}
                                                >
                                                    {fmt.monthShort.format(new Date(Date.UTC(viewYear, m, 1)))}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ))}
                            </div>
                        )}

                        <div className="mt-3 flex items-center justify-between gap-2 border-t border-line pt-2.5">
                            {canClear ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        close();
                                        commit('');
                                    }}
                                    className="min-h-9 rounded-lg px-2.5 text-[13px] font-semibold text-ink-muted transition-soft hover:bg-sage hover:text-ink focus-visible:outline-2 focus-visible:outline-primary"
                                >
                                    {t('datePicker.clear')}
                                </button>
                            ) : (
                                <p className="text-xs text-ink-muted">{t('monthPicker.keyboardHint')}</p>
                            )}
                            <button
                                type="button"
                                onClick={() => select(today)}
                                disabled={todayDisabled}
                                className="min-h-9 rounded-lg px-2.5 text-[13px] font-semibold text-primary transition-soft hover:bg-accent-soft focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-default disabled:text-ink-muted disabled:hover:bg-transparent"
                            >
                                {t('datePicker.today')}
                            </button>
                        </div>
                    </div>,
                    document.body,
                )}
        </div>
    );
}
