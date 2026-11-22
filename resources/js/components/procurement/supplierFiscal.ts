/*
 * Supplier fiscal information: the ONE place that maps backend props to UI shape.
 * Contract (Inertia):
 *  - supplier.ice / tax_identifier / rc_number / default_payment_term_days / default_currency_code
 *  - supplier.bank_iban_masked: string|null  (detail page only; the full IBAN is never sent to the browser)
 *  - fiscal_warnings: string[]  (advisory, French, never blocking) — page-level prop on Show, or supplier.fiscal_warnings
 *  - on save: bank_iban is sent ONLY when the user typed a new value (omitted = keep current).
 */
export type SupplierFiscal = {
    ice: string | null;
    tax_identifier: string | null;
    rc_number: string | null;
    default_payment_term_days: number | null;
    default_currency_code: string | null;
    bank_iban_masked: string | null;
};

export type SupplierFiscalRaw = Partial<SupplierFiscal> & { fiscal_warnings?: string[] | null };

export type SupplierFiscalFormData = {
    ice: string;
    tax_identifier: string;
    rc_number: string;
    default_payment_term_days: string;
    default_currency_code: string;
    bank_iban: string;
};

export const blankFiscalForm: SupplierFiscalFormData = {
    ice: '',
    tax_identifier: '',
    rc_number: '',
    default_payment_term_days: '',
    default_currency_code: '',
    bank_iban: '',
};

export function mapSupplierFiscal(raw: SupplierFiscalRaw): SupplierFiscal {
    return {
        ice: raw.ice ?? null,
        tax_identifier: raw.tax_identifier ?? null,
        rc_number: raw.rc_number ?? null,
        default_payment_term_days: raw.default_payment_term_days ?? null,
        default_currency_code: raw.default_currency_code ?? null,
        bank_iban_masked: raw.bank_iban_masked ?? null,
    };
}

export function mapFiscalWarnings(raw: { fiscal_warnings?: string[] | null } | undefined, pageLevel?: string[] | null): string[] {
    return [...(pageLevel ?? raw?.fiscal_warnings ?? [])].filter((message) => typeof message === 'string' && message.trim() !== '');
}

export function fiscalToForm(raw: SupplierFiscalRaw): SupplierFiscalFormData {
    const fiscal = mapSupplierFiscal(raw);
    return {
        ice: fiscal.ice ?? '',
        tax_identifier: fiscal.tax_identifier ?? '',
        rc_number: fiscal.rc_number ?? '',
        default_payment_term_days: fiscal.default_payment_term_days === null ? '' : String(fiscal.default_payment_term_days),
        default_currency_code: fiscal.default_currency_code ?? '',
        bank_iban: '', // never prefilled: sensitive and not available to the browser
    };
}

/** Request payload: blanks become null (clears), IBAN is omitted unless a new one was typed. */
export function fiscalPayload(data: SupplierFiscalFormData): Record<string, string | number | null> {
    const nullable = (value: string) => (value.trim() === '' ? null : value.trim());
    const days = data.default_payment_term_days.trim();
    const payload: Record<string, string | number | null> = {
        ice: nullable(data.ice),
        tax_identifier: nullable(data.tax_identifier),
        rc_number: nullable(data.rc_number),
        default_payment_term_days: days === '' ? null : Number(days),
        default_currency_code: data.default_currency_code.trim() === '' ? null : data.default_currency_code.trim().toUpperCase(),
    };
    const iban = data.bank_iban.replace(/\s+/g, '');
    if (iban !== '') payload.bank_iban = iban;
    return payload;
}
