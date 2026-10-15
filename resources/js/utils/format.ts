const frenchNumber = new Intl.NumberFormat('fr-FR');
const frenchQuantity = new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
});
const frenchMoney = new Intl.NumberFormat('fr-FR', {
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

    return frenchQuantity.format(numeric);
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
    const formatted = frenchMoney.format(normalized);

    return currencyCode === 'MAD' ? `${formatted} DH` : `${formatted} ${currencyCode}`;
}

export function formatDate(value: string): string {
    return new Intl.DateTimeFormat('fr-FR').format(new Date(value));
}

export function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

export function formatInteger(value: number): string {
    return frenchNumber.format(value);
}
