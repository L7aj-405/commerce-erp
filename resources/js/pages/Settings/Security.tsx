import { Button } from '@/components/ui/Button';
import { useFreshAuth } from '@/components/auth/FreshAuthProvider';
import AccountLayout from '@/layouts/AccountLayout';
import { useI18n } from '@/i18n';
import { Head, router } from '@inertiajs/react';
import { toCanvas } from 'qrcode';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import axios, { type AxiosRequestConfig, type AxiosResponse } from 'axios';

type TrustedDeviceRow = {
    id: string;
    deviceName: string | null;
    browser: string | null;
    platform: string | null;
    lastIp: string | null;
    lastUsedAt: string | null;
    expiresAt: string;
    isCurrent: boolean;
};

type Props = {
    status?: string | null;
    twoFactorEnabled: boolean;
    hasPassword: boolean;
    googleAuthConnected: boolean;
    recoveryCodesRemaining: number;
    trustedDevices: TrustedDeviceRow[];
};

/** "Mot de passe" — current/new/confirm, same fetch-based pattern as the 2FA
 * actions on this page. On success the server rotates this session and
 * signs out every other one, so we surface that explicitly. */
function PasswordSection({
    initialHasPassword,
    googleAuthConnected,
    onCredentialsChanged,
}: {
    initialHasPassword: boolean;
    googleAuthConnected: boolean;
    onCredentialsChanged: () => void;
}) {
    const { t } = useI18n();
    const { request } = useFreshAuth();
    const [open, setOpen] = useState(false);
    const [hasPassword, setHasPassword] = useState(initialHasPassword);
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [errors, setErrors] = useState<{ current_password?: string; password?: string }>({});
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);
    const [doneMessage, setDoneMessage] = useState<string | null>(null);

    const reset = () => {
        setOpen(false);
        setCurrentPassword('');
        setNewPassword('');
        setConfirmation('');
        setErrors({});
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        setDone(false);
        setDoneMessage(null);
        const isInitialSetup = !hasPassword;
        const { status, data } = await apiCall<{ message?: string; errors?: { current_password?: string[]; password?: string[] } }>(request,
            isInitialSetup ? '/account/password/initial' : '/account/password',
            isInitialSetup ? 'POST' : 'PATCH',
            isInitialSetup
                ? { password: newPassword, password_confirmation: confirmation }
                : { current_password: currentPassword, password: newPassword, password_confirmation: confirmation },
        );
        setBusy(false);
        if (status !== 200) {
            setErrors({ current_password: data.errors?.current_password?.[0], password: data.errors?.password?.[0] });
            return;
        }
        setHasPassword(true);
        onCredentialsChanged();
        setDoneMessage(data.message ?? (isInitialSetup
            ? t('security.passwordSetSuccess')
            : t('security.passwordChangedSuccess')));
        reset();
        setDone(true);
    };

    return (
        <section className="rounded-2xl border bg-white p-4 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-medium text-ink">{t('security.password')}</p>
                    <p className="mt-1 text-sm text-ink-muted">
                        {hasPassword
                            ? t('security.passwordProtects')
                            : t('security.googlePasswordHelp')}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2 text-xs">
                        <span className="rounded-full bg-muted px-2.5 py-1 font-medium text-ink-muted">
                            {t('security.google')} {googleAuthConnected ? t('security.connected') : t('security.notConnected')}
                        </span>
                        <span className="rounded-full bg-muted px-2.5 py-1 font-medium text-ink-muted">
                            {t('security.password')} {hasPassword ? t('security.configured') : t('security.notConfigured')}
                        </span>
                    </div>
                </div>
                {!open && (
                    <Button variant="secondary" onClick={() => { setOpen(true); setDone(false); }} className="w-full sm:w-auto">
                        {hasPassword ? t('security.changePassword') : t('security.setPassword')}
                    </Button>
                )}
            </div>

            {done && !open && (
                <p className="mt-3 rounded-lg bg-success-soft px-3 py-2 text-sm text-success">
                    {doneMessage}
                </p>
            )}

            {open && (
                <form onSubmit={submit} className="mt-4 space-y-3">
                    {hasPassword && (
                        <label className="block text-sm">
                            {t('security.currentPassword')}
                            <input
                                type="password"
                                autoComplete="current-password"
                                autoFocus
                                value={currentPassword}
                                onChange={(e) => setCurrentPassword(e.target.value)}
                                className="mt-1 block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
                            />
                            {errors.current_password && <span className="mt-1 block text-xs text-danger">{errors.current_password}</span>}
                        </label>
                    )}
                    <label className="block text-sm">
                        {t('security.newPassword')}
                        <input
                            type="password"
                            autoComplete="new-password"
                            autoFocus={!hasPassword}
                            value={newPassword}
                            onChange={(e) => setNewPassword(e.target.value)}
                            className="mt-1 block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
                        />
                        {errors.password && <span className="mt-1 block text-xs text-danger">{errors.password}</span>}
                    </label>
                    <label className="block text-sm">
                        {t('security.confirmPassword')}
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)}
                            className="mt-1 block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
                        />
                    </label>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Button type="submit" disabled={busy || (hasPassword && !currentPassword) || !newPassword || !confirmation} className="w-full sm:w-auto">
                            {busy ? t('common.saving') : hasPassword ? t('common.save') : t('security.settingPassword')}
                        </Button>
                        <button type="button" onClick={reset} className="py-2 text-sm text-ink-muted underline-offset-4 hover:underline">
                            {t('common.cancel')}
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}

