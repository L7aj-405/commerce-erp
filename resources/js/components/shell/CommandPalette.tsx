import { useI18n } from '@/i18n';
import { buildQuickActions, isNavSection, IconSearch, IconUsers, SEARCHABLE_INDEX_HREFS } from '@/layouts/navigation';
import type { NavGroup } from '@/layouts/navigation';
import type { SharedPageProps } from '@/types/app';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { createPortal } from 'react-dom';

/*
 * Global command palette (header search button, Ctrl/⌘+K).
 *
 * Every result is real and permission-filtered:
 *  - Pages and quick actions come from the navigation registry (already filtered
 *    by permission + store context by the shell).
 *  - "Search in …" rows deep-link to index pages whose controllers accept `?search=`.
 *  - Live customer records use the existing organization-scoped
 *    GET /quotations/customer-search endpoint, only when the user holds both
 *    permissions that endpoint enforces (quotations.create + customers.view).
 * There is no cross-entity search endpoint yet (products, orders, invoices…);
 * those are reachable through the "Search in …" rows instead of invented results.
 */

type Result = { id: string; href: string; label: string; meta?: string; icon: ReactNode };
type Section = { key: string; title: string; results: Result[] };
type CustomerHit = { id: number; display_name: string; company_name?: string | null; phone?: string | null; email?: string | null };

const normalize = (value: string) =>
    value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

function score(haystack: string, needle: string) {
    const text = normalize(haystack);
    if (text.startsWith(needle)) return 3;
    if (text.split(/[\s/·-]+/).some((word) => word.startsWith(needle))) return 2;
    return text.includes(needle) ? 1 : 0;
}

export default function CommandPalette({ open, onClose, groups }: { open: boolean; onClose: () => void; groups: NavGroup[] }) {
    if (!open) return null;
    return createPortal(<Palette onClose={onClose} groups={groups} />, document.body);
}

