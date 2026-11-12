<?php

namespace App\Services\Sandbox;

/**
 * Parity phase — what a Test quotation may do, using the SAME Production
 * actions on the sandbox-scoped row. Anything absent (revision, duplicate,
 * supplier sourcing / availability, signed public share link, customer
 * creation) stays refused for Test records by the policy, the action trait and
 * the route allowlist.
 */
final class SandboxQuotationRules
{
    public const PERMISSIONS = [
        'quotations.view', 'quotations.update', 'quotations.convert',
        'quotations.issue', 'quotations.accept', 'quotations.email', 'documents.stamp',
        // Phase 6: supplier sourcing on Test quotations (Test ledger only).
        'procurement.manage', 'suppliers.view',
    ];

    /**
     * Document views plus the lifecycle actions. `quotations.email` only
     * SIMULATES the dispatch in Test Mode (see SendQuotationEmailAction).
     */
    public const ROUTES = [
        'quotations.print', 'quotations.pdf', 'quotations.download',
        'quotations.issue', 'quotations.accept', 'quotations.reject',
        'quotations.email', 'quotations.stamp',
    ];

    public static function allows(?int $sandboxId, string $permission): bool
    {
        if ((string) $sandboxId !== (string) app(SandboxContext::class)->id()) {
            return false;
        }

        return $sandboxId === null || in_array($permission, self::PERMISSIONS, true);
    }
}
