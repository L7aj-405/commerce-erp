import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import AccountLayout from '@/layouts/AccountLayout';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    user: { name: string; email: string };
    emailVerified: boolean;
    status?: string | null;
};

export default function Profile({ user, emailVerified, status }: Props) {
    const { t } = useI18n();
    const form = useForm({ name: user.name, email: user.email });
    const resendForm = useForm({});
    const emailChanged = form.data.email !== user.email;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch('/account/profile', {
            preserveScroll: true,
        });
    };

    const resendVerification = () => {
        resendForm.post('/email/verification-notification', { preserveScroll: true, preserveState: true });
    };

    return (
        <AccountLayout>
            <Head title={t('profile.title')} />
            <h1 className="text-xl font-semibold tracking-tight text-ink sm:text-2xl">{t('profile.title')}</h1>
            <p className="mt-1 text-sm text-ink-muted">{t('profile.help')}</p>

            {status === 'verification-link-sent' && (
                <p className="mt-4 rounded-field bg-success-soft px-3.5 py-2.5 text-[13px] font-medium text-success">
                    {t('profile.verificationSent')}
                </p>
            )}

            <section className="mt-6 rounded-card border border-line bg-surface p-4 sm:p-6">
                <form onSubmit={submit} className="space-y-4">
                    <TextField
                        label={t('profile.name')}
                        name="name"
                        autoComplete="name"
                        required
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                    />

                    <div>
                        <TextField
                            label={t('profile.email')}
                            type="email"
                            name="email"
                            autoComplete="email"
                            required
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            error={form.errors.email}
                        />
                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                            {emailVerified ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 text-[12px] font-medium text-success">
                                    ✓ {t('profile.emailVerified')}
                                </span>
                            ) : (
                                <>
                                    <span className="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-1 text-[12px] font-medium text-warning">
                                        {t('profile.verificationRequired')}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={resendVerification}
                                        disabled={resendForm.processing}
                                        className="text-[12px] font-medium text-ink-muted underline-offset-4 hover:underline disabled:opacity-50"
                                    >
                                        {resendForm.processing ? t('profile.sending') : t('profile.resendVerification')}
                                    </button>
                                </>
                            )}
                        </div>
                    </div>

                    {emailChanged && (
                        <div className="rounded-field border border-line bg-raised p-3.5">
                            <p className="text-[13px] text-ink-muted">
                                {t('profile.emailChangeWarning')}
                            </p>
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" loading={form.processing} loadingText={t('common.saving')} className="w-full sm:w-auto">
                            {t('common.save')}
                        </Button>
                    </div>
                </form>
            </section>
        </AccountLayout>
    );
}
