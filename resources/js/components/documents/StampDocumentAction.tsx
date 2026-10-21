import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    postUrl: string;
    applied: boolean;
    appliedAt: string | null;
    canStamp: boolean;
    unavailable?: boolean;
};

/**
 * "Apposer le cachet" — a separate, explicit action from the organization's
 * stamp configuration (see DocumentStampApposer). Having a configured stamp
 * never stamps a document by itself; this is the only thing that does, and
 * it is a one-way action per document in V1 (no "un-stamp").
 */
export default function StampDocumentAction({ postUrl, applied, appliedAt, canStamp, unavailable = false }: Props) {
    const { locale, t } = useI18n();
    const [confirmOpen, setConfirmOpen] = useState(false);
    const form = useForm({});
    const errors = form.errors as Record<string, string>;

    if (applied) {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-2.5 py-1 text-xs font-medium text-success">
                {t('stampAction.applied')}
                {appliedAt && <span className="font-normal text-success/80">· {new Date(appliedAt).toLocaleDateString(locale === 'ar' ? 'ar-MA' : 'fr-FR')}</span>}
            </span>
        );
    }

    if (unavailable) {
        return (
            <span className="inline-flex items-center rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning">
                {t('stampAction.unavailable')}
            </span>
        );
    }

    if (!canStamp) {
        return null;
    }

    const apply = () => {
        form.post(postUrl, { preserveScroll: true, onSuccess: () => setConfirmOpen(false) });
    };

    return (
        <>
            <Button type="button" variant="secondary" size="sm" onClick={() => setConfirmOpen(true)}>
                {t('stampAction.apply')}
            </Button>

            {confirmOpen && (
                <div className="fixed inset-0 z-40 flex items-center justify-center bg-ink/40 px-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-sm rounded-card border border-line bg-surface p-5 shadow-pop">
                        <h2 className="text-base font-semibold text-ink">{t('stampAction.confirmTitle')}</h2>
                        <p className="mt-2 text-sm text-ink-muted">{t('stampAction.confirmHelp')}</p>
                        {errors.stamp && <p className="mt-2 text-sm text-danger">{errors.stamp}</p>}
                        <div className="mt-4 flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setConfirmOpen(false)}>
                                {t('common.cancel')}
                            </Button>
                            <Button type="button" loading={form.processing} loadingText={t('stampAction.applying')} onClick={apply}>
                                {t('stampAction.apply')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
