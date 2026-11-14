import { SandboxBadge, SandboxNotice } from '@/components/SandboxBadge';
import { usePage } from '@inertiajs/react';

/**
 * Phase 5 — marks payments as simulated while Test Mode is active. Test
 * payments live on the Test finance ledger and never touch Production cash,
 * bank or TPE totals. Renders nothing in Production.
 */
export default function SandboxPaymentBadge({ inline = false }: { inline?: boolean }) {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    if (inline) return <SandboxBadge kind="payment" className="ms-2" />;
    return (
        <SandboxNotice kind="payment">
            Paiements simulés : caisse, banque, TPE, créances et commissions de Production inchangés.
        </SandboxNotice>
    );
}
