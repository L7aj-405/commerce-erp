import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Register() {
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    return (
        <>
            <Head title="Créer un compte" />

            <AuthLayout
                title="Créer un compte"
                subtitle="Quelques informations suffisent. La configuration de l’activité vient ensuite."
                aside="Créez d’abord votre compte. Vous préparerez votre organisation et votre premier magasin juste après, en deux étapes."
                footer={
                    <>
                        Vous avez déjà un compte ?{' '}
                        <Link href="/login" className="font-medium text-ink underline-offset-4 hover:underline">
                            Se connecter
                        </Link>
                    </>
                }
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <TextField
                        label="Nom"
                        name="name"
                        autoComplete="name"
                        autoFocus
                        required
                        placeholder="Prénom Nom"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                    />

                    <TextField
                        label="Adresse email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        required
                        placeholder="vous@entreprise.com"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />

                    <PasswordField
                        label="Mot de passe"
                        name="password"
                        autoComplete="new-password"
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
                        {form.processing ? 'Création…' : 'Créer mon compte'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
