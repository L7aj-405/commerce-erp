import { useI18n } from '@/i18n';
import { formatMoney } from '@/utils/format';
import { interpolate } from './math';
import { controlTotalState } from './rules';
import type { SupplierInvoiceStatus } from './types';

/**
 * HT / TVA / rounding adjustment / TTC (payable) plus the control-total check
 * against the printed invoice. Values are server-computed decimal strings.
 */
export function InvoiceTotalsPanel({
    subtotalHt,
    totalTva,
    roundingAdjustment,
    totalTtc,
    controlTotalTtc,
    currencyCode,
    roundingTolerance,
    status,
}: {
    subtotalHt: string;
    totalTva: string;
    roundingAdjustment: string;
    totalTtc: string;
    controlTotalTtc: string | null | undefined;
    currencyCode: string;
    roundingTolerance?: string;
    /** Omit while composing a new invoice (treated as draft). */
    status?: SupplierInvoiceStatus;
}) {
    const { t } = useI18n();
    const control = controlTotalState(controlTotalTtc, totalTtc, status);
    const hasControl = control.kind === 'match' || control.kind === 'mismatch';
    const matches = control.kind === 'match';
    const neutral = control.kind === 'missing_not_recorded';

    const row = (label: string, value: string, strong = false) => (
        <div className={`flex items-baseline justify-between gap-4 py-1.5 ${strong ? 'border-t border-line pt-3 text-base' : 'text-sm'}`}>
            <dt className={strong ? 'font-semibold text-ink' : 'text-ink-muted'}>{label}</dt>
            <dd className={`tabular-nums ${strong ? 'font-bold text-ink' : 'font-medium text-ink'}`}>{formatMoney(value, currencyCode)}</dd>
        </div>
    );

    return (
        <section aria-labelledby="si-totals-title" className="rounded-card border border-line bg-surface p-4">
            <h3 id="si-totals-title" className="text-sm font-semibold text-ink">
                {t('supplierInvoice.totals.title')}
            </h3>
            <dl className="mt-2">
                {row(t('supplierInvoice.totals.ht'), subtotalHt)}
                {row(t('supplierInvoice.totals.tva'), totalTva)}
                {row(t('supplierInvoice.totals.rounding'), roundingAdjustment)}
                {row(t('supplierInvoice.totals.ttc'), totalTtc, true)}
            </dl>
            {roundingTolerance && <p className="mt-1 text-[12.5px] text-ink-faint">{interpolate(t('supplierInvoice.totals.roundingHint'), { tolerance: formatMoney(roundingTolerance, currencyCode) })}</p>}
            <div className="mt-3 border-t border-line pt-3 text-sm">
                {hasControl && (
                    <div className="flex items-baseline justify-between gap-4">
                        <span className="text-ink-muted">{t('supplierInvoice.totals.control')}</span>
                        <span className="font-medium tabular-nums text-ink">{formatMoney(controlTotalTtc, currencyCode)}</span>
                    </div>
                )}
                <p role="status" className={`mt-1 flex items-start gap-1.5 text-[13px] font-medium ${matches ? 'text-success' : neutral ? 'text-ink-muted' : 'text-warning'}`}>
                    <span aria-hidden="true">{matches ? '✓' : neutral ? 'ⓘ' : '!'}</span>
                    <span>
                        {control.kind === 'missing_required'
                            ? t('supplierInvoice.totals.controlMissing')
                            : control.kind === 'missing_not_recorded'
                              ? t('supplierInvoice.totals.controlNotRecorded')
                              : matches
                                ? t('supplierInvoice.totals.controlMatch')
                                : interpolate(t('supplierInvoice.totals.controlMismatch'), { diff: formatMoney(control.diff ?? '0', currencyCode) })}
                    </span>
                </p>
            </div>
        </section>
    );
}
