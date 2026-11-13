import { useFloatingPanel } from '@/hooks/useFloatingPanel';
import { useI18n } from '@/i18n';
import DatePicker from './DatePicker';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Spinner } from './Spinner';

/*
 * AdvancedFilterBar — shared filter system for index pages.
 *
 *   <AdvancedFilterBar
 *       search={<FilterSearch value={f.search} onChange={f.setSearch} loading={f.searching} label="…" />}
 *       activeCount={f.activeCount} loading={f.loading} onReset={f.reset}
 *       chips={[{ key: 'status', label: 'Statut', value: 'Confirmée', onRemove: () => f.remove('status') }]}
 *   >
 *       <ChoiceFilter label="Statut" value={f.values.status} options={…} onChange={(v) => f.set('status', v)} />
 *       <DateRangeFilter … />
 *   </AdvancedFilterBar>
 *
 * Desktop (lg+): one aligned row — search grows, compact filter triggers follow,
 * reset at the end, active chips underneath. Narrow screens: search + a
 * "Filtres (n)" disclosure revealing the same controls in a grid (one DOM
 * instance, so state is never duplicated). Pair with `useQueryFilters`.
 */

type Option = { value: string; label: string; description?: string; disabled?: boolean };
export type FilterChip = { key: string; label: string; value: string; onRemove: () => void };

export function AdvancedFilterBar({
    search,
    children,
    chips = [],
    activeCount = 0,
    loading = false,
    onReset,
    actions,
    label,
}: {
    search?: ReactNode;
    children?: ReactNode;
    chips?: FilterChip[];
    activeCount?: number;
    loading?: boolean;
    onReset?: () => void;
    actions?: ReactNode;
    label?: string;
}) {
    const { t } = useI18n();
    const panelId = useId();
    const [expanded, setExpanded] = useState(false);
    const filterCount = chips.length;

    return (
        <section role="search" aria-label={label ?? t('filters.title')} className="surface-card p-3 sm:p-3.5">
            <div className="flex flex-wrap items-center gap-2">
                {search && <div className="min-w-0 flex-1 basis-full sm:basis-64 lg:max-w-md">{search}</div>}

                {children && (
                    <button
                        type="button"
                        aria-expanded={expanded}
                        aria-controls={panelId}
                        onClick={() => setExpanded((value) => !value)}
                        className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-field border border-line-strong bg-surface px-3 text-sm font-medium text-ink transition-soft hover:bg-raised lg:hidden"
                    >
                        <IconSliders />
                        {t('filters.title')}
                        {filterCount > 0 && <span className="rounded-md bg-accent-soft px-1.5 text-[12px] font-semibold text-accent">{filterCount}</span>}
                    </button>
                )}

                {children && (
                    <div
                        id={panelId}
                        className={`${expanded ? 'grid' : 'hidden'} w-full grid-cols-1 gap-2 min-[480px]:grid-cols-2 lg:flex lg:w-auto lg:flex-wrap lg:items-center`}
                    >
                        {children}
                    </div>
                )}

                <div className="ms-auto flex items-center gap-2">
                    {loading && (
                        <span className="inline-flex items-center gap-1.5 text-[13px] text-ink-muted" role="status">
                            <Spinner size="sm" />
                            <span className="hidden sm:inline">{t('filters.loading')}</span>
                        </span>
                    )}
                    {onReset && activeCount > 0 && (
                        <button
                            type="button"
                            onClick={onReset}
                            className="inline-flex h-10 cursor-pointer items-center rounded-field px-3 text-sm font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                        >
                            {t('filters.reset')}
                        </button>
                    )}
                    {actions}
                </div>
            </div>

            {chips.length > 0 && (
                <div className="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-line pt-2.5">
                    <span className="me-1 text-[13px] text-ink-muted">{t('filters.applied').replace('{n}', String(chips.length))}</span>
                    {chips.map((chip) => (
                        <span key={chip.key} className="inline-flex min-h-8 items-center gap-1 rounded-md border border-accent/20 bg-accent-soft ps-2.5 pe-1 text-[13px] text-ink">
                            <span className="text-ink-muted">{chip.label} :</span>
                            <span className="max-w-48 truncate font-medium">{chip.value}</span>
                            <button
                                type="button"
                                onClick={chip.onRemove}
                                aria-label={t('filters.remove').replace('{label}', `${chip.label} ${chip.value}`)}
                                className="grid size-6 cursor-pointer place-items-center rounded text-ink-muted transition-soft hover:bg-surface hover:text-ink"
                            >
                                <IconX />
                            </button>
                        </span>
                    ))}
                </div>
            )}
        </section>
    );
}

