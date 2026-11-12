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
        <div role="note" className="mb-3 rounded-field border border-amber-400 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <strong className="me-2">MODE TEST — EXPÉDITION TEST</strong>
            Le stock test sort une seule fois, à la remise. Aucun transporteur ni client n’est contacté : <strong>NOTIFICATION TEST — NON ENVOYÉE</strong>.
        </div>
    );
}
