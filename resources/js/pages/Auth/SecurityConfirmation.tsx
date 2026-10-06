import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
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
            <Head title="Confirmation de sécurité" />
            <AuthLayout
                title="Confirmation de sécurité requise"
                subtitle={`Pour continuer, confirmez votre identité. La confirmation restera valable ${timeoutMinutes} minutes.`}
            >
                {status && <p className="mb-4 rounded-lg bg-success-soft px-3 py-2 text-sm text-success">{status}</p>}
                {page.props.errors?.google && <p className="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{page.props.errors.google}</p>}

                {needsAccountConfirmation && hasPassword && (
                    <form onSubmit={confirmPassword} className="space-y-4" noValidate>
                        <PasswordField
                            label="Mot de passe"
                            name="password"
                            autoComplete="current-password"
                            autoFocus
                            required
                            value={passwordForm.data.password}
                            onChange={(event) => passwordForm.setData('password', event.target.value)}
                            error={passwordForm.errors.password}
                        />
                        <Button type="submit" block size="lg" disabled={passwordForm.processing}>
                            {passwordForm.processing ? 'Vérification…' : 'Confirmer mon identité'}
                        </Button>
                    </form>
                )}

                {needsAccountConfirmation && googleConnected && (
                    <div className={hasPassword ? 'mt-4 border-t pt-4' : ''}>
                        <a
                            href="/security/confirm/google"
                            className="flex h-11 w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-ink hover:bg-slate-50"
                        >
                            Confirmer avec Google
                        </a>
                    </div>
                )}

                {needsAccountConfirmation && !hasPassword && !googleConnected && (
                    <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">
                        Aucun moyen de confirmation n’est configuré pour ce compte.
                    </p>
                )}

                {!needsAccountConfirmation && requiresTwoFactor && (
                    <form onSubmit={confirmCode} className="space-y-4" noValidate>
                        <TextField
                            label="Code d’authentification ou code de récupération"
                            name="code"
                            autoComplete="one-time-code"
                            autoFocus
                            required
                            value={codeForm.data.code}
                            onChange={(event) => codeForm.setData('code', event.target.value)}
                            error={codeForm.errors.code}
                        />
                        <p className="text-xs leading-5 text-ink-muted">
                            Un appareil de confiance ne remplace pas cette confirmation renforcée.
                        </p>
                        <Button type="submit" block size="lg" disabled={codeForm.processing}>
                            {codeForm.processing ? 'Vérification…' : 'Confirmer le code'}
                        </Button>
                    </form>
                )}
            </AuthLayout>
        </>
    );
}
