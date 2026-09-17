import { Button } from '@/components/ui/Button';
import AccountLayout from '@/layouts/AccountLayout';
import { Head, router } from '@inertiajs/react';
import { toCanvas } from 'qrcode';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';

type SessionRow = {
    token: string;
    isCurrent: boolean;
    ipAddress: string | null;
    userAgent: string | null;
    lastActiveAt: string;
};

type Props = {
    status?: string | null;
    twoFactorEnabled: boolean;
    recoveryCodesRemaining: number;
    sessions: SessionRow[];
};

/** "Mot de passe" — current/new/confirm, same fetch-based pattern as the 2FA
 * actions on this page. On success the server rotates this session and
 * signs out every other one, so we surface that explicitly. */
function PasswordSection() {
    const [open, setOpen] = useState(false);
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [errors, setErrors] = useState<{ current_password?: string; password?: string }>({});
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);

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
        const { status, data } = await apiCall<{ message?: string; errors?: { current_password?: string[]; password?: string[] } }>(
            '/account/password',
            'PATCH',
            { current_password: currentPassword, password: newPassword, password_confirmation: confirmation },
        );
        setBusy(false);
        if (status !== 200) {
            setErrors({ current_password: data.errors?.current_password?.[0], password: data.errors?.password?.[0] });
            return;
        }
        reset();
        setDone(true);
    };

    return (
        <section className="rounded-2xl border bg-white p-4 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-medium text-ink">Mot de passe</p>
                    <p className="mt-1 text-sm text-ink-muted">Votre mot de passe protège l’accès à votre compte.</p>
                </div>
                {!open && (
                    <Button variant="secondary" onClick={() => { setOpen(true); setDone(false); }} className="w-full sm:w-auto">
                        Modifier le mot de passe
                    </Button>
                )}
            </div>

            {done && !open && (
                <p className="mt-3 rounded-lg bg-success-soft px-3 py-2 text-sm text-success">
                    Mot de passe modifié. Vos autres sessions ont été déconnectées.
                </p>
            )}

            {open && (
                <form onSubmit={submit} className="mt-4 space-y-3">
                    <label className="block text-sm">
                        Mot de passe actuel
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
                    <label className="block text-sm">
                        Nouveau mot de passe
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={newPassword}
                            onChange={(e) => setNewPassword(e.target.value)}
                            className="mt-1 block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
                        />
                        {errors.password && <span className="mt-1 block text-xs text-danger">{errors.password}</span>}
                    </label>
                    <label className="block text-sm">
                        Confirmer le nouveau mot de passe
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)}
                            className="mt-1 block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm"
                        />
                    </label>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Button type="submit" disabled={busy || !currentPassword || !newPassword || !confirmation} className="w-full sm:w-auto">
                            {busy ? 'Enregistrement…' : 'Enregistrer'}
                        </Button>
                        <button type="button" onClick={reset} className="py-2 text-sm text-ink-muted underline-offset-4 hover:underline">
                            Annuler
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
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        if (canvasRef.current) {
            toCanvas(canvasRef.current, otpauthUri, { width: 200, margin: 1 }).catch(() => {});
        }
    }, [otpauthUri]);

    return <canvas ref={canvasRef} className="rounded-lg border" aria-label="QR code de configuration de la double authentification" />;
}

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

async function apiCall<T>(url: string, method: string, body?: Record<string, unknown>): Promise<{ status: number; data: T }> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });

    return { status: response.status, data: (await response.json().catch(() => ({}))) as T };
}

