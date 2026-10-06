import { Button } from '@/components/ui/Button';
import AccountLayout from '@/layouts/AccountLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type SessionRow = {
    token: string;
    isCurrent: boolean;
    deviceLabel: string;
    browser: string;
    platform: string;
    ipAddress: string | null;
    lastActiveAt: string;
    state: 'recent' | 'stale';
};

export default function Sessions({ sessions }: { sessions: SessionRow[] }) {
    const [processing, setProcessing] = useState<string | 'all' | null>(null);
    const otherSessions = sessions.filter((session) => !session.isCurrent);

    const revoke = (token: string) => {
        setProcessing(token);
        router.delete(`/security/sessions/${token}`, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    };

    const revokeAll = () => {
        setProcessing('all');
        router.delete('/security/sessions', {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    };

    return (
        <AccountLayout>
            <Head title="Sessions actives" />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight text-ink sm:text-2xl">Sessions actives</h1>
                    <p className="mt-1 text-sm text-ink-muted">Les navigateurs où votre compte est actuellement connecté, toutes organisations confondues.</p>
                </div>
                {otherSessions.length > 0 && (
                    <Button variant="secondary" onClick={revokeAll} disabled={processing !== null} className="w-full sm:w-auto">
                        {processing === 'all' ? 'Déconnexion…' : 'Déconnecter toutes les autres'}
                    </Button>
                )}
            </div>

            <section className="mt-6 overflow-hidden rounded-2xl border bg-white">
                <ul className="divide-y">
                    {sessions.map((session) => (
                        <li key={session.token} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="font-medium text-ink">{session.deviceLabel}</p>
                                    {session.isCurrent && (
                                        <span className="rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-success">Session actuelle</span>
                                    )}
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${session.state === 'recent' ? 'bg-success-soft text-success' : 'bg-muted text-ink-muted'}`}>
                                        {session.state === 'recent' ? 'Active récemment' : 'Inactive'}
                                    </span>
                                </div>
                                <p className="mt-1 text-xs text-ink-muted">
                                    {session.ipAddress ?? 'Adresse IP indisponible'} · dernière activité {new Date(session.lastActiveAt).toLocaleString('fr-FR')}
                                </p>
                            </div>
                            {session.isCurrent ? (
                                <p className="text-xs text-ink-muted">Utilisez la déconnexion normale pour terminer cette session.</p>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => revoke(session.token)}
                                    disabled={processing !== null}
                                    className="self-start rounded-field px-2 py-1.5 text-sm text-danger underline-offset-4 hover:underline disabled:opacity-50 sm:self-auto"
                                >
                                    {processing === session.token ? 'Déconnexion…' : 'Déconnecter'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </section>

            <p className="mt-4 text-xs leading-5 text-ink-muted">
                Les sessions actives sont distinctes des appareils de confiance 2FA. Un appareil de confiance peut ignorer la seconde étape lors d’une future connexion, sans prolonger cette session.
            </p>
        </AccountLayout>
    );
}
