import { Button } from '@/components/ui/Button';
import ApplicationShell from '@/layouts/ApplicationShell';
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
    twoFactorEnabled: boolean;
    recoveryCodesRemaining: number;
    sessions: SessionRow[];
};

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

export default function Security({ twoFactorEnabled, recoveryCodesRemaining, sessions }: Props) {
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
        <ApplicationShell>
            <Head title="Sécurité du compte" />
            <main className="mx-auto max-w-2xl">
                <h1 className="text-2xl font-semibold tracking-tight text-ink">Sécurité du compte</h1>
                <p className="mt-1 text-sm text-ink-muted">
                    Double authentification (TOTP) — compatible Google Authenticator, Microsoft Authenticator, 1Password
                    et toute application compatible Authy.
                </p>

                {error && <p className="mt-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{error}</p>}

                <section className="mt-6 rounded-2xl border bg-white p-6">
                    {step === 'idle' && (
                        <>
                            <div className="flex items-center justify-between gap-3">
                                <div>
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
                                    <Button onClick={startEnrollment} disabled={busy}>
                                        Activer
                                    </Button>
                                )}
                            </div>

                            {enabled && !showDisableForm && (
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <Button variant="secondary" onClick={regenerateCodes} disabled={busy}>
                                        Régénérer les codes de récupération
                                    </Button>
                                    <Button variant="secondary" onClick={() => setShowDisableForm(true)}>
                                        Désactiver
                                    </Button>
                                </div>
                            )}

                            {enabled && showDisableForm && (
                                <form onSubmit={disable} className="mt-4 flex flex-wrap items-end gap-2">
                                    <label className="text-sm">
                                        Confirmez votre mot de passe
                                        <input
                                            type="password"
                                            value={password}
                                            onChange={(e) => setPassword(e.target.value)}
                                            className="mt-1 block h-10 rounded-lg border border-slate-300 px-3 text-sm"
                                            autoFocus
                                        />
                                    </label>
                                    <Button type="submit" disabled={busy || !password}>
                                        Confirmer la désactivation
                                    </Button>
                                    <button type="button" onClick={() => setShowDisableForm(false)} className="text-sm text-ink-muted underline-offset-4 hover:underline">
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
                                    className="mt-1 block h-10 w-40 rounded-lg border border-slate-300 px-3 text-sm tracking-widest"
                                    maxLength={6}
                                    autoFocus
                                />
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" disabled={busy || code.length !== 6}>
                                    Confirmer
                                </Button>
                                <button type="button" onClick={() => setStep('idle')} className="text-sm text-ink-muted underline-offset-4 hover:underline">
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
                            <ul className="mt-3 grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-sm">
                                {recoveryCodes.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                            <Button className="mt-4" onClick={() => setStep('idle')}>
                                J’ai noté mes codes
                            </Button>
                        </div>
                    )}
                </section>

                <section className="mt-6 rounded-2xl border bg-white p-6">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="font-medium text-ink">Sessions actives</p>
                            <p className="mt-1 text-sm text-ink-muted">Les appareils actuellement connectés à votre compte.</p>
                        </div>
                        {sessions.filter((s) => !s.isCurrent).length > 0 && (
                            <Button
                                variant="secondary"
                                onClick={() => router.delete('/security/sessions', { preserveScroll: true })}
                            >
                                Déconnecter les autres sessions
                            </Button>
                        )}
                    </div>

                    <ul className="mt-4 divide-y">
                        {sessions.map((session) => (
                            <li key={session.token} className="flex items-center justify-between gap-3 py-3">
                                <div className="min-w-0">
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
                                        className="shrink-0 text-sm text-danger underline-offset-4 hover:underline"
                                    >
                                        Révoquer
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </main>
        </ApplicationShell>
    );
}
