import AuthLayout from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/Button';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = { status?: string | null };

export default function VerifyEmail({ status }: Props) {
    const form = useForm({});
    const logout = useForm({});

    const resend = (event: FormEvent) => {
        event.preventDefault();
        form.post('/email/verification-notification');
    };

    return (
        <>
            <Head title="Vérifier votre email" />

            <AuthLayout title="Vérifiez votre adresse email" subtitle="Un lien de confirmation vous a été envoyé.">
                <div className="space-y-4 text-sm text-ink-muted">
                    <p>
                        Avant de continuer, cliquez sur le lien que nous venons de vous envoyer par email pour
                        confirmer votre adresse. Si vous ne l’avez pas reçu, nous pouvons vous en renvoyer un.
                    </p>

                    {status === 'verification-link-sent' && (
                        <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-medium text-success">
                            Un nouveau lien de vérification a été envoyé à votre adresse email.
                        </p>
                    )}

                    <form onSubmit={resend}>
                        <Button type="submit" block disabled={form.processing}>
                            {form.processing ? 'Envoi…' : 'Renvoyer l’email de vérification'}
                        </Button>
                    </form>

                    <form onSubmit={(e) => { e.preventDefault(); logout.post('/logout'); }}>
                        <button type="submit" className="text-sm font-medium text-ink underline-offset-4 hover:underline">
                            Se déconnecter
                        </button>
                    </form>
                </div>
            </AuthLayout>
        </>
    );
}
