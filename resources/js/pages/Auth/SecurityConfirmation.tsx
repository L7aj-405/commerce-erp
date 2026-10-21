import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    hasPassword: boolean;
    googleConnected: boolean;
    accountConfirmed: boolean;
    requiresTwoFactor: boolean;
    timeoutMinutes: number;
    status?: string | null;
};

export default function SecurityConfirmation({
    hasPassword,
    googleConnected,
    accountConfirmed,
    requiresTwoFactor,
    timeoutMinutes,
    status,
}: Props) {
    const { t } = useI18n();
    const passwordForm = useForm({ password: '' });
    const codeForm = useForm({ code: '' });
    const page = usePage<{ errors?: { google?: string } }>();

    const confirmPassword = (event: FormEvent) => {
        event.preventDefault();
        passwordForm.post('/security/confirm/password', { onFinish: () => passwordForm.reset('password') });
    };

    const confirmCode = (event: FormEvent) => {
        event.preventDefault();
        codeForm.post('/security/confirm/two-factor', { onFinish: () => codeForm.reset('code') });
    };

    const needsAccountConfirmation = !accountConfirmed;

    return (
        <>
            <Head title={t('auth.securityConfirmation')} />
            <AuthLayout
                title={t('auth.securityRequired')}
                subtitle={`${t('auth.securitySubtitle')} ${timeoutMinutes}`}
            >
                {status && <p className="mb-4 rounded-lg bg-success-soft px-3 py-2 text-sm text-success">{status}</p>}
                {page.props.errors?.google && <p className="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{page.props.errors.google}</p>}

                {needsAccountConfirmation && hasPassword && (
                    <form onSubmit={confirmPassword} className="space-y-4" noValidate>
                        <PasswordField
                            label={t('auth.password')}
                            name="password"
                            autoComplete="current-password"
                            autoFocus
                            required
                            value={passwordForm.data.password}
                            onChange={(event) => passwordForm.setData('password', event.target.value)}
                            error={passwordForm.errors.password}
                        />
                        <Button type="submit" block size="lg" disabled={passwordForm.processing}>
                            {passwordForm.processing ? t('auth.verifying') : t('auth.confirmIdentity')}
                        </Button>
                    </form>
                )}

                {needsAccountConfirmation && googleConnected && (
                    <div className={hasPassword ? 'mt-4 border-t pt-4' : ''}>
                        <a
                            href="/security/confirm/google"
                            className="flex h-11 w-full items-center justify-center rounded-field border border-line-strong bg-surface px-4 text-sm font-medium text-ink hover:bg-raised"
                        >
                            {t('auth.confirmGoogle')}
                        </a>
                    </div>
                )}

                {needsAccountConfirmation && !hasPassword && !googleConnected && (
                    <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">
                        {t('auth.noConfirmationMethod')}
                    </p>
                )}

                {!needsAccountConfirmation && requiresTwoFactor && (
                    <form onSubmit={confirmCode} className="space-y-4" noValidate>
                        <TextField
                            label={t('auth.authOrRecoveryCode')}
                            name="code"
                            autoComplete="one-time-code"
                            autoFocus
                            required
                            value={codeForm.data.code}
                            onChange={(event) => codeForm.setData('code', event.target.value)}
                            error={codeForm.errors.code}
                        />
                        <p className="text-xs leading-5 text-ink-muted">
                            {t('auth.trustedDeviceNotice')}
                        </p>
                        <Button type="submit" block size="lg" disabled={codeForm.processing}>
                            {codeForm.processing ? t('auth.verifying') : t('auth.confirmCode')}
                        </Button>
                    </form>
                )}
            </AuthLayout>
        </>
    );
}
