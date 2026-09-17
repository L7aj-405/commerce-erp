import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = { status?: string | null };

export default function ForgotPassword({ status }: Props) {
    const form = useForm({ email: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/forgot-password');
    }

    return (
        <>
            <Head title="Mot de passe oublié" />

            <AuthLayout
                title="Mot de passe oublié ?"
                subtitle="Indiquez votre adresse email, nous vous enverrons un lien de réinitialisation."
                footer={
                    <Link href="/login" className="font-medium text-ink underline-offset-4 hover:underline">
                        Retour à la connexion
                    </Link>
                }
            >
                <form onSubmit={submit} className="space-y-4" noValidate>
                    {status && <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-medium text-success">{status}</p>}

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

                    <Button type="submit" block size="lg" disabled={form.processing}>
                        {form.processing ? 'Envoi…' : 'Envoyer le lien de réinitialisation'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