function Palette({ onClose, groups }: { onClose: () => void; groups: NavGroup[] }) {
    const { t, direction } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const baseId = useId();
    const listId = `${baseId}-list`;
    const inputRef = useRef<HTMLInputElement | null>(null);
    const listRef = useRef<HTMLDivElement | null>(null);
    const openerRef = useRef<HTMLElement | null>(document.activeElement instanceof HTMLElement ? document.activeElement : null);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const needle = normalize(query.trim());

    /* Focus, scroll lock, focus restoration -------------------------------- */
    useEffect(() => {
        inputRef.current?.focus();
        const previous = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        const opener = openerRef.current;
        return () => {
            document.documentElement.style.overflow = previous;
            if (opener?.isConnected) window.setTimeout(() => opener.focus(), 0);
        };
    }, []);

    /* Live customers (real endpoint, permission-gated) -------------------- */
    const canSearchCustomers = tenant.permissions.includes('quotations.create') && tenant.permissions.includes('customers.view');
    const [customers, setCustomers] = useState<{ status: 'idle' | 'loading' | 'error' | 'done'; items: CustomerHit[] }>({ status: 'idle', items: [] });
    useEffect(() => {
        const term = query.trim();
        if (!canSearchCustomers || term.length < 2) {
            setCustomers({ status: 'idle', items: [] });
            return;
        }
        const controller = new AbortController();
        setCustomers((current) => ({ status: 'loading', items: current.items }));
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(`/quotations/customer-search?search=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error(String(response.status));
                const body = (await response.json()) as { data: CustomerHit[] };
                setCustomers({ status: 'done', items: body.data.slice(0, 6) });
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setCustomers({ status: 'error', items: [] });
            }
        }, 220);
        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, canSearchCustomers]);

    /* Result sections ------------------------------------------------------ */
    const sections = useMemo<Section[]>(() => {
        const pages: (Result & { rank: number })[] = [];
        groups.forEach((group) =>
            group.items.forEach((entry) => {
                const items = isNavSection(entry) ? entry.items : [entry];
                const context = isNavSection(entry) ? `${group.label} · ${entry.label}` : group.label;
                items.forEach((item) => {
                    const rank = needle ? Math.max(score(item.label, needle) * 2, score(context, needle)) : 1;
                    if (rank > 0) pages.push({ id: `page:${item.href}`, href: item.href, label: item.label, meta: context === item.label ? undefined : context, icon: item.icon, rank });
                });
            }),
        );
        pages.sort((a, b) => b.rank - a.rank);

        const actions = buildQuickActions(t, tenant)
            .filter((action) => !needle || score(action.label, needle) > 0)
            .map((action) => ({ id: `action:${action.href}`, href: action.href, label: action.label, icon: action.icon }));

        const visibleHrefs = new Map<string, { label: string; icon: ReactNode }>();
        groups.forEach((group) =>
            group.items.forEach((entry) => (isNavSection(entry) ? entry.items : [entry]).forEach((item) => visibleHrefs.set(item.href, item))),
        );
        const term = query.trim();
        const searchIn: Result[] =
            term.length >= 2
                ? SEARCHABLE_INDEX_HREFS.filter((href) => visibleHrefs.has(href)).map((href) => {
                      const target = visibleHrefs.get(href)!;
                      return {
                          id: `search:${href}`,
                          href: `${href}?search=${encodeURIComponent(term)}`,
                          label: t('palette.searchInItem').replace('{q}', term).replace('{module}', target.label),
                          icon: target.icon,
                      };
                  })
                : [];

        const customerResults: Result[] = customers.items.map((customer) => ({
            id: `customer:${customer.id}`,
            href: `/sales/customers/${customer.id}`,
            label: customer.display_name,
            meta: [customer.company_name, customer.phone ?? customer.email].filter(Boolean).join(' · ') || undefined,
            icon: <IconUsers />,
        }));

        return [
            { key: 'actions', title: t('palette.actions'), results: actions },
            { key: 'customers', title: t('palette.customers'), results: customerResults },
            { key: 'pages', title: t('palette.pages'), results: needle ? pages.slice(0, 12) : pages },
            { key: 'search', title: t('palette.searchIn'), results: searchIn },
        ].filter((section) => section.results.length > 0);
    }, [groups, needle, query, customers.items, t, tenant]);

    const flat = useMemo(() => sections.flatMap((section) => section.results), [sections]);

    // Restart at the top when the query or async customer results change the list shape.
    useEffect(() => setActive(0), [needle, customers.items]);
    useEffect(() => {
        if (active >= flat.length) setActive(Math.max(0, flat.length - 1));
    }, [flat.length, active]);
    useEffect(() => {
        listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const go = (result: Result | undefined) => {
        if (!result) return;
        openerRef.current = null; // navigation moves focus to the new page
        onClose();
        router.visit(result.href);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            onClose();
        } else if (event.key === 'ArrowDown' && flat.length) {
            event.preventDefault();
            setActive((index) => (index + 1) % flat.length);
        } else if (event.key === 'ArrowUp' && flat.length) {
            event.preventDefault();
            setActive((index) => (index - 1 + flat.length) % flat.length);
        } else if (event.key === 'Home' && event.ctrlKey) {
            setActive(0);
        } else if (event.key === 'End' && event.ctrlKey) {
            setActive(flat.length - 1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(flat[active]);
        } else if (event.key === 'Tab') {
            // Single focus stop: keep focus on the input (options are virtual-focused).
            event.preventDefault();
        }
    };

    const activeId = flat[active] ? `${listId}-${active}` : undefined;
    const customersPending = canSearchCustomers && query.trim().length >= 2 && customers.status === 'loading';
    let index = -1;

    return (
        <div className="fixed inset-0 z-[70] flex items-start justify-center px-3 pt-[12vh] sm:px-6" dir={direction} onKeyDown={onKeyDown}>
            <button type="button" tabIndex={-1} aria-label={t('common.closeMenu')} onClick={onClose} className="animate-overlay-in glass-scrim absolute inset-0 cursor-default" />
            <div
                role="dialog"
                aria-modal="true"
                aria-label={t('palette.title')}
                className="animate-panel-in glass-popover relative flex max-h-[min(36rem,76dvh)] w-full max-w-2xl flex-col overflow-hidden rounded-panel"
            >
                <div className="flex items-center gap-3 border-b border-line px-4">
                    <span aria-hidden="true" className="text-ink-faint [&>svg]:size-[18px]">
                        <IconSearch />
                    </span>
                    <input
                        ref={inputRef}
                        role="combobox"
                        aria-expanded="true"
                        aria-controls={listId}
                        aria-activedescendant={activeId}
                        aria-autocomplete="list"
                        aria-label={t('palette.title')}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t('palette.placeholder')}
                        autoComplete="off"
                        spellCheck={false}
                        className="h-14 min-w-0 flex-1 border-0 bg-transparent text-base text-ink outline-none placeholder:text-ink-faint focus:outline-none focus-visible:outline-none"
                    />
                    {customersPending && (
                        <span className="text-[13px] text-ink-muted" role="status">
                            {t('palette.loading')}
                        </span>
                    )}
                    <kbd className="hidden rounded-md border border-line bg-raised px-1.5 py-0.5 font-sans text-[11px] font-medium text-ink-muted sm:inline">Esc</kbd>
                </div>

                <div ref={listRef} id={listId} role="listbox" aria-label={t('palette.results')} className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2">
                    {sections.map((section) => (
                        <div key={section.key} role="group" aria-labelledby={`${baseId}-${section.key}`} className="pb-2 last:pb-0">
                            <p id={`${baseId}-${section.key}`} className="px-2.5 pt-2 pb-1 text-[13px] font-medium text-ink-faint">
                                {section.title}
                            </p>
                            {section.results.map((result) => {
                                index += 1;
                                const current = index;
                                const isActive = current === active;
                                return (
                                    <div
                                        key={result.id}
                                        id={`${listId}-${current}`}
                                        data-index={current}
                                        role="option"
                                        aria-selected={isActive}
                                        onMouseMove={() => current !== active && setActive(current)}
                                        onMouseDown={(event) => event.preventDefault()}
                                        onClick={() => go(result)}
                                        className={`flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-sm ${
                                            isActive ? 'bg-accent-soft text-ink' : 'text-ink'
                                        }`}
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={`grid size-8 shrink-0 place-items-center rounded-lg border [&>svg]:size-4 ${
                                                isActive ? 'border-accent/25 bg-surface text-accent' : 'border-line bg-raised text-ink-muted'
                                            }`}
                                        >
                                            {result.icon}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">{result.label}</span>
                                            {result.meta && <span className="block truncate text-[13px] text-ink-muted">{result.meta}</span>}
                                        </span>
                                        {isActive && (
                                            <kbd aria-hidden="true" className="hidden rounded-md border border-line bg-surface px-1.5 py-0.5 font-sans text-[11px] text-ink-muted sm:inline">
                                                ↵
                                            </kbd>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    ))}

                    {flat.length === 0 && !customersPending && (
                        <p className="px-3 py-10 text-center text-sm text-ink-muted" role="status">
                            {t('palette.empty').replace('{q}', query.trim())}
                        </p>
                    )}
                    {customers.status === 'error' && (
                        <p className="mx-2 mt-1 rounded-lg bg-danger-soft px-3 py-2 text-[13px] text-danger" role="alert">
                            {t('palette.error')}
                        </p>
                    )}
                </div>

                <div className="hidden items-center gap-4 border-t border-line bg-raised/70 px-4 py-2 text-[12px] text-ink-muted sm:flex">
                    <span className="flex items-center gap-1.5">
                        <Key>↑</Key>
                        <Key>↓</Key>
                        {t('palette.hintNavigate')}
                    </span>
                    <span className="flex items-center gap-1.5">
                        <Key>↵</Key>
                        {t('palette.hintOpen')}
                    </span>
                    <span className="flex items-center gap-1.5">
                        <Key>Esc</Key>
                        {t('palette.hintClose')}
                    </span>
                    {canSearchCustomers ? null : <span className="ms-auto">{t('palette.scopeNote')}</span>}
                </div>
            </div>
        </div>
    );
}

function Key({ children }: { children: ReactNode }) {
    return <kbd className="grid min-w-5 place-items-center rounded border border-line bg-surface px-1 font-sans text-[11px] text-ink-muted">{children}</kbd>;
}
