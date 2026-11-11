<?php

namespace App\Services\Sandbox;

/**
 * Phase 3 — document-only operations a Test invoice / Delivery Note may use.
 * Anything absent here (e-mail, stamping, corrections, credit notes, finance,
 * stock) is refused for Test records by policies and action traits.
 */
final class SandboxDocumentRules
{
    public const PERMISSIONS = [
        'invoices.view', 'invoices.create', 'invoices.update_draft', 'invoices.issue',
        'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.update_draft', 'delivery_notes.issue',
        'credit_notes.view',
    ];

    /**
     * Test Mode route allowlist for documents. Deliberately absent: e-mail,
     * stamp, signed share links, corrections / correction lines, cross-store
     * document opener, finance exports and every credit-note mutation.
     */
    public const ROUTES = [
        'sales.orders.invoices.store', 'sales.orders.delivery-notes.store',
        'invoices.index', 'invoices.show', 'invoices.update', 'invoices.cancel', 'invoices.issue',
        'invoices.pdf', 'invoices.print', 'invoices.download',
        'delivery-notes.index', 'delivery-notes.show', 'delivery-notes.update', 'delivery-notes.cancel', 'delivery-notes.issue',
        'delivery-notes.pdf', 'delivery-notes.print', 'delivery-notes.download',
        'credit-notes.show', 'credit-notes.pdf', 'credit-notes.print',
    ];

    public static function allows(?int $sandboxId, string $permission): bool
    {
        if ((string) $sandboxId !== (string) app(SandboxContext::class)->id()) {
            return false;
        }

        return $sandboxId === null || in_array($permission, self::PERMISSIONS, true);
    }
}
