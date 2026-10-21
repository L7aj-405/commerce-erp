import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = { status?: string | null };

export default function ForgotPassword({ status }: Props) {
    const { t } = useI18n();
    const form = useForm({ email: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/forgot-password');
    }

    return (
        <>
            <Head title={t('auth.forgotTitle')} />

            <AuthLayout
                title={t('auth.forgotPassword')}
                subtitle={t('auth.forgotSubtitle')}
                footer={
                    <Link href="/login" className="font-medium text-ink underline-offset-4 hover:underline">
                        {t('auth.backToLogin')}
                    </Link>
                }
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    {status && <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-medium text-success">{status}</p>}

                    <TextField
                        label={t('auth.email')}
                        type="email"
                        name="email"
                        autoComplete="email"
                        autoFocus
                        required
                        placeholder={t('auth.emailPlaceholder')}
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? t('auth.sending') : t('auth.sendResetLink')}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
