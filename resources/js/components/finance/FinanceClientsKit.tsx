import EmptyState from '@/components/ui/EmptyState';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { Link, usePage } from '@inertiajs/react';

/**
 * Shared presentation helpers for the Finance clients section (Phase 1 UI/UX).
 * Presentation only: no financial rule lives here.
 */

/** Current path without query string (so a page keeps working under its legacy URL AND its Finance clients alias). */
export function useCurrentPath(): string {
    return usePage().url.split('?')[0] ?? '';
}

export function useQueryParams(): URLSearchParams {
    const url = usePage().url;
    return new URLSearchParams(url.includes('?') ? url.slice(url.indexOf('?') + 1) : '');
}

/** The active `?section=` value, restricted to the page's known sections (anything else falls back). */
export function useSection<T extends string>(keys: readonly T[], fallback: T): T {
    const value = useQueryParams().get('section');
    return (keys as readonly string[]).includes(value ?? '') ? (value as T) : fallback;
}

export type SectionDef = { key: string; label: string; /** Explicit destination when the section is a different page. */ href?: string };

/** Compact tab strip: each tab is a real link (keyboard + middle-click friendly) that keeps month / store filters. */
export function SectionTabs({ sections, active, label }: { sections: SectionDef[]; active: string; label: string }) {
    const path = useCurrentPath();
    const params = useQueryParams();
    const hrefFor = (key: string) => {
        const next = new URLSearchParams(params);
        next.set('section', key);
        next.delete('page');
        return `${path}?${next.toString()}`;
    };
    return (
        <nav aria-label={label} className="mb-4 overflow-x-auto border-b border-line">
            <ul className="flex min-w-max gap-1">
                {sections.map((section) => {
                    const isActive = section.key === active;
                    return (
                        <li key={section.key}>
                            <Link
                                href={section.href ?? hrefFor(section.key)}
                                preserveState
                                preserveScroll
                                aria-current={isActive ? 'page' : undefined}
                                className={`-mb-px inline-flex h-10 items-center border-b-2 px-3.5 text-sm font-medium transition-soft ${
                                    isActive ? 'border-ink text-ink' : 'border-transparent text-ink-muted hover:text-ink'
                                }`}
                            >
                                {section.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/** Honest "not built yet" state. `plannedKey` says what will live here; there is never a fake figure or an active-looking action. */
export function FinancePlaceholder({ title, plannedKey }: { title?: string; plannedKey?: TranslationKey }) {
    const { t } = useI18n();
    return <EmptyState compact title={title ?? t('fc.placeholder')} description={plannedKey ? t(plannedKey) : t('fc.placeholderHint')} />;
}

/** Unavailable figures are an em dash, never a fabricated zero. */
export const UNAVAILABLE = '—';

/**
 * Phase 3: one tab strip per consolidated destination. Each tab is a real link to an existing authorized page
 * (legacy URLs are preserved), so relocating a page never changes what its route authorizes. Month and store filters carry over.
 */
export type ParentGroup = 'collections' | 'receivables' | 'reports';

const PARENT_TABS: Record<ParentGroup, { key: string; label: TranslationKey; path: string; section?: string }[]> = {
    collections: [
        { key: 'settlements', label: 'p3.tab.settlements', path: '/finance/clients/encaissements', section: 'settlements' },
        { key: 'advances', label: 'p3.tab.advances', path: '/finance/clients/avances' },
        { key: 'refunds', label: 'p3.tab.refunds', path: '/finance/clients/encaissements', section: 'refunds' },
        { key: 'instruments', label: 'p3.tab.instruments', path: '/finance/clients/instruments' },
    ],
    receivables: [
        { key: 'receivables', label: 'p3.tab.receivables', path: '/finance/clients/creances', section: 'receivables' },
        { key: 'dues', label: 'p3.tab.schedules', path: '/finance/clients/creances', section: 'dues' },
        { key: 'aging', label: 'p3.tab.aging', path: '/finance/clients/creances', section: 'aging' },
        { key: 'reminders', label: 'p3.tab.reminders', path: '/finance/clients/creances', section: 'reminders' },
        { key: 'forecasts', label: 'p3.tab.forecasts', path: '/finance/clients/creances', section: 'forecasts' },
        { key: 'credit', label: 'p3.tab.credit', path: '/finance/clients/credit' },
    ],
    reports: [
        { key: 'catalog', label: 'p3.tab.reports', path: '/finance/clients/rapports', section: 'catalog' },
        { key: 'profitability', label: 'p3.tab.profitability', path: '/finance/clients/rapports', section: 'profitability' },
        { key: 'journal', label: 'p3.tab.journal', path: '/finance/clients/journal' },
    ],
};

export function ParentTabs({ group, active }: { group: ParentGroup; active: string }) {
    const { t } = useI18n();
    const params = useQueryParams();
    const sections: SectionDef[] = PARENT_TABS[group].map((tab) => {
        const next = new URLSearchParams();
        for (const carried of ['month', 'store_id']) {
            const value = params.get(carried);
            if (value) next.set(carried, value);
        }
        if (tab.section) next.set('section', tab.section);
        const query = next.toString();
        return { key: tab.key, label: t(tab.label), href: query ? `${tab.path}?${query}` : tab.path };
    });
    return <SectionTabs sections={sections} active={active} label={t('p3.tabs.label')} />;
}
