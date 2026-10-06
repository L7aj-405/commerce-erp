import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type Props = {
    trustedDeviceDurations: number[];
    trustedDeviceDefaultDays: number;
};

export default function TwoFactorChallenge({ trustedDeviceDurations, trustedDeviceDefaultDays }: Props) {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const form = useForm({ code: '', trust_device: false, duration_days: trustedDeviceDefaultDays });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/two-factor-challenge', { onFinish: () => form.reset('code') });
    };

    return (
        <>
            <Head title="Vérification en deux étapes" />

            <AuthLayout
                title="Vérification en deux étapes"
                subtitle={
                    useRecoveryCode
                        ? 'Entrez l’un de vos codes de récupération.'
                        : 'Entrez le code affiché par votre application d’authentification.'
                }
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <TextField
                        label={useRecoveryCode ? 'Code de récupération' : 'Code à 6 chiffres'}
                        name="code"
                        inputMode={useRecoveryCode ? 'text' : 'numeric'}
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                        error={form.errors.code}
                    />

                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <label className="flex cursor-pointer items-start gap-3 text-sm text-ink">
                            <input
                                type="checkbox"
                                checked={form.data.trust_device}
                                onChange={(event) => form.setData('trust_device', event.target.checked)}
                                className="mt-0.5 h-4 w-4 rounded border-slate-300"
                            />
                            <span>
                                <span className="font-medium">Faire confiance à cet appareil</span>
                                <span className="mt-1 block text-xs leading-5 text-ink-muted">
                                    Cet appareil pourra ignorer cette seconde étape pendant la durée choisie. Votre mot de passe restera requis à chaque nouvelle connexion.
                                </span>
                            </span>
                        </label>

                        {form.data.trust_device && (
                            <label className="mt-3 block text-sm text-ink">
                                Durée de confiance
                                <select
                                    value={form.data.duration_days}
                                    onChange={(event) => form.setData('duration_days', Number(event.target.value))}
                                    className="mt-1 block h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm"
                                >
                                    {trustedDeviceDurations.map((days) => (
                                        <option key={days} value={days}>{days} jours</option>
                                    ))}
                                </select>
                                {form.errors.duration_days && <span className="mt-1 block text-xs text-danger">{form.errors.duration_days}</span>}
                            </label>
                        )}
                    </div>

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? 'Vérification…' : 'Vérifier'}
                    </Button>

                    <button
                        type="button"
                        onClick={() => setUseRecoveryCode((value) => !value)}
                        className="block text-center text-sm text-ink-muted underline-offset-4 hover:underline"
                    >
                        {useRecoveryCode ? 'Utiliser mon application d’authentification' : 'Utiliser un code de récupération'}
                    </button>
                </form>
            </AuthLayout>
        </>
    );
}
