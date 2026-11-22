import { Alert } from '@/components/ui/Alert';
import { TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { mapSupplierFiscal } from './supplierFiscal';
import type { SupplierFiscalFormData, SupplierFiscalRaw } from './supplierFiscal';

/** Advisory (non-blocking) warnings, shown as a hint above/below the fiscal data. Renders nothing when empty. */
export function FiscalWarnings({ warnings }: { warnings: string[] }) {
    const { t } = useI18n();
    if (warnings.length === 0) return null;
    return (
        <Alert tone="warning" title={t('suppliers.fiscal.warningsTitle')}>
            <ul className="list-disc space-y-1 ps-5">
                {warnings.map((message, index) => (
                    <li key={index}>{message}</li>
                ))}
            </ul>
            <p className="mt-1 text-[13px]">{t('suppliers.fiscal.warningsHint')}</p>
        </Alert>
    );
}

/** Form fields for the supplier create/edit sheet. State lives in the parent (Inertia useForm). */
export function SupplierFiscalFields({
    data,
    setData,
    errors,
    warnings = [],
    ibanMasked,
    hasIban = false,
}: {
    data: SupplierFiscalFormData;
    setData: <K extends keyof SupplierFiscalFormData>(key: K, value: SupplierFiscalFormData[K]) => void;
    errors: Partial<Record<keyof SupplierFiscalFormData, string>>;
    warnings?: string[];
    ibanMasked?: string | null;
    hasIban?: boolean;
}) {
    const { t } = useI18n();
    return (
        <fieldset className="grid gap-4 rounded-card border border-line p-4">
            <legend className="px-1 text-sm font-semibold text-ink">{t('suppliers.fiscal.title')}</legend>
            <FiscalWarnings warnings={warnings} />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label={t('suppliers.fiscal.ice')} dir="ltr" maxLength={32} value={data.ice} onChange={(e) => setData('ice', e.target.value)} error={errors.ice} />
                <TextField label={t('suppliers.fiscal.taxIdentifier')} dir="ltr" maxLength={32} value={data.tax_identifier} onChange={(e) => setData('tax_identifier', e.target.value)} error={errors.tax_identifier} />
            </div>
            <TextField label={t('suppliers.fiscal.rcNumber')} dir="ltr" maxLength={32} value={data.rc_number} onChange={(e) => setData('rc_number', e.target.value)} error={errors.rc_number} />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label={t('suppliers.fiscal.paymentTermDays')}
                    type="number"
                    inputMode="numeric"
                    min={0}
                    max={65535}
                    step={1}
                    dir="ltr"
                    value={data.default_payment_term_days}
                    onChange={(e) => setData('default_payment_term_days', e.target.value)}
                    error={errors.default_payment_term_days}
                />
                <TextField
                    label={t('suppliers.fiscal.currency')}
                    dir="ltr"
                    maxLength={3}
                    placeholder="MAD"
                    value={data.default_currency_code}
                    onChange={(e) => setData('default_currency_code', e.target.value.toUpperCase())}
                    error={errors.default_currency_code}
                />
            </div>
            <TextField
                label={t('suppliers.fiscal.iban')}
                dir="ltr"
                autoComplete="off"
                maxLength={40}
                value={data.bank_iban}
                onChange={(e) => setData('bank_iban', e.target.value)}
                error={errors.bank_iban}
                hint={`${t('suppliers.fiscal.ibanSensitive')}${ibanMasked ? ` ${ibanMasked}.` : hasIban ? ` ${t('suppliers.fiscal.ibanOnFile')}` : ''} ${t('suppliers.fiscal.ibanReplace')}`}
            />
        </fieldset>
    );
}

/** Read-only summary for the supplier detail page. IBAN is shown masked as sent by the server. */
export function SupplierFiscalSummary({ supplier, warnings }: { supplier: SupplierFiscalRaw; warnings: string[] }) {
    const { t } = useI18n();
    const fiscal = mapSupplierFiscal(supplier);
    const missing = t('suppliers.fiscal.notProvided');
    const items: [string, string | null][] = [
        [t('suppliers.fiscal.ice'), fiscal.ice],
        [t('suppliers.fiscal.taxIdentifier'), fiscal.tax_identifier],
        [t('suppliers.fiscal.rcNumber'), fiscal.rc_number],
        [t('suppliers.fiscal.paymentTermDays'), fiscal.default_payment_term_days === null ? null : String(fiscal.default_payment_term_days)],
        [t('suppliers.fiscal.currency'), fiscal.default_currency_code],
        [t('suppliers.fiscal.iban'), fiscal.bank_iban_masked],
    ];
    return (
        <section aria-labelledby="supplier-fiscal-title" className="mb-6 surface-card p-5 text-sm">
            <h2 id="supplier-fiscal-title" className="mb-3 text-base font-semibold text-ink">
                {t('suppliers.fiscal.title')}
            </h2>
            <FiscalWarnings warnings={warnings} />
            <dl className={`grid gap-3 sm:grid-cols-2 ${warnings.length > 0 ? 'mt-3' : ''}`}>
                {items.map(([label, value]) => (
                    <div key={label}>
                        <dt className="mb-1 text-[13px] text-ink-muted">{label}</dt>
                        <dd className="text-ink">{value ? <bdi className="tabular-nums">{value}</bdi> : <span className="text-ink-faint">{missing}</span>}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
