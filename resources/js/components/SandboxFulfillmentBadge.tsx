import { SandboxNotice } from '@/components/SandboxBadge';
import { usePage } from '@inertiajs/react';

/**
 * Phase 7 — marks the fulfillment / shipment / hand-over card as Test data while
 * Test Mode is active: shipments live on the Test ledger, stock leaves Test
 * inventory only, and customer / carrier notifications are simulated. Renders
 * nothing in Production.
 */
export default function SandboxFulfillmentBadge() {
    const { sandbox } = usePage<{ sandbox?: { active: boolean } }>().props;
    if (!sandbox?.active) return null;
    return (
        <SandboxNotice kind="fulfillment">
            Le stock test sort une seule fois, à la remise. Aucun transporteur ni client n’est contacté : notification test non envoyée.
        </SandboxNotice>
    );
}
