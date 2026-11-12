import { usePage } from '@inertiajs/react';

/**
 * Phase 5 — marks payments as simulated while Test Mode is active. Test
 * payments live on the Test finance ledger and never touch Production cash,
 * bank or TPE totals. Renders nothing in Production.
 */
export default function SandboxPaymentBadge({ inline = false }: { inline?: boolean }) {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    if (inline) {
        return (
            <span title="Paiement simulé : aucun effet sur la caisse, la banque ou le TPE réels" className="ms-2 rounded border border-amber-500 bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-900">
                PAIEMENT TEST / SIMULÉ
            </span>
        );
    }
    return (
        <div role="note" className="mb-3 rounded-field border border-amber-400 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <strong className="me-2">PAIEMENT TEST / SIMULÉ</strong>
            Aucun encaissement réel — caisse, banque, TPE, créances et commissions de Production inchangés.
        </div>
    );
}
