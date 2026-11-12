import { usePage } from '@inertiajs/react';

/**
 * Phase 8 — marks the after-sales screens (returns, exchanges, credit notes,
 * refunds) as Test data while Test Mode is active: restocking and replacement
 * stock use Test inventory, refunds are simulated on the Test finance ledger,
 * credit notes are TEST-AV documents without fiscal value. Renders nothing in
 * Production.
 */
export default function SandboxReturnsBadge({ kind }: { kind: 'return' | 'exchange' }) {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    return (
        <div role="note" className="mb-3 rounded-field border border-amber-400 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <strong className="me-2">MODE TEST — {kind === 'exchange' ? 'ÉCHANGE TEST' : 'RETOUR TEST'}</strong>
            <strong className="me-2">REMBOURSEMENT TEST / SIMULÉ</strong>
            Stock test uniquement, avoirs TEST-AV sans valeur fiscale : aucun effet sur la Production.
        </div>
    );
}