export default function Security({ status, twoFactorEnabled, recoveryCodesRemaining, sessions }: Props) {
    const [enabled, setEnabled] = useState(twoFactorEnabled);
    const [codesRemaining, setCodesRemaining] = useState(recoveryCodesRemaining);
    const [step, setStep] = useState<'idle' | 'enrolling' | 'recovery'>('idle');
    const [secret, setSecret] = useState('');
    const [otpauthUri, setOtpauthUri] = useState('');
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [password, setPassword] = useState('');
    const [showDisableForm, setShowDisableForm] = useState(false);
    const [busy, setBusy] = useState(false);

    const startEnrollment = async () => {
        setError(null);
        setBusy(true);
        const { status, data } = await apiCall<{ secret: string; otpauth_uri: string }>('/two-factor-authentication', 'POST');
        setBusy(false);
        if (status !== 200) {
            setError('Impossible de démarrer la configuration.');
            return;
        }
        setSecret(data.secret);
        setOtpauthUri(data.otpauth_uri);
        setStep('enrolling');
    };

    const confirmEnrollment = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setBusy(true);
        const { status, data } = await apiCall<{ recovery_codes?: string[]; errors?: { code?: string[] } }>(
            '/two-factor-authentication/confirm',
            'POST',
            { code },
        );
        setBusy(false);
        if (status !== 200 || !data.recovery_codes) {
            setError(data.errors?.code?.[0] ?? 'Code invalide.');
            return;
        }
        setRecoveryCodes(data.recovery_codes);
        setCodesRemaining(data.recovery_codes.length);
        setEnabled(true);
        setStep('recovery');
        setCode('');
    };

    const disable = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setBusy(true);
        const { status, data } = await apiCall<{ errors?: { password?: string[] } }>('/two-factor-authentication', 'DELETE', { password });
        setBusy(false);
        if (status >= 400) {
            setError(data.errors?.password?.[0] ?? 'Mot de passe incorrect.');
            return;
        }
        setEnabled(false);
        setCodesRemaining(0);
        setShowDisableForm(false);
        setPassword('');
    };

    const regenerateCodes = async () => {
        const confirmPassword = window.prompt('Confirmez votre mot de passe pour régénérer vos codes de récupération :');
        if (!confirmPassword) return;
        setError(null);
        setBusy(true);
        const { status, data } = await apiCall<{ recovery_codes?: string[]; errors?: { password?: string[] } }>(
            '/two-factor-recovery-codes',
            'POST',
            { password: confirmPassword },
        );
        setBusy(false);
        if (status !== 200 || !data.recovery_codes) {
            setError(data.errors?.password?.[0] ?? 'Échec de la régénération.');
            return;
        }
        setRecoveryCodes(data.recovery_codes);
        setCodesRemaining(data.recovery_codes.length);
        setStep('recovery');
    };

    return (
        <AccountLayout>
            <Head title="Sécurité du compte" />
            <h1 className="text-xl font-semibold tracking-tight text-ink sm:text-2xl">Sécurité du compte</h1>
            <p className="mt-1 text-sm text-ink-muted">
                Double authentification (TOTP) — compatible Google Authenticator, Microsoft Authenticator, 1Password
                et toute application compatible Authy.
            </p>

            {status && (
                <p role="alert" className="mt-4 rounded-lg border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-sm font-medium text-warning">
                    {status}
                </p>
            )}

            <div className="mt-6 space-y-6">
                <PasswordSection />

                {error && <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{error}</p>}

                <section className="rounded-2xl border bg-white p-4 sm:p-6">
                    {step === 'idle' && (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-medium text-ink">
                                        {enabled ? 'Double authentification activée' : 'Double authentification désactivée'}
                                    </p>
                                    {enabled && (
                                        <p className="mt-1 text-sm text-ink-muted">
                                            {codesRemaining} code{codesRemaining === 1 ? '' : 's'} de récupération restant
                                            {codesRemaining === 1 ? '' : 's'}.
                                        </p>
                                    )}
                                </div>
                                {!enabled && (
                                    <Button onClick={startEnrollment} disabled={busy} className="w-full sm:w-auto">
                                        Activer
                                    </Button>
                                )}
                            </div>

                            {enabled && !showDisableForm && (
                                <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <Button variant="secondary" onClick={regenerateCodes} disabled={busy}>
                                        Régénérer les codes de récupération
                                    </Button>
                                    <Button variant="secondary" onClick={() => setShowDisableForm(true)}>
                                        Désactiver
                                    </Button>
                                </div>
                            )}

                            {enabled && showDisableForm && (
                                <form onSubmit={disable} className="mt-4 flex flex-col items-stretch gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                                    <label className="text-sm">
                                        Confirmez votre mot de passe
                                        <input
                                            type="password"
                                            value={password}
                                            onChange={(e) => setPassword(e.target.value)}
                                            className="mt-1 block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm sm:w-auto"
                                            autoFocus
                                        />
                                    </label>
                                    <Button type="submit" disabled={busy || !password}>
                                        Confirmer la désactivation
                                    </Button>
                                    <button type="button" onClick={() => setShowDisableForm(false)} className="py-2 text-sm text-ink-muted underline-offset-4 hover:underline">
                                        Annuler
                                    </button>
                                </form>
                            )}
                        </>
                    )}

                    {step === 'enrolling' && (
                        <form onSubmit={confirmEnrollment} className="space-y-4">
                            <div>
                                <p className="text-sm font-medium text-ink">1. Scannez ce code avec votre application d’authentification</p>
                                <div className="mt-2">
                                    <TotpQrCode otpauthUri={otpauthUri} />
                                </div>
                                <p className="mt-3 text-xs text-ink-muted">Vous ne pouvez pas scanner ? Entrez cette clé manuellement :</p>
                                <p className="mt-1 break-all rounded-lg bg-slate-50 p-3 font-mono text-sm">{secret}</p>
                            </div>
                            <div>
                                <label className="text-sm font-medium text-ink" htmlFor="totp-code">
                                    2. Entrez le code à 6 chiffres affiché par l’application
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
                                    Confirmer
                                </Button>
                                <button type="button" onClick={() => setStep('idle')} className="py-2 text-sm text-ink-muted underline-offset-4 hover:underline">
                                    Annuler
                                </button>
                            </div>
                        </form>
                    )}

                    {step === 'recovery' && (
                        <div>
                            <p className="text-sm font-medium text-ink">Codes de récupération</p>
                            <p className="mt-1 text-sm text-ink-muted">
                                Notez ces codes dans un endroit sûr. Chacun ne peut être utilisé qu’une seule fois et ils
                                ne seront plus jamais affichés.
                            </p>
                            <ul className="mt-3 grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-sm min-[420px]:grid-cols-2">
                                {recoveryCodes.map((c) => (
                                    <li key={c} className="break-all">{c}</li>
                                ))}
                            </ul>
                            <Button className="mt-4 w-full sm:w-auto" onClick={() => setStep('idle')}>
                                J’ai noté mes codes
                            </Button>
                        </div>
                    )}
                </section>

                <section className="rounded-2xl border bg-white p-4 sm:p-6">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <p className="font-medium text-ink">Sessions actives</p>
                            <p className="mt-1 text-sm text-ink-muted">Les appareils actuellement connectés à votre compte.</p>
                        </div>
                        {sessions.filter((s) => !s.isCurrent).length > 0 && (
                            <Button
                                variant="secondary"
                                onClick={() => router.delete('/security/sessions', { preserveScroll: true })}
                                className="w-full sm:w-auto"
                            >
                                Déconnecter les autres sessions
                            </Button>
                        )}
                    </div>

                    <ul className="mt-4 divide-y">
                        {sessions.map((session) => (
                            <li key={session.token} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-ink">
                                        {session.userAgent ?? 'Appareil inconnu'}
                                        {session.isCurrent && (
                                            <span className="ml-2 rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-success">
                                                Cet appareil
                                            </span>
                                        )}
                                    </p>
                                    <p className="mt-0.5 text-xs text-ink-muted">
                                        {session.ipAddress ?? 'IP inconnue'} · dernière activité{' '}
                                        {new Date(session.lastActiveAt).toLocaleString('fr-FR')}
                                    </p>
                                </div>
                                {!session.isCurrent && (
                                    <button
                                        type="button"
                                        onClick={() => router.delete(`/security/sessions/${session.token}`, { preserveScroll: true })}
                                        className="shrink-0 rounded-field px-2 py-1.5 text-sm text-danger underline-offset-4 hover:underline"
                                    >
                                        Révoquer
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </AccountLayout>
    );
}