/** Search field with clear button and in-flight feedback. */
export function FilterSearch({
    value,
    onChange,
    label,
    placeholder,
    loading = false,
}: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    placeholder?: string;
    loading?: boolean;
}) {
    const { t } = useI18n();
    const inputRef = useRef<HTMLInputElement | null>(null);
    return (
        <label className="relative block">
            <span className="sr-only">{label}</span>
            <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-faint">
                {loading ? <Spinner size="sm" /> : <IconSearch />}
            </span>
            <input
                ref={inputRef}
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && value) {
                        event.stopPropagation();
                        onChange('');
                    }
                }}
                placeholder={placeholder ?? label}
                className="control-input h-10 ps-9 pe-9 [&::-webkit-search-cancel-button]:hidden"
            />
            {value && (
                <button
                    type="button"
                    onClick={() => {
                        onChange('');
                        inputRef.current?.focus();
                    }}
                    aria-label={t('filters.clearSearch')}
                    className="absolute inset-y-1 end-1 grid w-8 cursor-pointer place-items-center rounded-md text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                >
                    <IconX />
                </button>
            )}
        </label>
    );
}

/**
 * Compact filter trigger + floating option list. Single choice by default;
 * `multiple` turns it into a checkbox listbox (only use where the backend or the
 * page accepts several values). Searchable automatically above 8 options.
 */
