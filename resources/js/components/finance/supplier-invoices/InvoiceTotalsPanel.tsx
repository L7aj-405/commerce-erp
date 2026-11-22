import { useI18n } from '@/i18n';
import { formatMoney } from '@/utils/format';
import { decimalDiff, decimalEquals, interpolate } from './math';

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
}: {
    subtotalHt: string;
    totalTva: string;
    roundingAdjustment: string;
    totalTtc: string;
    controlTotalTtc: string | null | undefined;
    currencyCode: string;
    roundingTolerance?: string;
}) {
    const { t } = useI18n();
    const hasControl = controlTotalTtc !== null && controlTotalTtc !== undefined && controlTotalTtc.trim() !== '';
    const matches = hasControl && decimalEquals(controlTotalTtc, totalTtc);
    const diff = hasControl ? decimalDiff(controlTotalTtc, totalTtc) : null;

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
                <p role="status" className={`mt-1 flex items-start gap-1.5 text-[13px] font-medium ${matches ? 'text-success' : 'text-warning'}`}>
                    <span aria-hidden="true">{matches ? '✓' : '!'}</span>
                    <span>
                        {!hasControl
                            ? t('supplierInvoice.totals.controlMissing')
                            : matches
                              ? t('supplierInvoice.totals.controlMatch')
                              : interpolate(t('supplierInvoice.totals.controlMismatch'), { diff: formatMoney(diff ?? '0', currencyCode) })}
                    </span>
                </p>
            </div>
        </section>
    );
}
