import { FlaskConicalIcon } from '@/components/ui/icons';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Item = { key: string; label: string; count: number };
type Domain = { domain: string; total: number; items: Item[] };
type Props = {
    preview: {
        sandbox: { id: number; name: string; generation: number; status: string };
        domains: Domain[];
        total: number;
        fingerprint: string;
    };
    phrase: string;
};

const KEPT = ['Produits et variantes', 'Clients', 'Fournisseurs et catalogue fournisseur', 'Magasins et entrepôts', 'Utilisateurs et rôles', 'Comptes financiers', 'Règles de commission', 'Paramètres de l’organisation', 'Toutes les données de Production'];

/** Phase 9 — Test data reset: preview (Test counts only) → typed confirmation → reset. */
export default function SandboxReset({ preview, phrase }: Props) {
    const { sandbox } = usePage<{ sandbox: { active: boolean; restricted: boolean } }>().props;
    const form = useForm({ confirmation: '', fingerprint: preview.fingerprint });
    const errors = form.errors as Record<string, string | undefined>;
    const ready = form.data.confirmation === phrase;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!ready || form.processing) return;
        form.transform((data) => ({ ...data, fingerprint: preview.fingerprint }));
        form.post('/test-mode/reset', { preserveScroll: true, onError: () => form.reset('confirmation') });
    };

    return <main className="min-h-dvh bg-canvas text-ink">
        <Head title="Réinitialiser le mode test" />
        {sandbox.restricted && <div role="status" className="sticky top-0 z-50 flex min-h-9 flex-wrap items-center justify-center gap-x-3 gap-y-0.5 border-b border-accent/25 bg-accent-soft px-4 py-1 text-[13px] font-medium text-ink">
            <span className="inline-flex items-center gap-2 font-semibold text-accent"><FlaskConicalIcon size={15} className="shrink-0" />Mode test</span>
            Aucune donnée de Production n’est modifiée.
        </div>}
        <header className="border-b border-line p-4 font-semibold">10xScale ERP · Mode test</header>
        <section className="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
            <div className="rounded-card border-2 border-danger bg-danger-soft p-4">
                <h1 className="text-xl font-bold tracking-wide text-danger sm:text-2xl">RÉINITIALISATION DU MODE TEST</h1>
                <p className="mt-2 font-semibold text-ink">AUCUNE DONNÉE DE PRODUCTION NE SERA SUPPRIMÉE</p>
                <p className="mt-1 text-sm text-ink-muted">
                    Bac à sable « {preview.sandbox.name} » · génération {preview.sandbox.generation} → {preview.sandbox.generation + 1}.
                    Toutes les données transactionnelles test ci-dessous seront définitivement supprimées. Le stock test sera réinitialisé depuis le stock de Production à la prochaine utilisation.
                </p>
            </div>

            {(errors.reset || errors.fingerprint) && <p role="alert" className="rounded-card border border-danger bg-surface p-4 font-semibold text-danger">
                {errors.reset ?? 'L’aperçu n’est plus valide. Rechargez la page.'} Aucune donnée n’a été supprimée.
            </p>}

            <section aria-labelledby="preview-title" className="rounded-card border border-line bg-surface">
                <h2 id="preview-title" className="border-b border-line p-4 font-semibold">Aperçu — données test à supprimer ({preview.total})</h2>
                {preview.total === 0 && <p className="p-4 text-sm text-ink-muted">Aucune donnée test à supprimer. La réinitialisation repartira simplement sur une nouvelle génération.</p>}
                <div className="divide-y divide-line">
                    {preview.domains.map((domain) => <details key={domain.domain} className="group p-4" open={domain.total > 0}>
                        <summary className="flex cursor-pointer items-center justify-between gap-4 font-medium">
                            <span>{domain.domain}</span>
                            <span className="tabular-nums">{domain.total}</span>
                        </summary>
                        <dl className="mt-2 grid gap-1 text-sm">
                            {domain.items.map((item) => <div key={item.key} className="flex justify-between gap-4 text-ink-muted">
                                <dt>{item.label}</dt>
                                <dd className="tabular-nums">{item.count}</dd>
                            </div>)}
                        </dl>
                    </details>)}
                </div>
            </section>

            <section aria-labelledby="kept-title" className="rounded-card border border-line bg-surface p-4">
                <h2 id="kept-title" className="font-semibold">Conservé</h2>
                <ul className="mt-2 grid list-disc gap-1 ps-5 text-sm text-ink-muted sm:grid-cols-2">
                    {KEPT.map((item) => <li key={item}>{item}</li>)}
                </ul>
                <p className="mt-2 text-sm text-ink-muted">Les numéros test (TEST-FAC, TEST-PAY…) continuent leur séquence : aucune référence test déjà émise n’est réutilisée.</p>
            </section>

            <form onSubmit={submit} className="space-y-3 rounded-card border border-line bg-surface p-4">
                <label htmlFor="confirmation" className="block font-semibold">Pour confirmer, saisissez <code className="rounded bg-raised px-1">{phrase}</code></label>
                <input id="confirmation" className="control-input w-full" autoComplete="off" spellCheck={false}
                    value={form.data.confirmation} onChange={(event) => form.setData('confirmation', event.target.value)}
                    aria-invalid={errors.confirmation ? true : undefined} aria-describedby={errors.confirmation ? 'confirmation-error' : undefined} />
                {errors.confirmation && <p id="confirmation-error" className="text-sm text-danger">{errors.confirmation}</p>}
                <div className="flex flex-wrap items-center gap-3">
                    <button type="submit" disabled={!ready || form.processing}
                        className="rounded bg-danger px-4 py-2 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger">
                        {form.processing ? 'Réinitialisation en cours…' : 'Réinitialiser les données test'}
                    </button>
                    <a href="/test-mode" className="underline">Annuler</a>
                </div>
            </form>
        </section>
    </main>;
}
