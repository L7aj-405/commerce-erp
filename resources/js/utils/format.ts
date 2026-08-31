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
