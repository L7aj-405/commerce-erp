import { Button, ButtonLink } from '@/components/ui/Button';
import ApplicationShell from '@/layouts/ApplicationShell';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Stamp = {
    id: number;
    source: 'uploaded' | 'digitized';
    uploaded_at: string;
    available: boolean;
};

type Props = {
    organization: { id: number; name: string };
    stamp: Stamp | null;
    canUpdate: boolean;
};

export default function DocumentStamp({ stamp, canUpdate }: Props) {
    const { t } = useI18n();
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [cacheBust, setCacheBust] = useState(0);

    const form = useForm<{ image: File | null }>({
        image: null,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/document-stamp', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.setData('image', null);
                setCacheBust((n) => n + 1);
            },
        });
    };

    const remove = () => {
        form.delete('/document-stamp', { preserveScroll: true, onSuccess: () => setConfirmRemove(false) });
    };

    return (
        <ApplicationShell>
            <Head title={t('stamp.title')} />
            <main className="mx-auto max-w-3xl">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">{t('stamp.title')}</h1><p className="mt-1 text-sm text-ink-muted">{t('stamp.help')}</p>
                    </div>
                    <span
                        className={`inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                            stamp?.available ? 'bg-success-soft text-success' : stamp ? 'bg-warning-soft text-warning' : 'bg-raised text-ink-muted'
                        }`}
                    >
                        {stamp?.available ? t('stamp.configured') : stamp ? t('stamp.fileUnavailable') : t('stamp.notConfigured')}
                    </span>
                </div>

                {!canUpdate && (
                    <p className="mt-4 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                        {t('stamp.readOnly')}
                    </p>
                )}

                {!stamp && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <p className="text-sm text-ink-muted">
                            {t('stamp.uploadHelp')}
                        </p>
                        <p className="mt-1 text-xs text-ink-faint">{t('stamp.formats')}</p>
                    </section>
                )}

                {stamp?.available && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <h2 className="mb-3 text-sm font-semibold text-ink">{t('stamp.preview')}</h2>
                        <div className="flex size-28 items-center justify-center overflow-hidden rounded-card border border-line bg-raised">
                            <img
                                key={cacheBust}
                                src={`/document-stamp/image?v=${cacheBust}`}
                                alt={t('stamp.imageAlt')}
                                className="max-h-full max-w-full object-contain"
                            />
                        </div>
                    </section>
                )}

                {stamp && !stamp.available && (
                    <section className="mt-8 rounded-card border border-warning/30 bg-warning-soft p-5">
                        <h2 className="text-sm font-semibold text-warning">{t('stamp.missingTitle')}</h2><p className="mt-1 text-sm text-warning">{t('stamp.missingHelp')}</p>
                    </section>
                )}

                <form onSubmit={submit} className="mt-4 space-y-4">
                    <fieldset disabled={!canUpdate} className="space-y-4">
                        <Section title={stamp?.available ? t('stamp.replace') : t('stamp.add')}>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(e) => form.setData('image', e.target.files?.[0] ?? null)}
                                className="block text-sm"
                            />
                            <p className="text-xs text-ink-faint">{t('stamp.maxSize')}</p>
                            {form.errors.image && <p className="text-xs text-danger">{form.errors.image}</p>}
                        </Section>

                        <Section title={t('stamp.presentation')}><p className="text-sm text-ink-muted">{t('stamp.presentationHelp')}</p><ButtonLink href="/document-profile/studio" variant="secondary" size="sm">{t('stamp.openStudio')}</ButtonLink>
                        </Section>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="submit" loading={form.processing} loadingText={t('common.saving')}>
                                {t('common.save')}
                            </Button>
                        </div>
                    </fieldset>
                </form>

                {stamp && canUpdate && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <h2 className="text-sm font-semibold text-ink">{t('stamp.disable')}</h2><p className="mt-1 text-xs text-ink-muted">{t('stamp.disableHelp')}</p>
                        {!confirmRemove ? (
                            <Button type="button" variant="danger" size="sm" className="mt-3" onClick={() => setConfirmRemove(true)}>
                                {t('stamp.disable')}
                            </Button>
                        ) : (
                            <div className="mt-3 flex items-center gap-2">
                                <Button type="button" variant="danger" size="sm" loading={form.processing} onClick={remove}>
                                    {t('stamp.confirmDisable')}
                                </Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmRemove(false)}>
                                    {t('common.cancel')}
                                </Button>
                            </div>
                        )}
                    </section>
                )}
            </main>
        </ApplicationShell>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <h2 className="mb-3 text-sm font-semibold text-ink">{title}</h2>
            <div className="space-y-3">{children}</div>
        </section>
    );
}
