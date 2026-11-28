import { Notice } from '@/components/inventory/TransferRequestUi';
import { useI18n } from '@/i18n';

/** Why the server refused a receipt, shown inside the confirmation dialog. Every error key is shown. */
export default function ReceiptRefusal({ errors }: { errors: Record<string, string> }) {
    const { t } = useI18n();
    const reasons = [...new Set(Object.values(errors).filter(Boolean))];
    if (reasons.length === 0) return null;

    return (
        <div role="alert">
            <Notice tone="danger" title={t('inventory.trq.receiveRefused')}>
                {reasons.map((reason) => (
                    <p key={reason}>{reason}</p>
                ))}
                <p className="mt-1">{t('inventory.trq.receiveRefusedHelp')}</p>
            </Notice>
        </div>
    );
}
