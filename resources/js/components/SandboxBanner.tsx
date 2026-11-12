import { router, usePage } from '@inertiajs/react';
export default function SandboxBanner() {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    return <div role="status" className="sticky top-0 z-50 shrink-0 border-b border-amber-400 bg-amber-100 p-3 text-center text-sm text-amber-950">
        <strong className="me-2">TEST</strong> Documents, stock test et PAIEMENTS TEST / SIMULÉS — aucune donnée de Production n’est modifiée. Expédition, retours et achats indisponibles.
        <button className="ms-3 underline" onClick={() => router.post('/test-mode/leave')}>Quitter le mode test</button>
    </div>;
}
