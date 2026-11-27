function uiLocale(): string {
    return typeof document !== 'undefined' && document.documentElement.lang === 'ar' ? 'ar-MA' : 'fr-FR';
}

const numberFormatter = () => new Intl.NumberFormat(uiLocale());
const quantityFormatter = () => new Intl.NumberFormat(uiLocale(), {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
});
const moneyFormatter = () => new Intl.NumberFormat(uiLocale(), {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export function formatQuantity(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '0';
    }

    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return String(value);
    }

    return quantityFormatter().format(numeric);
}

/**
 * Keep exact decimal input values while removing database scale that is not
 * meaningful to the person editing the field (for example 5.0000 -> 5).
 */
export function formatEditableNumber(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const raw = String(value).trim();

    if (!/^-?\d+(?:\.\d+)?$/.test(raw)) {
        return raw;
    }

    return raw.replace(/(\.\d*?[1-9])0+$/, '$1').replace(/\.0+$/, '');
}

export function formatSignedQuantity(value: number | string | null | undefined): string {
    const numeric = Number(value ?? 0);

    if (!Number.isFinite(numeric)) {
        return String(value ?? '0');
    }

    if (numeric > 0) {
        return `+${formatQuantity(numeric)}`;
    }

    return formatQuantity(numeric);
}

export function formatMoney(value: number | string | null | undefined, currencyCode = 'MAD'): string {
    if (value === null || value === undefined || value === '') {
        return currencyCode === 'MAD' ? '0,00 DH' : `0,00 ${currencyCode}`;
    }

    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return String(value);
    }

    const normalized = Object.is(numeric, -0) || Math.abs(numeric) < 0.00005 ? 0 : numeric;
    const formatted = moneyFormatter().format(normalized);

    return currencyCode === 'MAD' ? `${formatted} DH` : `${formatted} ${currencyCode}`;
}

export function formatDate(value: string): string {
    return new Intl.DateTimeFormat(uiLocale()).format(new Date(value));
}

/**
 * Parses a backend timestamp without ever throwing. Returns null for null, empty,
 * malformed or placeholder values ("Unverified", "N/A"...), so callers can render a
 * labelled fallback instead of crashing on `Intl.DateTimeFormat#format(Invalid Date)`.
 */
export function parseDateValue(value: unknown): Date | null {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value !== 'string' && typeof value !== 'number') return null;
    if (typeof value === 'string' && value.trim() === '') return null;
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value: string | number | null | undefined, fallback = '—'): string {
    const date = parseDateValue(value);
    if (date === null) return fallback;
    try {
        return new Intl.DateTimeFormat(uiLocale(), {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(date);
    } catch {
        return fallback;
    }
}

export function formatInteger(value: number): string {
    return numberFormatter().format(value);
}

/**
 * Business (calendar) dates arrive as `YYYY-MM-DD` or as a midnight timestamp of that same day. They are formatted from their
 * own Y-M-D parts in UTC, so the displayed day never shifts with the viewer's timezone and no time-of-day is ever shown.
 */
export function formatBusinessDate(value: string | null | undefined, fallback = '—'): string {
    if (value === null || value === undefined || value === '') return fallback;
    const parts = /^(\d{4})-(\d{2})-(\d{2})/u.exec(value);
    if (parts === null) return fallback;
    const date = new Date(Date.UTC(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3])));

    return new Intl.DateTimeFormat(uiLocale(), { timeZone: 'UTC' }).format(date);
}
