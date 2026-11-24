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
