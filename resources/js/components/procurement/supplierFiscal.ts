/*
 * Supplier fiscal information: the ONE place that maps backend props to UI shape.
 * Contract (Inertia):
 *  - supplier.ice / tax_identifier / rc_number / default_payment_term_days / default_currency_code
 *  - list rows: has_bank_iban (bool) only; show: has_bank_iban + bank_iban_masked ('****1234') (+ full bank_iban for managers, ignored here)
 *  - warnings: [{field, code, message}] top-level prop on Show (advisory, French, never blocking)
 *  - on save: bank_iban is sent ONLY when the user typed a new value (omitted = keep current).
 */
export type SupplierFiscal = {
    ice: string | null;
    tax_identifier: string | null;
    rc_number: string | null;
    default_payment_term_days: number | null;
    default_currency_code: string | null;
    bank_iban_masked: string | null;
    has_bank_iban: boolean;
};

/** Backend warning item (top-level `warnings` on Show). Codes: ice_format, tax_identifier_format, ice_duplicate. */
export type FiscalWarningItem = { field?: string; code?: string; message: string };

/** `bank_iban` (full value) may be present on Show for suppliers.manage users; the UI never displays or prefills it. */
export type SupplierFiscalRaw = Partial<Omit<SupplierFiscal, 'has_bank_iban'>> & { has_bank_iban?: boolean; bank_iban?: string | null };

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
        has_bank_iban: raw.has_bank_iban ?? raw.bank_iban_masked != null,
    };
}

export function mapFiscalWarnings(items: (FiscalWarningItem | string)[] | null | undefined): string[] {
    return (items ?? []).map((item) => (typeof item === 'string' ? item : item.message)).filter((message) => typeof message === 'string' && message.trim() !== '');
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
