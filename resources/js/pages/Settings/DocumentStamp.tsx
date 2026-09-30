import { Button, ButtonLink } from '@/components/ui/Button';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

type Stamp = {
    id: number;
    source: 'uploaded' | 'digitized';
    uploaded_at: string;
};

type Props = {
    organization: { id: number; name: string };
    stamp: Stamp | null;
    canUpdate: boolean;
};

export default function DocumentStamp({ stamp, canUpdate }: Props) {
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [cacheBust, setCacheBust] = useState(0);

    const form = useForm<{ image: File | null }>({
        image: null,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/document-stamp', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.setData('image', null);
                setCacheBust((n) => n + 1);
            },
        });
    };

    const remove = () => {
        form.delete('/document-stamp', { preserveScroll: true, onSuccess: () => setConfirmRemove(false) });
    };

    return (
        <ApplicationShell>
            <Head title="Cachet de l’entreprise" />
            <main className="mx-auto max-w-3xl">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">Cachet de l’entreprise</h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Le cachet numérique utilisé pour marquer vos factures et devis. Ce n’est pas une signature
                            électronique certifiée.
                        </p>
                    </div>
                    <span
                        className={`inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                            stamp ? 'bg-success-soft text-success' : 'bg-raised text-ink-muted'
                        }`}
                    >
                        {stamp ? 'Configuré' : 'Non configuré'}
                    </span>
                </div>

                {!canUpdate && (
                    <p className="mt-4 rounded-field border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                        Lecture seule — vous n’avez pas la permission de modifier le cachet de l’entreprise.
                    </p>
                )}

                {!stamp && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <p className="text-sm text-ink-muted">
                            Téléversez le cachet numérique de votre entreprise pour pouvoir l’apposer sur vos documents.
                        </p>
                        <p className="mt-1 text-xs text-ink-faint">PNG (idéalement transparent), JPG ou WebP.</p>
                    </section>
                )}

                {stamp && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <h2 className="mb-3 text-sm font-semibold text-ink">Aperçu du cachet</h2>
                        <div className="flex size-28 items-center justify-center overflow-hidden rounded-card border border-line bg-raised">
                            <img
                                key={cacheBust}
                                src={`/document-stamp/image?v=${cacheBust}`}
                                alt="Cachet de l’entreprise"
                                className="max-h-full max-w-full object-contain"
                            />
                        </div>
                    </section>
                )}

                <form onSubmit={submit} className="mt-4 space-y-4">
                    <fieldset disabled={!canUpdate} className="space-y-4">
                        <Section title={stamp ? 'Remplacer l’image' : 'Ajouter un cachet'}>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(e) => form.setData('image', e.target.files?.[0] ?? null)}
                                className="block text-sm"
                            />
                            <p className="text-xs text-ink-faint">PNG (idéalement transparent), JPG ou WebP · 2 Mo max.</p>
                            {form.errors.image && <p className="text-xs text-danger">{form.errors.image}</p>}
                        </Section>

                        <Section title="Présentation sur les documents">
                            <p className="text-sm text-ink-muted">
                                La taille, la position, la rotation, l’opacité et la visibilité du cachet sont centralisées dans le Studio PDF.
                            </p>
                            <ButtonLink href="/document-profile/studio" variant="secondary" size="sm">Ouvrir le Studio PDF</ButtonLink>
                        </Section>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="submit" loading={form.processing} loadingText="Enregistrement…">
                                Enregistrer
                            </Button>
                        </div>
                    </fieldset>
                </form>

                {stamp && canUpdate && (
                    <section className="mt-8 rounded-card border border-line bg-surface p-5">
                        <h2 className="text-sm font-semibold text-ink">Désactiver le cachet</h2>
                        <p className="mt-1 text-xs text-ink-muted">
                            Les documents déjà cachetés conservent leur cachet. Seules les nouvelles appositions seront
                            bloquées tant qu’aucun cachet n’est reconfiguré.
                        </p>
                        {!confirmRemove ? (
                            <Button type="button" variant="danger" size="sm" className="mt-3" onClick={() => setConfirmRemove(true)}>
                                Désactiver le cachet
                            </Button>
                        ) : (
                            <div className="mt-3 flex items-center gap-2">
                                <Button type="button" variant="danger" size="sm" loading={form.processing} onClick={remove}>
                                    Confirmer la désactivation
                                </Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmRemove(false)}>
                                    Annuler
                                </Button>
                            </div>
                        )}
                    </section>
                )}
            </main>
        </ApplicationShell>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-card border border-line bg-surface p-5">
            <h2 className="mb-3 text-sm font-semibold text-ink">{title}</h2>
            <div className="space-y-3">{children}</div>
        </section>
    );
}
