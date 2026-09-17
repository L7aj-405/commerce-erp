import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

export default function TwoFactorChallenge() {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const form = useForm({ code: '' });

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
