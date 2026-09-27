import AuthLayout from '@/components/auth/AuthLayout';
import { Button, DownloadLink } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    legalLinks: {
        privacy: string;
        terms: string;
    };
};

export default function Register({}: Props) {
    const { props } = usePage<{ errors?: Record<string, string> }>();
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
                    {props.errors?.google && (
                        <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{props.errors.google}</p>
                    )}

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

                    <div className="flex items-center gap-3 py-1 text-xs uppercase tracking-wide text-ink-muted">
                        <span className="h-px flex-1 bg-line" />
                        ou
                        <span className="h-px flex-1 bg-line" />
                    </div>

                    <DownloadLink href="/auth/google/redirect" variant="secondary" block size="lg">
                        <span className="font-semibold">G</span>
                        Continuer avec Google
                    </DownloadLink>
                </form>
            </AuthLayout>
        </>
    );
}
