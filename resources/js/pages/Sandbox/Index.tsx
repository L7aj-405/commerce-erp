import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
export default function SandboxPage({ enabled, canManage, canSwitch }: { enabled: boolean; canManage: boolean; canSwitch: boolean }) {
    const { sandbox } = usePage<{ sandbox: { active: boolean; restricted: boolean; id: number | null } }>().props;
    const [busy, setBusy] = useState(false);
    const submit = (path: string, data = {}) => {
        if (busy) return;
        setBusy(true);
        router.post(path, data, { onFinish: () => setBusy(false) });
    };
    return <main className="min-h-dvh bg-canvas text-ink">
        <Head title="Mode test" />
        {sandbox.restricted && <div role="status" className="sticky top-0 z-50 border-b border-amber-400 bg-amber-100 p-4 text-center text-amber-950 font-semibold">
            MODE TEST — Brouillons commerciaux uniquement.
            <button className="ms-4 underline" disabled={busy} onClick={() => submit('/test-mode/leave')}>Quitter le mode test</button>
        </div>}
        <header className="border-b border-line p-4 font-semibold">10xScale ERP · Mode test</header>
        <section className="mx-auto max-w-2xl space-y-5 p-6">
            <h1 className="text-2xl font-semibold">Fondation du mode test</h1>
            <p>Phase 2 : paniers POS, devis et commandes TEST en brouillon. Aucun stock, paiement ou document officiel.</p>
            {sandbox.active && <nav className="flex flex-wrap gap-4"><a href="/pos">POS TEST</a><a href="/quotations">Devis TEST</a><a href="/sales/orders">Commandes TEST</a></nav>}
            {sandbox.restricted ? <p>{sandbox.active ? 'Votre session est en mode test.' : 'Ce contexte n’est plus disponible. Quittez le mode test pour continuer.'}</p> : <>
                <p>Votre session est en Production. Le mode test est {enabled ? 'autorisé' : 'désactivé'} pour cette organisation.</p>
                {canManage && <button disabled={busy} className="control-input" onClick={() => submit('/test-mode/policy', { enabled: !enabled })}>{enabled ? 'Désactiver' : 'Autoriser'} le mode test pour l’organisation</button>}
                {enabled && canSwitch && <button disabled={busy} className="control-input" onClick={() => submit('/test-mode/enter')}>Entrer en mode test</button>}
                <a className="block underline" href="/platform">Retour à la Production</a>
            </>}
        </section>
    </main>;
}
