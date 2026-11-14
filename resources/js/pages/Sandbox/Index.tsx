import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    enabled: boolean;
    canManage: boolean;
    canSwitch: boolean;
    canReset: boolean;
    current: { name: string; status: string; generation: number } | null;
    modules: { label: string; href: string; hint: string }[];
    unavailable: string[];
};

export default function SandboxPage({ enabled, canManage, canSwitch, canReset, current, modules, unavailable }: Props) {
    const { sandbox, flash } = usePage<{ sandbox: { active: boolean; restricted: boolean; id: number | null }; flash?: { success?: string | null } }>().props;
    const resetLink = canReset && <section aria-labelledby="reset" className="rounded-card border border-line bg-surface p-4">
        <h2 id="reset" className="font-semibold">Réinitialiser les données test</h2>
        <p className="text-sm text-ink-muted">Supprime toutes les données transactionnelles test (ventes, documents, paiements, stock test, approvisionnements, expéditions, retours) et repart d’un bac à sable propre. Aucune donnée de Production n’est supprimée.</p>
        <a href="/test-mode/reset" className="mt-3 inline-block rounded border border-danger px-3 py-1.5 text-sm font-semibold text-danger hover:bg-danger-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger">Réinitialiser les données test…</a>
    </section>;
    const [busy, setBusy] = useState(false);
    const submit = (path: string, data = {}) => {
        if (busy) return;
        setBusy(true);
        router.post(path, data, { onFinish: () => setBusy(false) });
    };
    return <main className="min-h-dvh bg-canvas text-ink">
        <Head title="Mode test" />
        {sandbox.restricted && <div role="status" className="sticky top-0 z-50 border-b border-amber-400 bg-amber-100 p-4 text-center text-amber-950 font-semibold">
            MODE TEST — Données de test isolées de la Production.
            <button className="ms-4 underline" disabled={busy} onClick={() => submit('/test-mode/leave')}>Quitter le mode test</button>
        </div>}
        <header className="border-b border-line p-4 font-semibold">10xScale ERP · Mode test</header>
        <section className="mx-auto max-w-3xl space-y-6 p-6">
            {flash?.success && <p role="status" className="rounded-card border border-success bg-success-soft p-4 font-semibold text-ink">{flash.success}</p>}
            {sandbox.active && current ? <>
                <div>
                    <p className="inline-block rounded border border-amber-500 bg-amber-100 px-2 py-0.5 text-xs font-bold tracking-wide text-amber-900">MODE TEST</p>
                    <h1 className="mt-2 text-2xl font-semibold">Mode test — {current.name}</h1>
                    <p className="text-sm text-ink-muted">Bac à sable n°{sandbox.id} · état : {current.status} · génération {current.generation}. Stock, documents et paiements sont simulés et indépendants de la Production.</p>
                </div>
                <section aria-labelledby="available">
                    <h2 id="available" className="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">Modules disponibles</h2>
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {modules.map((module) => <li key={module.href}>
                            <a href={module.href} className="block rounded-card border border-line bg-surface p-4 hover:bg-raised">
                                <span className="font-semibold">{module.label}</span>
                                <span className="block text-sm text-ink-muted">{module.hint}</span>
                            </a>
                        </li>)}
                    </ul>
                </section>
                <section aria-labelledby="unavailable">
                    <h2 id="unavailable" className="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">Pas encore disponibles en mode test</h2>
                    <ul className="list-disc space-y-1 ps-5 text-sm text-ink-muted">
                        {unavailable.map((item) => <li key={item}>{item}</li>)}
                    </ul>
                </section>
                {resetLink}
                <button disabled={busy} className="control-input" onClick={() => submit('/test-mode/leave')}>Quitter le mode test</button>
            </> : <>
                <h1 className="text-2xl font-semibold">Mode test</h1>
                {sandbox.restricted ? <p>Ce contexte n’est plus disponible. Quittez le mode test pour continuer.</p> : <>
                    <p>Votre session est en Production. Le mode test est {enabled ? 'autorisé' : 'désactivé'} pour cette organisation.</p>
                    {canManage && <button disabled={busy} className="control-input" onClick={() => submit('/test-mode/policy', { enabled: !enabled })}>{enabled ? 'Désactiver' : 'Autoriser'} le mode test pour l’organisation</button>}
                    {enabled && canSwitch && <button disabled={busy} className="control-input" onClick={() => submit('/test-mode/enter')}>Entrer en mode test</button>}
                    {resetLink}
                    <a className="block underline" href="/platform">Retour à la Production</a>
                </>}
            </>}
        </section>
    </main>;
}
