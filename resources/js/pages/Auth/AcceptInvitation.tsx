import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { FormBanner, PasswordField, TextField } from '@/components/ui/form';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    status: 'invalid' | 'pending';
    token?: string;
    invitation?: { organization: string; role: string; email: string; expires_at: string };
    userExists?: boolean;
    authenticatedEmail?: string | null;
};

export default function AcceptInvitation({ status, token, invitation, userExists, authenticatedEmail }: Props) {
    if (status === 'invalid' || !invitation || !token) {
        return (
            <>
                <Head title="Invitation invalide" />
                <AuthLayout title="Invitation invalide" subtitle="Ce lien d’invitation n’est plus valable.">
                    <FormBanner tone="danger">
                        Ce lien a expiré, a déjà été utilisé, ou n’existe pas. Demandez à un administrateur de vous envoyer une
                        nouvelle invitation.
                    </FormBanner>
                    <p className="mt-4 text-sm text-ink-muted">
                        <Link href="/login" className="font-medium text-ink underline-offset-4 hover:underline">
                            Retour à la connexion
                        </Link>
                    </p>
                </AuthLayout>
            </>
        );
    }

    const loggedInAsSomeoneElse = !!authenticatedEmail && authenticatedEmail !== invitation.email;

    return (
        <>
            <Head title="Accepter l’invitation" />
            <AuthLayout
                title={`Rejoindre ${invitation.organization}`}
                subtitle={`Vous avez été invité(e) avec le rôle « ${invitation.role} » (${invitation.email}).`}
            >
                {loggedInAsSomeoneElse ? (
                    <ExistingUserMismatch email={invitation.email} authenticatedEmail={authenticatedEmail!} />
                ) : userExists ? (
                    <ExistingUserAccept email={invitation.email} authenticated={!!authenticatedEmail} token={token} />
                ) : (
                    <NewUserAccept email={invitation.email} token={token} />
                )}
            </AuthLayout>
        </>
    );
}

function ExistingUserMismatch({ email, authenticatedEmail }: { email: string; authenticatedEmail: string }) {
    return (
        <div className="space-y-4">
            <FormBanner tone="danger">
                Cette invitation est destinée à {email}, mais vous êtes connecté(e) en tant que {authenticatedEmail}.
            </FormBanner>
            <Button type="button" variant="secondary" block onClick={() => router.post('/logout')}>
                Se déconnecter
            </Button>
            <p className="text-center text-[13px] text-ink-faint">
                Reconnectez-vous en tant que {email} puis rouvrez ce lien d’invitation depuis l’email reçu.
            </p>
        </div>
    );
}

function ExistingUserAccept({ email, authenticated, token }: { email: string; authenticated: boolean; token: string }) {
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const accept = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/invitations/${token}`);
    };

    if (!authenticated) {
        return (
            <div className="space-y-4">
                <p className="text-sm text-ink-muted">
                    Un compte existe déjà pour {email}. Connectez-vous, puis rouvrez ce lien d’invitation depuis l’email reçu pour
                    l’accepter.
                </p>
                <Link
                    href="/login"
                    className="inline-flex w-full items-center justify-center rounded-field bg-primary px-4 py-2.5 text-sm font-medium text-primary-fg hover:bg-primary-hover"
                >
                    Se connecter
                </Link>
            </div>
        );
    }

    return (
        <form onSubmit={accept} className="space-y-4">
            {errors.email && <FormBanner tone="danger">{errors.email}</FormBanner>}
            <Button type="submit" block size="lg" disabled={form.processing}>
                {form.processing ? 'Acceptation…' : 'Accepter l’invitation'}
            </Button>
        </form>
    );
}

function NewUserAccept({ email, token }: { email: string; token: string }) {
    const form = useForm({ name: '', password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/invitations/${token}`, { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            <TextField label="Adresse email" value={email} disabled />
            <TextField
                label="Nom"
                autoComplete="name"
                autoFocus
                required
                placeholder="Prénom Nom"
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                error={form.errors.name}
            />
            <PasswordField
                label="Mot de passe"
                autoComplete="new-password"
                required
                placeholder="8 caractères minimum"
                value={form.data.password}
                onChange={(e) => form.setData('password', e.target.value)}
                error={form.errors.password}
                hint="Au moins 8 caractères."
            />
            <PasswordField
                label="Confirmation du mot de passe"
                autoComplete="new-password"
                required
                placeholder="Retapez le mot de passe"
                value={form.data.password_confirmation}
                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                error={form.errors.password_confirmation}
            />
            <Button type="submit" block size="lg" disabled={form.processing}>
                {form.processing ? 'Création…' : 'Créer mon compte et rejoindre'}
            </Button>
        </form>
    );
}
