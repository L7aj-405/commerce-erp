import { useI18n } from '@/i18n';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { FieldError, FieldHint, FieldLabel } from './form';
import { Spinner } from './Spinner';

export type SelectOption = { value: string; label: string; description?: string; disabled?: boolean };

const normalize = (value: string) =>
    value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLocaleLowerCase();

/**
 * Accessible searchable select (ARIA 1.2 combobox + listbox).
 * - Local filtering (accent-insensitive) by default; pass `onQueryChange` +
 *   `loading` to drive server-side search with the same UI.
 * - Keyboard: ↓/↑ move, Enter selects, Esc closes and restores, Home/End jump.
 * - `name` renders a hidden input so it works inside plain <form> posts too.
 */
export function SearchableSelect({
    label,
    options,
    value,
    onChange,
    placeholder,
    hint,
    error,
    required,
    disabled,
    name,
    clearable = true,
    loading = false,
    onQueryChange,
    emptyLabel,
    hideLabel = false,
    className = '',
    renderOption,
}: {
    label: string;
    options: SelectOption[];
    value: string | null;
    onChange: (value: string | null, option: SelectOption | null) => void;
    placeholder?: string;
    hint?: string;
    error?: string;
    required?: boolean;
    disabled?: boolean;
    name?: string;
    clearable?: boolean;
    loading?: boolean;
    onQueryChange?: (query: string) => void;
    emptyLabel?: string;
    hideLabel?: boolean;
    className?: string;
    renderOption?: (option: SelectOption) => ReactNode;
}) {
    const { t } = useI18n();
    const baseId = useId();
    const inputId = `${baseId}-input`;
    const listId = `${baseId}-list`;
    const errorId = `${baseId}-error`;
    const hintId = `${baseId}-hint`;
    const wrapperRef = useRef<HTMLDivElement>(null);
    const listRef = useRef<HTMLUListElement>(null);

    const selected = options.find((option) => option.value === value) ?? null;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    // Open upward when the viewport has too little room below (dialogs, bottom-of-page forms).
    const [dropUp, setDropUp] = useState(false);

    const filtered = useMemo(() => {
        if (onQueryChange || !query.trim()) return options;
        const needle = normalize(query.trim());
        return options.filter((option) => normalize(`${option.label} ${option.description ?? ''}`).includes(needle));
    }, [options, query, onQueryChange]);

    useEffect(() => {
        if (!open) return;
        const onPointer = (event: PointerEvent) => {
            if (!wrapperRef.current?.contains(event.target as Node)) close();
        };
        document.addEventListener('pointerdown', onPointer);
        return () => document.removeEventListener('pointerdown', onPointer);
    });

    useEffect(() => {
        listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active, open]);

    const openList = () => {
        if (disabled) return;
        const index = selected ? Math.max(0, filtered.findIndex((option) => option.value === selected.value)) : 0;
        const rect = wrapperRef.current?.getBoundingClientRect();
        if (rect) {
            const below = window.innerHeight - rect.bottom;
            setDropUp(below < 300 && rect.top > below);
        }
        setActive(index);
        setOpen(true);
    };
    const close = () => {
        setOpen(false);
        setQuery('');
    };
    const choose = (option: SelectOption | undefined) => {
        if (!option || option.disabled) return;
        onChange(option.value, option);
        close();
    };
    const move = (delta: number) => {
        if (filtered.length === 0) return;
        let next = active;
        for (let step = 0; step < filtered.length; step++) {
            next = (next + delta + filtered.length) % filtered.length;
            if (!filtered[next]!.disabled) break;
        }
        setActive(next);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (!open) openList();
                else move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                if (!open) openList();
                else move(-1);
                break;
            case 'Home':
                if (open) {
                    event.preventDefault();
                    setActive(0);
                }
                break;
            case 'End':
                if (open) {
                    event.preventDefault();
                    setActive(Math.max(0, filtered.length - 1));
                }
                break;
            case 'Enter':
                if (open) {
                    event.preventDefault();
                    choose(filtered[active]);
                }
                break;
            case 'Escape':
                if (open) {
                    event.preventDefault();
                    event.stopPropagation();
                    close();
                }
                break;
            case 'Tab':
                close();
                break;
        }
    };

    const describedBy = error ? errorId : hint ? hintId : undefined;
    const activeId = open && filtered[active] ? `${listId}-opt-${active}` : undefined;

    return (
        <div ref={wrapperRef} className={`relative ${className}`}>
            {hideLabel ? (
                <label htmlFor={inputId} className="sr-only">
                    {label}
                </label>
            ) : (
                <FieldLabel htmlFor={inputId} required={required}>
                    {label}
                </FieldLabel>
            )}
            {name && <input type="hidden" name={name} value={value ?? ''} />}
            <div className="relative">
                <input
                    id={inputId}
                    type="text"
                    role="combobox"
                    autoComplete="off"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={activeId}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    aria-required={required || undefined}
                    disabled={disabled}
                    value={open ? query : (selected?.label ?? '')}
                    placeholder={open && selected ? selected.label : (placeholder ?? t('ui.selectPlaceholder'))}
                    onClick={() => (open ? undefined : openList())}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActive(0);
                        if (!open) setOpen(true);
                        onQueryChange?.(event.target.value);
                    }}
                    onKeyDown={onKeyDown}
                    className={`control-input h-11 cursor-pointer px-3.5 ${clearable && selected && !disabled ? 'pe-[4.5rem]' : 'pe-10'}`}
                />
                <span className="pointer-events-none absolute inset-y-0 end-3 flex items-center gap-1 text-ink-muted">
                    {loading ? (
                        <Spinner size="sm" />
                    ) : (
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                            <path d={open ? 'm6 15 6-6 6 6' : 'm6 9 6 6 6-6'} />
                        </svg>
                    )}
                </span>
                {clearable && selected && !disabled && (
                    <button
                        type="button"
                        onClick={() => {
                            onChange(null, null);
                            close();
                        }}
                        aria-label={t('ui.clearSelection')}
                        className="absolute inset-y-1.5 end-9 flex w-8 cursor-pointer items-center justify-center rounded-md text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                    >
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                )}
            </div>

            {open && (
                <ul
                    ref={listRef}
                    id={listId}
                    role="listbox"
                    aria-label={label}
                    className={`animate-overlay-in glass-popover absolute inset-x-0 z-50 max-h-72 overflow-y-auto overscroll-contain rounded-xl p-1 ${dropUp ? 'bottom-full mb-1.5' : 'top-full mt-1.5'}`}
                >
                    {filtered.length === 0 ? (
                        <li role="presentation" className="px-3 py-3 text-sm text-ink-muted">
                            {loading ? t('common.searching') : (emptyLabel ?? t('ui.noResults'))}
                        </li>
                    ) : (
                        filtered.map((option, index) => {
                            const isSelected = option.value === value;
                            const isActive = index === active;
                            return (
                                <li
                                    key={option.value}
                                    id={`${listId}-opt-${index}`}
                                    data-index={index}
                                    role="option"
                                    aria-selected={isSelected}
                                    aria-disabled={option.disabled || undefined}
                                    onMouseEnter={() => setActive(index)}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => choose(option)}
                                    className={`flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm ${
                                        option.disabled ? 'cursor-not-allowed text-ink-faint' : isActive ? 'bg-sage text-ink' : isSelected ? 'bg-accent-soft/60 text-ink' : 'text-ink'
                                    }`}
                                >
                                    <span className="min-w-0 flex-1">
                                        {renderOption ? (
                                            renderOption(option)
                                        ) : (
                                            <>
                                                <span className={`block truncate ${isSelected ? 'font-semibold' : ''}`}>{option.label}</span>
                                                {option.description && <span className="block truncate text-[13px] text-ink-muted">{option.description}</span>}
                                            </>
                                        )}
                                    </span>
                                    {isSelected && (
                                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" className="shrink-0 text-accent">
                                            <path d="m5 12.5 4.5 4.5L19 7.5" />
                                        </svg>
                                    )}
                                </li>
                            );
                        })
                    )}
                </ul>
            )}
            {hint && !error && <FieldHint id={hintId}>{hint}</FieldHint>}
            <FieldError id={errorId}>{error}</FieldError>
        </div>
    );
}
