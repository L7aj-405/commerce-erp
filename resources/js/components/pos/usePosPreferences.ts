import { useCallback, useEffect, useState } from 'react';

/**
 * Persistent, lightweight POS display preferences. These are per-device
 * conveniences (how the catalogue looks), never business state — the current
 * sale, customer and search are deliberately NOT stored here.
 */
export type PosViewMode = 'grid' | 'table';
export type PosCardSize = 'compact' | 'normal' | 'large';

export type PosPreferences = {
    viewMode: PosViewMode;
    cardSize: PosCardSize;
    columns: number;
    showImages: boolean;
    showLocalStock: boolean;
    showCompanyStock: boolean;
};

export const MIN_COLUMNS = 2;
export const MAX_COLUMNS = 6;

const STORAGE_KEY = 'pos.display.v1';

const DEFAULTS: PosPreferences = {
    viewMode: 'grid',
    cardSize: 'normal',
    columns: 4,
    showImages: true,
    showLocalStock: true,
    showCompanyStock: true,
};

function clampColumns(value: unknown): number {
    const numeric = Math.round(Number(value));
    if (!Number.isFinite(numeric)) return DEFAULTS.columns;
    return Math.min(MAX_COLUMNS, Math.max(MIN_COLUMNS, numeric));
}

function read(): PosPreferences {
    if (typeof window === 'undefined') return DEFAULTS;

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) return DEFAULTS;

        const parsed = JSON.parse(raw) as Partial<PosPreferences>;

        return {
            viewMode: parsed.viewMode === 'table' ? 'table' : 'grid',
            cardSize: parsed.cardSize === 'compact' || parsed.cardSize === 'large' ? parsed.cardSize : 'normal',
            columns: clampColumns(parsed.columns),
            showImages: parsed.showImages !== false,
            showLocalStock: parsed.showLocalStock !== false,
            showCompanyStock: parsed.showCompanyStock !== false,
        };
    } catch {
        return DEFAULTS;
    }
}

export function usePosPreferences() {
    const [preferences, setPreferences] = useState<PosPreferences>(DEFAULTS);

    // Hydrate after mount so SSR / disabled-storage browsers render the default.
    useEffect(() => {
        setPreferences(read());
    }, []);

    const update = useCallback((patch: Partial<PosPreferences>) => {
        setPreferences((current) => {
            const next: PosPreferences = {
                ...current,
                ...patch,
                columns: patch.columns !== undefined ? clampColumns(patch.columns) : current.columns,
            };

            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
            } catch {
                // Private mode / storage disabled — preference stays in-memory for the session.
            }

            return next;
        });
    }, []);

    return { preferences, update };
}
