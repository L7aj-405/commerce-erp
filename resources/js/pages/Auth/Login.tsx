import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { Checkbox, PasswordField, TextField } from '@/components/ui/form';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <>
            <Head title="Se connecter" />

            <AuthLayout
                title="Se connecter"
                subtitle="Accédez à votre organisation et à vos magasins."
                footer={
                    <>
                        Pas encore de compte ?{' '}
                        <Link href="/register" className="font-medium text-ink underline-offset-4 hover:underline">
                            Créer un compte
                        </Link>
                    </>
                }
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <TextField
                        label="Adresse email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        autoFocus
                        required
                        placeholder="vous@entreprise.com"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />

                    <PasswordField
                        label="Mot de passe"
                        name="password"
                        autoComplete="current-password"
                        required
                        placeholder="••••••••"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                    />

                    <div className="pt-0.5">
                        <Checkbox
                            label="Se souvenir de moi"
                            checked={form.data.remember}
                            onChange={(event) => form.setData('remember', event.target.checked)}
                        />
                    </div>

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? 'Connexion…' : 'Se connecter'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
