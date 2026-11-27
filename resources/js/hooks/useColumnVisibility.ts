import { usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

export type ColumnDef = { key: string; label: string; defaultVisible: boolean; locked?: boolean };

const PREFIX = 'erp.columns.v1';

function read(storageKey: string, columns: ColumnDef[]): string[] | null {
    try {
        const raw = window.localStorage.getItem(storageKey);
        if (!raw) return null;
        const parsed: unknown = JSON.parse(raw);
        if (!Array.isArray(parsed)) return null;
        const known = new Set(columns.map((c) => c.key));
        return parsed.filter((k): k is string => typeof k === 'string' && known.has(k));
    } catch {
        return null;
    }
}

/**
 * Per-user, per-device column visibility for a table. Same persistence mechanism as the other display preferences
 * (localStorage; theme, locale, POS view), keyed by the authenticated user id and table key. Locked columns are always
 * visible. Storage may be unavailable (private window): the table then simply uses its defaults.
 */
export function useColumnVisibility(tableKey: string, columns: ColumnDef[]) {
    const userId = usePage<{ auth?: { user?: { id: number } | null } }>().props.auth?.user?.id ?? 'anon';
    const storageKey = `${PREFIX}:${userId}:${tableKey}`;
    const defaults = useMemo(() => columns.filter((c) => c.defaultVisible || c.locked).map((c) => c.key), [columns]);
    const [selected, setSelected] = useState<string[]>(() => read(storageKey, columns) ?? defaults);

    const visible = useMemo(() => new Set([...selected, ...columns.filter((c) => c.locked).map((c) => c.key)]), [selected, columns]);

    const persist = useCallback(
        (next: string[] | null) => {
            try {
                if (next === null) window.localStorage.removeItem(storageKey);
                else window.localStorage.setItem(storageKey, JSON.stringify(next));
            } catch {
                /* storage unavailable: preference lasts for this page view only */
            }
        },
        [storageKey],
    );

    const toggle = useCallback(
        (key: string) => {
            setSelected((current) => {
                const next = current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
                persist(next);
                return next;
            });
        },
        [persist],
    );

    const reset = useCallback(() => {
        setSelected(defaults);
        persist(null);
    }, [defaults, persist]);

    const isDefault = selected.length === defaults.length && defaults.every((k) => selected.includes(k));

    return { visible, isVisible: (key: string) => visible.has(key), toggle, reset, isDefault };
}
