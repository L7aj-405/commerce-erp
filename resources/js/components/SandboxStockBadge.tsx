import { usePage } from '@inertiajs/react';

/**
 * Phase 4 — marks stock quantities as Test stock while Test Mode is active.
 * Renders nothing in Production, so Production stock displays are unchanged.
 */
export default function SandboxStockBadge({ inline = false }: { inline?: boolean }) {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    if (inline) {
        return (
            <span title="Quantités du stock test, indépendantes de la Production" className="ms-1 rounded border border-amber-500 bg-amber-100 px-1 text-[10px] font-bold uppercase text-amber-900">
                Stock test
            </span>
        );
    }
    return (
        <div role="note" className="mb-4 rounded-field border border-amber-400 bg-amber-50 px-4 py-2 text-sm text-amber-950">
            <strong className="me-2">Stock test</strong>
            Quantités du mode test, initialisées depuis la Production puis indépendantes — aucune opération ici ne modifie le stock réel.
        </div>
    );
}
