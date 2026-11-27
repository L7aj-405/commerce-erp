import { Button } from '@/components/ui/Button';
import { DateField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { formatMoney } from '@/utils/format';
import { decimalDiff, decimalEquals, interpolate, parseScaled, sumDecimals } from './math';
import type { InstallmentDraft } from './types';

/**
 * Installment rows (due date + amount) with a running sum compared to the invoice
 * total TTC. Presentational: state lives in the parent form. `errors[i]` carries
 * server-side messages per row. Exact match uses string/BigInt arithmetic.
 */
export function InstallmentEditor({
    rows,
    onChange,
    totalTtc,
    currencyCode,
    invoiceDate,
    errors = {},
    disabled = false,
}: {
    rows: InstallmentDraft[];
    onChange: (rows: InstallmentDraft[]) => void;
    totalTtc: string;
    currencyCode: string;
    invoiceDate?: string | null;
    errors?: Record<number, { due_date?: string; amount?: string }>;
    disabled?: boolean;
}) {
    const { t } = useI18n();
    const { sum, invalid } = sumDecimals(rows.map((row) => row.amount));
    const exact = rows.length > 0 && invalid === 0 && decimalEquals(sum, totalTtc);
    const diff = decimalDiff(totalTtc, sum); // > 0: still to allocate, < 0: excess
    const diffScaled = parseScaled(diff);

    const update = (index: number, patch: Partial<InstallmentDraft>) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const remove = (index: number) => onChange(rows.filter((_, i) => i !== index));
    const add = () => onChange([...rows, { key: `new-${Date.now()}-${rows.length}`, due_date: '', amount: '' }]);

    let status: { tone: 'ok' | 'warn'; text: string } | null = null;
    if (rows.length === 0) status = { tone: 'warn', text: t('supplierInvoice.installments.empty') };
    else if (invalid > 0) status = { tone: 'warn', text: interpolate(t('supplierInvoice.installments.invalid'), { count: invalid }) };
    else if (exact) status = { tone: 'ok', text: t('supplierInvoice.installments.exact') };
    else if (diff !== null && diffScaled !== null) {
        const abs = diff.replace('-', '');
        status = {
            tone: 'warn',
            text: interpolate(t(diffScaled > 0n ? 'supplierInvoice.installments.remaining' : 'supplierInvoice.installments.excess'), { diff: formatMoney(abs, currencyCode) }),
        };
    }

    return (
        <section aria-labelledby="si-installments-title" className="rounded-card border border-line bg-surface p-4">
            <h3 id="si-installments-title" className="text-sm font-semibold text-ink">
                {t('supplierInvoice.installments.title')}
            </h3>
            <ul className="mt-3 space-y-3">
                {rows.map((row, index) => {
                    const beforeInvoice = Boolean(invoiceDate && row.due_date && row.due_date < invoiceDate);
                    const rowErrors = errors[index] ?? {};
                    return (
                        <li key={row.key} className="grid grid-cols-1 items-start gap-3 sm:grid-cols-[1fr_1fr_auto]">
                            <DateField
                                label={`${t('supplierInvoice.installments.dueDate')} · ${interpolate(t('supplierInvoice.installments.row'), { n: index + 1 })}`}
                                value={row.due_date}
                                onChange={(value: string) => update(index, { due_date: value })}
                                disabled={disabled}
                                error={rowErrors.due_date ?? (beforeInvoice ? t('supplierInvoice.installments.beforeInvoice') : undefined)}
                            />
                            <TextField
                                label={t('supplierInvoice.installments.amount')}
                                inputMode="decimal"
                                dir="ltr"
                                value={row.amount}
                                onChange={(event) => update(index, { amount: event.target.value })}
                                disabled={disabled}
                                error={rowErrors.amount ?? (row.amount.trim() !== '' && parseScaled(row.amount) === null ? t('supplierInvoice.installments.invalid').replace('{count}', '1') : undefined)}
                            />
                            {!disabled && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="sm:mt-7"
                                    onClick={() => remove(index)}
                                    aria-label={interpolate(t('supplierInvoice.installments.remove'), { n: index + 1 })}
                                >
                                    {t('common.remove')}
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
            {!disabled && (
                <Button type="button" variant="secondary" size="sm" className="mt-3" onClick={add}>
                    {t('supplierInvoice.installments.add')}
                </Button>
            )}
            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-line pt-3 text-sm">
                <dt className="text-ink-muted">{t('supplierInvoice.installments.sum')}</dt>
                <dd className="text-end font-semibold tabular-nums text-ink">{formatMoney(sum, currencyCode)}</dd>
                <dt className="text-ink-muted">{t('supplierInvoice.installments.invoiceTotal')}</dt>
                <dd className="text-end font-semibold tabular-nums text-ink">{formatMoney(totalTtc, currencyCode)}</dd>
            </dl>
            {status && (
                <p role="status" className={`mt-2 flex items-start gap-1.5 text-[13px] font-medium ${status.tone === 'ok' ? 'text-success' : 'text-warning'}`}>
                    <span aria-hidden="true">{status.tone === 'ok' ? '✓' : '!'}</span>
                    <span>{status.text}</span>
                </p>
            )}
        </section>
    );
}
