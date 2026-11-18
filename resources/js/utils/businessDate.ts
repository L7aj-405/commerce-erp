import type { SharedPageProps } from '@/types/app';
import { usePage } from '@inertiajs/react';

/** Browser-local calendar date (`Y-m-d`) — only a fallback, never UTC (`toISOString`). */
export function browserLocalDate(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * The organization's business "today" (its configured timezone, e.g.
 * Africa/Casablanca), as the server computes it — the default for every
 * business-date field so the form and the server's "today" never disagree
 * around midnight.
 */
export function useBusinessToday(): string {
    const { props } = usePage<SharedPageProps>();
    return props.tenant?.business_today ?? browserLocalDate();
}

/**
 * Calendar arithmetic on `Y-m-d` strings, independent of the browser timezone (UTC maths on a date-only value,
 * so adding days to the organization's business "today" never shifts across midnight).
 */
export function addDaysToDate(date: string, days: number): string {
    const [y, m, d] = date.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}
