import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

type FilterValue = string | string[] | undefined | null;
type FilterRecord = Record<string, FilterValue>;

const isEmpty = (value: FilterValue) => value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0);

function clean(record: FilterRecord): Record<string, string | string[]> {
    const output: Record<string, string | string[]> = {};
    Object.entries(record).forEach(([key, value]) => {
        if (!isEmpty(value)) output[key] = value as string | string[];
    });
    return output;
}

/**
 * URL-backed filter state for server-filtered index pages.
 *
 * - `filters` are the values the controller echoed back (source of truth).
 * - Changing a filter issues an Inertia GET to `path`, keeping unrelated query
 *   params (sort, direction, per_page…) and dropping `page` so results restart at 1.
 * - The search box is local + debounced so typing stays smooth.
 * - `loading` reflects the in-flight visit for result-area feedback.
 * Only send keys the backend validates; this hook never invents parameters.
 */
export function useQueryFilters<F extends FilterRecord>(
    path: string,
    filters: F,
    { searchKey = 'search', debounce = 300, keys }: { searchKey?: string; debounce?: number; keys?: (keyof F & string)[] } = {},
) {
    const [search, setSearchState] = useState(String(filters[searchKey] ?? ''));
    const [loading, setLoading] = useState(false);
    // Latest requested state: the server echo, or the last visit sent while it is in
    // flight. Every change builds on it, so quick successive changes never drop one
    // another and a pending debounced search can never replay stale filters.
    const requestedRef = useRef<FilterRecord>(filters);
    const requestedSearch = () => String(requestedRef.current[searchKey] ?? '').trim();

    // Server-confirmed values win after navigation (back/forward, pagination, reset).
    useEffect(() => {
        requestedRef.current = filters;
        setSearchState(String(filters[searchKey] ?? ''));
    }, [filters, searchKey]);

    const visit = useCallback(
        (next: FilterRecord) => {
            requestedRef.current = next;
            setSearchState((current) => (current.trim() === String(next[searchKey] ?? '').trim() ? current : String(next[searchKey] ?? '')));
            const params = new URLSearchParams(window.location.search);
            const preserved: FilterRecord = {};
            const filterKeys = new Set([...Object.keys(filters), ...Object.keys(next), ...(keys ?? [])]);
            params.forEach((value, key) => {
                const base = key.replace(/\[\d*\]$/, '');
                if (base === 'page' || filterKeys.has(base)) return;
                preserved[base] = value;
            });
            router.get(path, clean({ ...preserved, ...next }), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [path, keys, searchKey],
    );

    const set = useCallback((key: keyof F & string, value: FilterValue) => visit({ ...requestedRef.current, [key]: value }), [visit]);
    const patch = useCallback((values: Partial<F>) => visit({ ...requestedRef.current, ...values }), [visit]);
    const remove = useCallback((key: keyof F & string) => visit({ ...requestedRef.current, [key]: undefined }), [visit]);
    const reset = useCallback(() => {
        const cleared: FilterRecord = {};
        [...Object.keys(requestedRef.current), ...(keys ?? [])].forEach((key) => (cleared[key] = undefined));
        visit(cleared);
    }, [visit, keys]);

    // Debounced search -> URL (only when the typed value differs from what was last requested).
    useEffect(() => {
        if (search.trim() === requestedSearch()) return;
        const timer = window.setTimeout(() => visit({ ...requestedRef.current, [searchKey]: search.trim() || undefined }), debounce);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, searchKey, debounce, visit]);

    const activeKeys = (Object.keys(filters) as (keyof F & string)[]).filter((key) => !isEmpty(filters[key]));
    const searching = search.trim() !== String(filters[searchKey] ?? '').trim();

    return { values: filters, search, setSearch: setSearchState, set, patch, remove, reset, activeKeys, activeCount: activeKeys.length, loading: loading || searching, searching };
}
