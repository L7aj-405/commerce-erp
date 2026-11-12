import { usePage } from '@inertiajs/react';

/**
 * Phase 6 — marks procurement / purchasing screens as Test data while Test Mode
 * is active: requirements, purchase orders (TEST-PO-…) and receipts live on the
 * Test ledger and only ever increase Test stock. Renders nothing in Production.
 */
export default function SandboxProcurementBadge({ inline = false }: { inline?: boolean }) {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    if (inline) {
        return (
            <span title="Approvisionnement test : aucun effet sur les achats, réceptions ou le stock de Production" className="ms-2 rounded border border-amber-500 bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-900">
                APPROVISIONNEMENT TEST
            </span>
        );
    }
    return (
        <div role="note" className="mb-4 rounded-field border border-amber-400 bg-amber-50 px-4 py-2 text-sm text-amber-950">
            <strong className="me-2">MODE TEST — APPROVISIONNEMENT TEST</strong>
            Besoins, commandes fournisseurs (TEST-PO) et réceptions simulés : seul le stock test augmente, aucun fournisseur n’est contacté.
        </div>
    );
}
