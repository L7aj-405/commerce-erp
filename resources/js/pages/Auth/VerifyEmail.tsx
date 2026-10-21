import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = { status?: string | null };

export default function VerifyEmail({ status }: Props) {
    const { t } = useI18n();
    const form = useForm({});
    const logout = useForm({});

    const resend = (event: FormEvent) => {
        event.preventDefault();
        form.post('/email/verification-notification');
    };

    return (
        <>
            <Head title={t('auth.verifyEmailTitle')} />

            <AuthLayout title={t('auth.verifyEmailTitle')} subtitle={t('auth.verifyEmailSent')}>
                <div className="space-y-4 text-sm text-ink-muted">
                    <p>
                        {t('auth.verifyEmailHelp')}
                    </p>

                    {status === 'verification-link-sent' && (
                        <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-medium text-success">
                            {t('auth.verifyEmailResent')}
                        </p>
                    )}

                    <form onSubmit={resend}>
                        <Button type="submit" block disabled={form.processing}>
                            {form.processing ? t('auth.sending') : t('auth.resendVerification')}
                        </Button>
                    </form>

                    <form onSubmit={(e) => { e.preventDefault(); logout.post('/logout'); }}>
                        <button type="submit" className="text-sm font-medium text-ink underline-offset-4 hover:underline">
                            {t('auth.signOut')}
                        </button>
                    </form>
                </div>
            </AuthLayout>
        </>
    );
}
