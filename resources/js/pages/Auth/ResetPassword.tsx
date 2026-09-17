import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = { token: string; email: string };

export default function ResetPassword({ token, email }: Props) {
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    return (
        <>
            <Head title="Réinitialiser le mot de passe" />

            <AuthLayout title="Choisissez un nouveau mot de passe" subtitle="Ce lien n’est valable qu’une seule fois.">
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <TextField
                        label="Adresse email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        required
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />

                    <PasswordField
                        label="Nouveau mot de passe"
                        name="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        placeholder="10 caractères minimum"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                        hint="Au moins 10 caractères — une phrase de passe fonctionne bien."
                    />

                    <PasswordField
                        label="Confirmation du mot de passe"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        placeholder="Retapez le mot de passe"
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        error={form.errors.password_confirmation}
                    />

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? 'Réinitialisation…' : 'Réinitialiser le mot de passe'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
