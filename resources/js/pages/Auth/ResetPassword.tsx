import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = { token: string; email: string };

export default function ResetPassword({ token, email }: Props) {
    const { t } = useI18n();
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    return (
        <>
            <Head title={t('auth.resetTitle')} />

            <AuthLayout title={t('auth.choosePassword')} subtitle={t('auth.oneTimeLink')}>
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <TextField
                        label={t('auth.email')}
                        type="email"
                        name="email"
                        autoComplete="email"
                        required
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />

                    <PasswordField
                        label={t('auth.newPassword')}
                        name="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        placeholder={t('auth.passwordMin10')}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                        hint={t('auth.passwordHint')}
                    />

                    <PasswordField
                        label={t('auth.passwordConfirmation')}
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        placeholder={t('auth.repeatPassword')}
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        error={form.errors.password_confirmation}
                    />

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? t('auth.resetting') : t('auth.resetTitle')}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
