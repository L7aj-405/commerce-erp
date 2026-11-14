import { SandboxBadge, SandboxNotice } from '@/components/SandboxBadge';
import { usePage } from '@inertiajs/react';

/**
 * Phase 6 — marks procurement / purchasing screens as Test data while Test Mode
 * is active: requirements, purchase orders (TEST-PO-…) and receipts live on the
 * Test ledger and only ever increase Test stock. Renders nothing in Production.
 */
export default function SandboxProcurementBadge({ inline = false }: { inline?: boolean }) {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    if (inline) return <SandboxBadge kind="procurement" className="ms-2" />;
    return (
        <SandboxNotice kind="procurement" className="mb-4">
            Besoins, commandes fournisseurs (TEST-PO) et réceptions simulés : seul le stock test augmente, aucun fournisseur n’est contacté.
        </SandboxNotice>
    );
}