export function ChoiceFilter<M extends boolean = false>({
    label,
    options,
    value,
    onChange,
    multiple,
    allLabel,
    searchable,
}: {
    label: string;
    options: Option[];
    value: M extends true ? string[] : string | undefined | null;
    onChange: (value: M extends true ? string[] : string | undefined) => void;
    multiple?: M;
    allLabel?: string;
    searchable?: boolean;
}) {
    const { t } = useI18n();
    const listId = useId();
    const triggerRef = useRef<HTMLButtonElement | null>(null);
    const panelRef = useRef<HTMLDivElement | null>(null);
    const searchRef = useRef<HTMLInputElement | null>(null);
    const listRef = useRef<HTMLUListElement | null>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const floating = useFloatingPanel(open, triggerRef, panelRef, { matchWidth: true });

    const selected = useMemo(() => (multiple ? ((value as string[] | undefined) ?? []) : value ? [value as string] : []), [multiple, value]);
    const withSearch = searchable ?? options.length > 8;
    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return needle ? options.filter((option) => `${option.label} ${option.description ?? ''}`.toLowerCase().includes(needle)) : options;
    }, [options, query]);

    const summary =
        selected.length === 0
            ? (allLabel ?? t('filters.any'))
            : selected.length === 1
              ? (options.find((option) => option.value === selected[0])?.label ?? selected[0])
              : t('filters.selected').replace('{n}', String(selected.length));

    const close = (refocus = true) => {
        setOpen(false);
        setQuery('');
        if (refocus) triggerRef.current?.focus();
    };

    useEffect(() => {
        if (!open) return;
        const start = Math.max(0, visible.findIndex((option) => selected.includes(option.value)));
        setActive(start);
        (withSearch ? searchRef.current : listRef.current)?.focus();
        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;
            if (!panelRef.current?.contains(target) && !triggerRef.current?.contains(target)) close(false);
        };
        document.addEventListener('pointerdown', onPointer);
        return () => document.removeEventListener('pointerdown', onPointer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const toggle = (option: Option | undefined) => {
        if (!option || option.disabled) return;
        if (multiple) {
            const next = selected.includes(option.value) ? selected.filter((item) => item !== option.value) : [...selected, option.value];
            (onChange as (value: string[]) => void)(next);
        } else {
            (onChange as (value: string | undefined) => void)(selected[0] === option.value ? undefined : option.value);
            close();
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(visible.length - 1, index + 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(0, index - 1));
        } else if (event.key === 'Home') {
            event.preventDefault();
            setActive(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            setActive(visible.length - 1);
        } else if (event.key === 'Enter' || (event.key === ' ' && event.target === listRef.current)) {
            event.preventDefault();
            toggle(visible[active]);
        } else if (event.key === 'Tab') {
            close(false);
        }
    };

    const hasValue = selected.length > 0;
    return (
        <div className="min-w-0">
            <button
                ref={triggerRef}
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                onClick={() => (open ? close() : setOpen(true))}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' && !open) {
                        event.preventDefault();
                        setOpen(true);
                    }
                }}
                className={`inline-flex h-10 w-full min-w-0 cursor-pointer items-center gap-2 rounded-field border px-3 text-sm transition-soft lg:w-auto lg:max-w-64 ${
                    hasValue
                        ? 'border-accent/35 bg-accent-soft text-ink hover:border-accent/60'
                        : 'border-line-strong bg-surface text-ink hover:border-[color-mix(in_srgb,var(--color-primary)_36%,var(--color-line-strong))]'
                } ${open ? 'ring-3 ring-primary/15' : ''}`}
            >
                <span className="shrink-0 text-ink-muted">{label}</span>
                <span className="min-w-0 truncate font-medium">{summary}</span>
                <svg aria-hidden="true" className={`ms-auto shrink-0 text-ink-faint transition-transform ${open ? 'rotate-180' : ''}`} width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>
            {open &&
                createPortal(
                    <div
                        ref={panelRef}
                        dir={floating.direction}
                        style={floating.style}
                        onKeyDown={onKeyDown}
                        className="animate-overlay-in glass-popover z-[80] flex w-72 max-w-[calc(100vw-1rem)] flex-col overflow-hidden rounded-xl"
                    >
                        {withSearch && (
                            <div className="border-b border-line p-1.5">
                                <input
                                    ref={searchRef}
                                    type="text"
                                    role="combobox"
                                    aria-expanded="true"
                                    aria-controls={listId}
                                    aria-activedescendant={visible[active] ? `${listId}-${active}` : undefined}
                                    aria-label={t('filters.searchOptions')}
                                    placeholder={t('filters.searchOptions')}
                                    value={query}
                                    onChange={(event) => {
                                        setQuery(event.target.value);
                                        setActive(0);
                                    }}
                                    className="control-input h-9 bg-raised text-sm"
                                />
                            </div>
                        )}
                        <ul
                            ref={listRef}
                            id={listId}
                            role="listbox"
                            aria-label={label}
                            aria-multiselectable={multiple || undefined}
                            aria-activedescendant={!withSearch && visible[active] ? `${listId}-${active}` : undefined}
                            tabIndex={withSearch ? -1 : 0}
                            className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-1 focus:outline-none"
                        >
                            {visible.length === 0 && <li className="px-3 py-4 text-center text-sm text-ink-muted">{t('filters.noOptions')}</li>}
                            {visible.map((option, index) => {
                                const isSelected = selected.includes(option.value);
                                const isActive = index === active;
                                return (
                                    <li
                                        key={option.value}
                                        id={`${listId}-${index}`}
                                        role="option"
                                        aria-selected={isSelected}
                                        aria-disabled={option.disabled || undefined}
                                        onMouseMove={() => index !== active && setActive(index)}
                                        onMouseDown={(event) => event.preventDefault()}
                                        onClick={() => toggle(option)}
                                        className={`flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm ${
                                            option.disabled ? 'cursor-not-allowed text-ink-faint' : isActive ? 'bg-sage text-ink' : 'text-ink'
                                        }`}
                                    >
                                        {multiple && (
                                            <span
                                                aria-hidden="true"
                                                className={`grid size-4 shrink-0 place-items-center rounded border ${isSelected ? 'border-primary bg-primary text-primary-fg' : 'border-line-strong bg-surface'}`}
                                            >
                                                {isSelected && <IconCheck size={11} />}
                                            </span>
                                        )}
                                        <span className="min-w-0 flex-1">
                                            <span className={`block truncate ${isSelected ? 'font-medium' : ''}`}>{option.label}</span>
                                            {option.description && <span className="block truncate text-[12px] text-ink-muted">{option.description}</span>}
                                        </span>
                                        {!multiple && isSelected && (
                                            <span className="text-accent">
                                                <IconCheck size={15} />
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                        {hasValue && (
                            <div className="flex items-center justify-between gap-2 border-t border-line px-2 py-1.5">
                                <span className="text-[12px] text-ink-muted">{multiple ? t('filters.selected').replace('{n}', String(selected.length)) : ''}</span>
                                <button
                                    type="button"
                                    onClick={() => {
                                        (onChange as (value: string[] | undefined) => void)(multiple ? [] : undefined);
                                        close();
                                    }}
                                    className="cursor-pointer rounded-md px-2 py-1 text-[13px] font-medium text-ink-muted transition-soft hover:bg-sage hover:text-ink"
                                >
                                    {t('filters.clear')}
                                </button>
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </div>
    );
}

/** Single-day filter (backend filters on one exact date), using the shared calendar popover. */
export function DateFilter({ label, value, onChange }: { label: string; value?: string | null; onChange: (value: string | undefined) => void }) {
    const labelId = useId();
    const hasValue = Boolean(value);
    return (
        <div
            className={`flex h-10 min-w-0 items-center gap-1 rounded-field border ps-3 pe-1 text-sm transition-soft focus-within:border-primary focus-within:ring-3 focus-within:ring-primary/15 ${
                hasValue ? 'border-accent/35 bg-accent-soft' : 'border-line-strong bg-surface'
            }`}
        >
            <span id={labelId} className="shrink-0 text-ink-muted">
                {label}
            </span>
            <DatePicker variant="bare" className="min-w-[8.5rem] flex-1" value={value ?? ''} onChange={(next) => onChange(next || undefined)} aria-labelledby={labelId} />
        </div>
    );
}

/** From/to date pair joined into one compact control; each end opens the shared calendar. */
export function DateRangeFilter({
    label,
    from,
    to,
    onChange,
}: {
    label: string;
    from?: string | null;
    to?: string | null;
    onChange: (range: { from?: string; to?: string }) => void;
}) {
    const { t } = useI18n();
    const labelId = useId();
    const hasValue = Boolean(from || to);
    return (
        <div
            role="group"
            aria-labelledby={labelId}
            className={`flex min-h-10 min-w-0 flex-wrap items-center gap-x-1 rounded-field border ps-3 pe-1 text-sm transition-soft focus-within:border-primary focus-within:ring-3 focus-within:ring-primary/15 ${
                hasValue ? 'border-accent/35 bg-accent-soft' : 'border-line-strong bg-surface'
            }`}
        >
            <span id={labelId} className="me-1 shrink-0 text-ink-muted">
                {label}
            </span>
            <DatePicker
                variant="bare"
                className="min-w-[9.5rem] flex-1"
                value={from ?? ''}
                max={to ?? undefined}
                placeholder={t('filters.from')}
                aria-label={`${label} — ${t('filters.from')}`}
                onChange={(next) => onChange({ from: next || undefined, to: to ?? undefined })}
            />
            <span aria-hidden="true" className="text-ink-faint">
                –
            </span>
            <DatePicker
                variant="bare"
                className="min-w-[9.5rem] flex-1"
                value={to ?? ''}
                min={from ?? undefined}
                placeholder={t('filters.to')}
                aria-label={`${label} — ${t('filters.to')}`}
                onChange={(next) => onChange({ from: from ?? undefined, to: next || undefined })}
            />
        </div>
    );
}

function IconSearch() {
    return (
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </svg>
    );
}

function IconX() {
    return (
        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            <path d="M18 6 6 18M6 6l12 12" />
        </svg>
    );
}

function IconCheck({ size = 14 }: { size?: number }) {
    return (
        <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
            <path d="m5 12.5 4.5 4.5L19 7.5" />
        </svg>
    );
}

function IconSliders() {
    return (
        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
            <path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0" />
            <circle cx="16" cy="6" r="2" />
            <circle cx="10" cy="12" r="2" />
            <circle cx="18" cy="18" r="2" />
        </svg>
    );
}
