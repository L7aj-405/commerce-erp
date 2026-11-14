import { SandboxNotice } from '@/components/SandboxBadge';
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
        <SandboxNotice kind={kind}>
            Stock test uniquement, remboursements simulés, avoirs TEST-AV sans valeur fiscale : aucun effet sur la Production.
        </SandboxNotice>
    );
}
