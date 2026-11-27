import { Button } from '@/components/ui/Button';
import { Sheet } from '@/components/ui/Sheet';
import { useI18n } from '@/i18n';
import { useId, useState } from 'react';
import ContactForm from './ContactForm';
import type { ContactFormProps } from './ContactForm';

type Props = Omit<ContactFormProps, 'layout' | 'formId' | 'hideActions' | 'onBusyChange'> & {
    open: boolean;
    onClose: () => void;
    title?: string;
    description?: string;
    submitLabel?: string;
};

/**
 * ContactForm in a right-hand drawer (quotations). The drawer is a plain overlay: the page underneath is never navigated,
 * reloaded or reset, so a quotation keeps every line, date, note and term while a customer is created or the drawer is cancelled.
 * 24px side padding on desktop (16px on phones); the footer with the actions is always visible.
 */
export default function ContactDrawer({ open, onClose, title, description, submitLabel, onCancel, ...form }: Props) {
    const { t } = useI18n();
    const formId = `contact-drawer-${useId()}`;
    const [busy, setBusy] = useState(false);
    const close = () => {
        if (busy) return;
        onCancel?.();
        onClose();
    };

    return (
        <Sheet
            open={open}
            onClose={close}
            title={title ?? t('cts.new')}
            description={description}
            widthClassName="max-w-xl"
            footer={
                <div className="flex flex-wrap justify-end gap-2 px-2 sm:px-4">
                    <Button type="button" variant="secondary" onClick={close} disabled={busy}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" form={formId} loading={busy} loadingText={t('cts.saving')}>
                        {submitLabel ?? t('cts.createAndSelect')}
                    </Button>
                </div>
            }
        >
            <div className="px-4 py-5 sm:px-6">
                <ContactForm {...form} layout="overlay" formId={formId} hideActions onBusyChange={setBusy} onCancel={close} />
            </div>
        </Sheet>
    );
}
