import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { PasswordField } from '@/components/ui/form';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ConfirmPassword() {
    const form = useForm({ password: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/confirm-password', { onFinish: () => form.reset('password') });
    };

    return (
        <>
            <Head title="Confirmer le mot de passe" />

            <AuthLayout
                title="Confirmez votre mot de passe"
                subtitle="Ceci est une zone sécurisée. Merci de confirmer votre mot de passe avant de continuer."
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <PasswordField
                        label="Mot de passe"
                        name="password"
                        autoComplete="current-password"
                        autoFocus
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                    />

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? 'Vérification…' : 'Confirmer'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
