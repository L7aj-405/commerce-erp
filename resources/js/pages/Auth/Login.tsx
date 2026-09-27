import AuthLayout from '@/components/auth/AuthLayout';
import { Button, DownloadLink } from '@/components/ui/Button';
import { Checkbox, PasswordField, TextField } from '@/components/ui/form';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    status?: string | null;
    legalLinks: {
        privacy: string;
        terms: string;
    };
};

export default function Login({ status }: Props) {
    const { props } = usePage<{ errors?: Record<string, string> }>();
    const form = useForm({
        email: '',
        password: '',
        remember: false,
        // Not a real input — only ever set by the server's validation error
        // bag when repeated failures require an additional challenge.
        challenge: '',
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
                    {status && <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-medium text-success">{status}</p>}

                    {form.errors.challenge && (
                        <p className="rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning">{form.errors.challenge}</p>
                    )}
                    {props.errors?.google && (
                        <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{props.errors.google}</p>
                    )}

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

                    <div>
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
                        <Link
                            href="/forgot-password"
                            className="mt-1.5 inline-block text-[13px] text-ink-muted underline-offset-4 hover:underline"
                        >
                            Mot de passe oublié ?
                        </Link>
                    </div>

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
