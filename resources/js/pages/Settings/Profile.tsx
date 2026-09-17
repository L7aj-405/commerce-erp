import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/form';
import AccountLayout from '@/layouts/AccountLayout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    user: { name: string; email: string };
    emailVerified: boolean;
    status?: string | null;
};

export default function Profile({ user, emailVerified, status }: Props) {
    const form = useForm({ name: user.name, email: user.email, current_password: '' });
    const resendForm = useForm({});
    const emailChanged = form.data.email !== user.email;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch('/account/profile', {
            preserveScroll: true,
            onSuccess: () => form.setData('current_password', ''),
        });
    };

    const resendVerification = () => {
        resendForm.post('/email/verification-notification', { preserveScroll: true, preserveState: true });
    };

    return (
        <AccountLayout>
            <Head title="Mon profil" />
            <h1 className="text-xl font-semibold tracking-tight text-ink sm:text-2xl">Mon profil</h1>
            <p className="mt-1 text-sm text-ink-muted">Vos informations personnelles. La gestion des accès de l’organisation se fait depuis « Utilisateurs & accès ».</p>

            {status === 'verification-link-sent' && (
                <p className="mt-4 rounded-field bg-success-soft px-3.5 py-2.5 text-[13px] font-medium text-success">
                    Un nouveau lien de vérification a été envoyé à votre adresse email.
                </p>
            )}

            <section className="mt-6 rounded-card border border-line bg-surface p-4 sm:p-6">
                <form onSubmit={submit} className="space-y-4">
                    <TextField
                        label="Nom"
                        name="name"
                        autoComplete="name"
                        required
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                    />

                    <div>
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
                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                            {emailVerified ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 text-[12px] font-medium text-success">
                                    ✓ E-mail vérifié
                                </span>
                            ) : (
                                <>
                                    <span className="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-1 text-[12px] font-medium text-warning">
                                        Vérification requise
                                    </span>
                                    <button
                                        type="button"
                                        onClick={resendVerification}
                                        disabled={resendForm.processing}
                                        className="text-[12px] font-medium text-ink-muted underline-offset-4 hover:underline disabled:opacity-50"
                                    >
                                        {resendForm.processing ? 'Envoi…' : 'Renvoyer l’email de vérification'}
                                    </button>
                                </>
                            )}
                        </div>
                    </div>

                    {emailChanged && (
                        <div className="rounded-field border border-line bg-raised p-3.5">
                            <p className="text-[13px] text-ink-muted">
                                Changer votre adresse email est une opération sensible : confirmez votre mot de passe actuel pour continuer.
                                Vous devrez vérifier la nouvelle adresse avant de retrouver l’accès complet.
                            </p>
                            <TextField
                                label="Mot de passe actuel"
                                type="password"
                                name="current_password"
                                autoComplete="current-password"
                                className="mt-3"
                                value={form.data.current_password}
                                onChange={(event) => form.setData('current_password', event.target.value)}
                                error={form.errors.current_password}
                            />
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" loading={form.processing} loadingText="Enregistrement…" className="w-full sm:w-auto">
                            Enregistrer
                        </Button>
                    </div>
                </form>
            </section>
        </AccountLayout>
    );
}