/** Renders the otpauth:// URI as a QR code entirely client-side — the image
 * is never generated or stored server-side (it contains only the otpauth
 * URI, nothing else), and only ever shown during authenticated enrollment. */
function TotpQrCode({ otpauthUri }: { otpauthUri: string }) {
    const { t } = useI18n();
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        if (canvasRef.current) {
            toCanvas(canvasRef.current, otpauthUri, { width: 200, margin: 1 }).catch(() => {});
        }
    }, [otpauthUri]);

    return <canvas ref={canvasRef} className="rounded-lg border" aria-label={t('security.qrLabel')} />;
}

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

async function apiCall<T>(
    request: (config: AxiosRequestConfig) => Promise<AxiosResponse<unknown>>,
    url: string,
    method: string,
    body?: Record<string, unknown>,
): Promise<{ status: number; data: T }> {
    try {
        const response = await request({ url, method, data: body, headers: { 'X-XSRF-TOKEN': xsrfToken() } });
        return { status: response.status, data: response.data as T };
    } catch (error) {
        if (axios.isAxiosError(error) && error.response) {
            return { status: error.response.status, data: error.response.data as T };
        }
        throw error;
    }
}

export default function Security({ status, twoFactorEnabled, hasPassword, googleAuthConnected, recoveryCodesRemaining, trustedDevices }: Props) {
    const { locale, t } = useI18n();
    const { request, submit: submitProtected } = useFreshAuth();
    const dateLocale = locale === 'ar' ? 'ar-MA' : 'fr-FR';
    const [enabled, setEnabled] = useState(twoFactorEnabled);
    const [codesRemaining, setCodesRemaining] = useState(recoveryCodesRemaining);
    const [step, setStep] = useState<'idle' | 'enrolling' | 'recovery'>('idle');
    const [secret, setSecret] = useState('');
    const [otpauthUri, setOtpauthUri] = useState('');
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [showDisableForm, setShowDisableForm] = useState(false);
    const [busy, setBusy] = useState(false);
    const [revokingDevice, setRevokingDevice] = useState<string | 'all' | null>(null);
    const [trustedDeviceRows, setTrustedDeviceRows] = useState(trustedDevices);

    useEffect(() => setTrustedDeviceRows(trustedDevices), [trustedDevices]);

    const revokeDevice = (deviceId: string) => {
        setRevokingDevice(deviceId);
        router.delete(`/security/trusted-devices/${deviceId}`, {
            preserveScroll: true,
            onFinish: () => setRevokingDevice(null),
        });
    };

    const revokeAllDevices = () => {
        setRevokingDevice('all');
        void submitProtected({ method: 'delete', url: '/security/trusted-devices', preserveScroll: true, onFinish: () => setRevokingDevice(null) });
    };

    const startEnrollment = async () => {
        setError(null);
        setBusy(true);
        const { status, data } = await apiCall<{ secret: string; otpauth_uri: string }>(request, '/two-factor-authentication', 'POST');
        setBusy(false);
        if (status !== 200) {
            setError(t('security.startError'));
            return;
        }
        setSecret(data.secret);
        setOtpauthUri(data.otpauth_uri);
        setTrustedDeviceRows([]);
        setStep('enrolling');
    };

    const confirmEnrollment = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setBusy(true);
        const { status, data } = await apiCall<{ recovery_codes?: string[]; errors?: { code?: string[] } }>(request,
            '/two-factor-authentication/confirm',
            'POST',
            { code },
        );
        setBusy(false);
        if (status !== 200 || !data.recovery_codes) {
            setError(data.errors?.code?.[0] ?? t('security.invalidCode'));
            return;
        }
        setRecoveryCodes(data.recovery_codes);
        setCodesRemaining(data.recovery_codes.length);
        setEnabled(true);
        setStep('recovery');
        setCode('');
    };

    const disable = async () => {
        setError(null);
        setBusy(true);
        const { status } = await apiCall<Record<string, never>>(request, '/two-factor-authentication', 'DELETE');
        setBusy(false);
        if (status >= 400) {
            if (status !== 409) setError(t('security.disableError'));
            return;
        }
        setEnabled(false);
        setTrustedDeviceRows([]);
        setCodesRemaining(0);
        setShowDisableForm(false);
    };

    const regenerateCodes = async () => {
        setError(null);
        setBusy(true);
        const { status, data } = await apiCall<{ recovery_codes?: string[] }>(request, '/two-factor-recovery-codes', 'POST');
        setBusy(false);
        if (status !== 200 || !data.recovery_codes) {
            if (status !== 409) setError(t('security.regenerateError'));
            return;
        }
        setRecoveryCodes(data.recovery_codes);
        setCodesRemaining(data.recovery_codes.length);
        setStep('recovery');
    };

    return (
        <AccountLayout>
            <Head title={t('security.title')} />
            <h1 className="text-xl font-semibold tracking-tight text-ink sm:text-2xl">{t('security.title')}</h1>
            <p className="mt-1 text-sm text-ink-muted">{t('security.description')}</p>

            {status && (
                <p role="alert" className="mt-4 rounded-lg border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-sm font-medium text-warning">
                    {status}
                </p>
            )}

            <div className="mt-6 space-y-6">
                <PasswordSection
                    initialHasPassword={hasPassword}
                    googleAuthConnected={googleAuthConnected}
                    onCredentialsChanged={() => setTrustedDeviceRows([])}
                />

                {error && <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{error}</p>}

                <section className="rounded-2xl border bg-white p-4 sm:p-6">
                    {step === 'idle' && (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-medium text-ink">
                                        {enabled ? t('security.twoFactorEnabled') : t('security.twoFactorDisabled')}
                                    </p>
                                    {enabled && (
                                        <p className="mt-1 text-sm text-ink-muted">
                                            {codesRemaining} {t('security.recoveryCodesRemaining')}.
                                        </p>
                                    )}
                                </div>
                                {!enabled && (
                                    <Button onClick={startEnrollment} disabled={busy} className="w-full sm:w-auto">
                                        {t('security.enable')}
                                    </Button>
                                )}
                            </div>

                            {enabled && !showDisableForm && (
                                <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <Button variant="secondary" onClick={regenerateCodes} disabled={busy}>
                                        {t('security.regenerateCodes')}
                                    </Button>
                                    <Button variant="secondary" onClick={() => setShowDisableForm(true)}>
                                        {t('security.disable')}
                                    </Button>
                                </div>
                            )}

                            {enabled && showDisableForm && (
                                <div className="mt-4 rounded-lg border border-warning/30 bg-warning-soft p-3">
                                    <p className="text-sm text-warning">{t('security.strongConfirmation')}</p>
                                    <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <Button type="button" onClick={disable} disabled={busy}>
                                        {t('security.confirmDisable')}
                                    </Button>
                                    <button type="button" onClick={() => setShowDisableForm(false)} className="py-2 text-sm text-ink-muted underline-offset-4 hover:underline">
                                        {t('common.cancel')}
                                    </button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}

                    {step === 'enrolling' && (
                        <form onSubmit={confirmEnrollment} className="space-y-4">
                            <div>
                                <p className="text-sm font-medium text-ink">{t('security.scanQr')}</p>
                                <div className="mt-2">
                                    <TotpQrCode otpauthUri={otpauthUri} />
                                </div>
                                <p className="mt-3 text-xs text-ink-muted">{t('security.manualKey')}</p>
                                <p className="mt-1 break-all rounded-lg bg-slate-50 p-3 font-mono text-sm">{secret}</p>
                            </div>
                            <div>
                                <label className="text-sm font-medium text-ink" htmlFor="totp-code">
                                    {t('security.enterCode')}
                                </label>
                                <input
                                    id="totp-code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                    className="mt-1 block h-11 w-full max-w-40 rounded-lg border border-slate-300 px-3 text-lg tracking-widest"
                                    maxLength={6}
                                    autoFocus
                                />
                            </div>
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <Button type="submit" disabled={busy || code.length !== 6} className="w-full sm:w-auto">
                                    {t('security.confirm')}
                                </Button>
                                <button type="button" onClick={() => setStep('idle')} className="py-2 text-sm text-ink-muted underline-offset-4 hover:underline">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    )}

                    {step === 'recovery' && (
                        <div>
                            <p className="text-sm font-medium text-ink">{t('security.recoveryCodes')}</p>
                            <p className="mt-1 text-sm text-ink-muted">{t('security.recoveryCodesHelp')}</p>
                            <ul className="mt-3 grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-sm min-[420px]:grid-cols-2">
                                {recoveryCodes.map((c) => (
                                    <li key={c} className="break-all">{c}</li>
                                ))}
                            </ul>
                            <Button className="mt-4 w-full sm:w-auto" onClick={() => setStep('idle')}>
                                {t('security.codesSaved')}
                            </Button>
                        </div>
                    )}
                </section>

                {enabled && (
                    <section className="rounded-2xl border bg-white p-4 sm:p-6">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0">
                                <p className="font-medium text-ink">{t('security.trustedDevices')}</p>
                                <p className="mt-1 text-sm text-ink-muted">{t('security.trustedDevicesHelp')}</p>
                            </div>
                            {trustedDeviceRows.length > 0 && (
                                <Button
                                    variant="secondary"
                                    onClick={revokeAllDevices}
                                    disabled={revokingDevice !== null}
                                    className="w-full sm:w-auto"
                                >
                                    {revokingDevice === 'all' ? t('security.revoking') : t('security.revokeAll')}
                                </Button>
                            )}
                        </div>

                        {trustedDeviceRows.length === 0 ? (
                            <p className="mt-4 rounded-lg bg-slate-50 px-3 py-3 text-sm text-ink-muted">
                                {t('security.noTrustedDevices')}
                            </p>
                        ) : (
                            <ul className="mt-4 divide-y">
                                {trustedDeviceRows.map((device) => (
                                    <li key={device.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 py-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-ink">
                                                {device.deviceName ?? ([device.browser, device.platform].filter(Boolean).join(` ${t('security.onPlatform')} `) || t('security.unknownDevice'))}
                                                {device.isCurrent && (
                                                    <span className="ms-2 rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-success">
                                                        {t('security.thisDevice')}
                                                    </span>
                                                )}
                                            </p>
                                            <p className="mt-0.5 text-xs text-ink-muted">
                                                {t('security.expiresOn')} {new Date(device.expiresAt).toLocaleString(dateLocale)}
                                                {device.lastUsedAt ? ` · ${t('security.usedOn')} ${new Date(device.lastUsedAt).toLocaleString(dateLocale)}` : ''}
                                                {device.lastIp ? ` · ${device.lastIp}` : ''}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => revokeDevice(device.id)}
                                            disabled={revokingDevice !== null}
                                            className="shrink-0 rounded-field px-2 py-1.5 text-sm text-danger underline-offset-4 hover:underline disabled:opacity-50"
                                        >
                                            {revokingDevice === device.id ? t('security.revoking') : t('security.revoke')}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

            </div>
        </AccountLayout>
    );
}
