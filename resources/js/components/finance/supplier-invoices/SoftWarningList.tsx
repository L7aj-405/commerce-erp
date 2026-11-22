import { Alert } from '@/components/ui/Alert';
import { Checkbox } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import type { SupplierInvoiceWarning } from './types';

/**
 * Non-blocking duplicate/consistency warnings. When `onAcknowledgeChange` is given
 * a checkbox is shown (approval requires acknowledging them); otherwise read-only.
 * Renders nothing when there is no warning.
 */
export function SoftWarningList({
    warnings,
    acknowledged,
    onAcknowledgeChange,
    error,
}: {
    warnings: SupplierInvoiceWarning[];
    acknowledged?: boolean;
    onAcknowledgeChange?: (value: boolean) => void;
    error?: string;
}) {
    const { t } = useI18n();
    if (warnings.length === 0) return null;
    return (
        <Alert tone="warning" title={t('supplierInvoice.warnings.title')}>
            <p>{t('supplierInvoice.warnings.advisory')}</p>
            <ul className="mt-2 list-disc space-y-1 ps-5">
                {warnings.map((warning, index) => (
                    <li key={`${warning.code}-${index}`}>{warning.message}</li>
                ))}
            </ul>
            {onAcknowledgeChange && (
                <div className="mt-3">
                    <Checkbox label={t('supplierInvoice.warnings.acknowledge')} checked={Boolean(acknowledged)} onChange={(event) => onAcknowledgeChange(event.target.checked)} aria-invalid={error ? true : undefined} />
                    {error && <p className="mt-1 text-[13px] font-medium text-danger">{error}</p>}
                </div>
            )}
        </Alert>
    );
}
