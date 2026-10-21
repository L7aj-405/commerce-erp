import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { PasswordField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ConfirmPassword() {
    const { t } = useI18n();
    const form = useForm({ password: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/confirm-password', { onFinish: () => form.reset('password') });
    };

    return (
        <>
            <Head title={t('auth.confirmPasswordTitle')} />

            <AuthLayout
                title={t('auth.confirmPasswordTitle')}
                subtitle={t('auth.confirmPasswordHelp')}
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <PasswordField
                        label={t('auth.password')}
                        name="password"
                        autoComplete="current-password"
                        autoFocus
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                    />

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? t('auth.verifying') : t('auth.confirm')}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
