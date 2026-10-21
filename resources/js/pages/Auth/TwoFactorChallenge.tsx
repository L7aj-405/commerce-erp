import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Props = {
    trustedDeviceDurations: number[];
    trustedDeviceDefaultDays: number;
};

export default function TwoFactorChallenge({ trustedDeviceDurations, trustedDeviceDefaultDays }: Props) {
    const { t } = useI18n();
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const form = useForm({ code: '', trust_device: false, duration_days: trustedDeviceDefaultDays });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/two-factor-challenge', { onFinish: () => form.reset('code') });
    };

    return (
        <>
            <Head title={t('auth.twoFactorTitle')} />

            <AuthLayout
                title={t('auth.twoFactorTitle')}
                subtitle={
                    useRecoveryCode
                        ? t('auth.recoveryCodeHelp')
                        : t('auth.authCodeHelp')
                }
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <TextField
                        label={useRecoveryCode ? t('auth.recoveryCode') : t('auth.authCode')}
                        name="code"
                        inputMode={useRecoveryCode ? 'text' : 'numeric'}
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                        error={form.errors.code}
                    />

                    <div className="rounded-card border border-line bg-raised p-3">
                        <label className="flex cursor-pointer items-start gap-3 text-sm text-ink">
                            <input
                                type="checkbox"
                                checked={form.data.trust_device}
                                onChange={(event) => form.setData('trust_device', event.target.checked)}
                                className="mt-0.5 h-4 w-4 rounded border-line-strong accent-primary"
                            />
                            <span>
                                <span className="font-medium">{t('auth.trustDevice')}</span>
                                <span className="mt-1 block text-xs leading-5 text-ink-muted">
                                    {t('auth.trustDeviceHelp')}
                                </span>
                            </span>
                        </label>

                        {form.data.trust_device && (
                            <label className="mt-3 block text-sm text-ink">
                                {t('auth.trustDuration')}
                                <select
                                    value={form.data.duration_days}
                                    onChange={(event) => form.setData('duration_days', Number(event.target.value))}
                                    className="control-select mt-1"
                                >
                                    {trustedDeviceDurations.map((days) => (
                                        <option key={days} value={days}>{days} {t('auth.days')}</option>
                                    ))}
                                </select>
                                {form.errors.duration_days && <span className="mt-1 block text-xs text-danger">{form.errors.duration_days}</span>}
                            </label>
                        )}
                    </div>

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? t('auth.verifying') : t('auth.verify')}
                    </Button>

                    <button
                        type="button"
                        onClick={() => setUseRecoveryCode((value) => !value)}
                        className="block text-center text-sm text-ink-muted underline-offset-4 hover:underline"
                    >
                        {useRecoveryCode ? t('auth.useAuthenticator') : t('auth.useRecovery')}
                    </button>
                </form>
            </AuthLayout>
        </>
    );
}
