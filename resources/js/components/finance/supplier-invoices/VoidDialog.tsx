import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextAreaField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import type { TranslationKey } from '@/i18n/translations';
import { useState } from 'react';
import { canVoid, voidProblems } from './rules';
import { VOID_REASON_CODES } from './types';
import type { SupplierInvoiceVoidReasonCode } from './types';

export type VoidPayload = { reason_code: SupplierInvoiceVoidReasonCode; reason: string };

/**
 * Void confirmation: coded reason + text (mandatory for `other`). States, always
 * visible, that supplier credit notes (avoirs) are a future feature and are NOT a
 * reason to void. Mount it only while open so state resets between uses.
 */
export function VoidDialog({
    open,
    onClose,
    onConfirm,
    processing = false,
    errors = {},
}: {
    open: boolean;
    onClose: () => void;
    onConfirm: (payload: VoidPayload) => void;
    processing?: boolean;
    errors?: Partial<Record<keyof VoidPayload, string>>;
}) {
    const { t } = useI18n();
    const [code, setCode] = useState<SupplierInvoiceVoidReasonCode | ''>('');
    const [text, setText] = useState('');
    const [touched, setTouched] = useState(false);
    const problems = voidProblems(code, text);

    const submit = () => {
        setTouched(true);
        if (code === '' || !canVoid(code, text)) return;
        onConfirm({ reason_code: code, reason: text.trim() });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={t('supplierInvoice.void.title')}
            description={t('supplierInvoice.void.description')}
            size="md"
            danger
            dismissible={!processing}
            footer={
                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose} disabled={processing}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="button" variant="danger-solid" onClick={submit} loading={processing} loadingText={t('supplierInvoice.void.confirming')}>
                        {t('supplierInvoice.void.confirm')}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4 px-4 py-4 sm:px-6">
                <Alert tone="info" title={t('supplierInvoice.void.creditNoteTitle')}>
                    {t('supplierInvoice.void.creditNote')}
                </Alert>
                <SelectField
                    label={t('supplierInvoice.void.reasonCode')}
                    required
                    value={code}
                    onChange={(event) => setCode(event.target.value as SupplierInvoiceVoidReasonCode | '')}
                    error={errors.reason_code ?? (touched && problems.reasonCode ? t('common.required') : undefined)}
                >
                    <option value="">{t('supplierInvoice.void.reasonPlaceholder')}</option>
                    {VOID_REASON_CODES.map((reason) => (
                        <option key={reason} value={reason}>
                            {t(`supplierInvoice.void.reason.${reason}` as TranslationKey)}
                        </option>
                    ))}
                </SelectField>
                <TextAreaField
                    label={t('supplierInvoice.void.text')}
                    required={code === 'other'}
                    value={text}
                    onChange={(event) => setText(event.target.value)}
                    error={errors.reason ?? (touched && problems.text ? t('supplierInvoice.void.textRequired') : undefined)}
                />
            </div>
        </Dialog>
    );
}
