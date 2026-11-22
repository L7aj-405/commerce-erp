import { Alert } from '@/components/ui/Alert';
import { Checkbox } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import type { SupplierInvoiceWarning } from './types';

/**
 * Non-blocking duplicate/consistency warnings. When `onAcknowledgedChange` is given
 * each warning gets a checkbox; `acknowledged` is the list of warning CODES ticked
 * (approval requires every shown code); otherwise read-only.
 * Renders nothing when there is no warning.
 */
export function SoftWarningList({
    warnings,
    acknowledged = [],
    onAcknowledgedChange,
    error,
}: {
    warnings: SupplierInvoiceWarning[];
    acknowledged?: string[];
    onAcknowledgedChange?: (codes: string[]) => void;
    error?: string;
}) {
    const { t } = useI18n();
    if (warnings.length === 0) return null;
    return (
        <Alert tone="warning" title={t('supplierInvoice.warnings.title')}>
            <p>{t('supplierInvoice.warnings.advisory')}</p>
            {onAcknowledgedChange ? (
                <fieldset className="mt-2">
                    <legend className="text-sm font-semibold text-ink">{t('supplierInvoice.warnings.acknowledge')}</legend>
                    <ul className="mt-1 space-y-1">
                        {warnings.map((warning, index) => (
                            <li key={`${warning.code}-${index}`}>
                                <Checkbox
                                    label={warning.message}
                                    checked={acknowledged.includes(warning.code)}
                                    onChange={(event) =>
                                        onAcknowledgedChange(event.target.checked ? [...new Set([...acknowledged, warning.code])] : acknowledged.filter((code) => code !== warning.code))
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                    {error && <p className="mt-1 text-[13px] font-medium text-danger">{error}</p>}
                </fieldset>
            ) : (
                <ul className="mt-2 list-disc space-y-1 ps-5">
                    {warnings.map((warning, index) => (
                        <li key={`${warning.code}-${index}`}>{warning.message}</li>
                    ))}
                </ul>
            )}
        </Alert>
    );
}
