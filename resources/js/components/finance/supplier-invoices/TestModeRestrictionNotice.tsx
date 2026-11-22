import { Alert } from '@/components/ui/Alert';
import { useI18n } from '@/i18n';

/** Shown in place of supplier-invoice actions when the session is in Test Mode (feature is Production-only). */
export function TestModeRestrictionNotice() {
    const { t } = useI18n();
    return (
        <Alert tone="info" title={t('supplierInvoice.testMode.title')}>
            {t('supplierInvoice.testMode.body')}
        </Alert>
    );
}
