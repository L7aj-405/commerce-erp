<?php

namespace App\Services\Pos;

use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\WarehouseStatus;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * One authoritative, explainable eligibility decision for the narrow
 * add-only POS completion workflow. Authorization is evaluated first; the
 * remaining reasons are safe operational explanations for an authorized user.
 */
class PosOrderCompletionEligibility
{
    /** @return array{allowed: bool, code: string, reason: string|null} */
    public function evaluate(User $actor, SalesOrder $order): array
    {
        if (! $actor->can('completePos', $order)) {
            return $this->denied('missing_permission', 'Vous n’avez pas l’autorisation de compléter cette commande.');
        }

        if ($order->source !== SalesOrderSource::Pos) {
            return $this->denied('not_pos_order', 'Seules les commandes créées depuis le POS peuvent être complétées.');
        }

        if ($order->status !== SalesOrderStatus::Confirmed) {
            return $this->denied('not_confirmed', 'La commande doit être confirmée avant de pouvoir être complétée.');
        }

        if ($order->fulfillment_status !== SalesOrderFulfillmentStatus::Fulfilled) {
            return $this->denied('not_fully_fulfilled', 'La commande doit avoir été entièrement remise avant de pouvoir être complétée.');
        }

        if ($order->pos_fulfillment_mode !== 'pickup') {
            return $this->denied('not_immediate_pickup', 'Seules les ventes POS en retrait immédiat peuvent être complétées.');
        }

        if ($order->hasActiveCorrection()) {
            return $this->denied('active_correction', 'Terminez ou annulez la correction commerciale en cours avant ce complément.');
        }

        if ($order->invoices()->where('status', InvoiceStatus::Draft->value)->exists()) {
            return $this->denied('draft_invoice_exists', 'Annulez ou finalisez d’abord la facture brouillon devenue potentiellement obsolète.');
        }

        if ($order->deliveryNotes()->where('status', 'draft')->exists()) {
            return $this->denied('draft_delivery_note_exists', 'Un bon de livraison brouillon existe déjà : ce complément doit être traité séparément.');
        }

        if ($order->deliveryNotes()->where('status', 'issued')->exists()) {
            return $this->denied('issued_delivery_note_exists', 'Un bon de livraison émis existe déjà : ce complément doit être traité séparément.');
        }

        if (($order->pos_global_discount_type ?? 'none') !== 'none'
            && Decimal::compare($order->pos_global_discount_value ?? '0', '0') > 0) {
            return $this->denied(
                'historical_global_discount',
                'Cette vente comporte une remise globale historique ; ajoutez les nouveaux articles dans une vente séparée.',
            );
        }

        $warehouse = $order->posWarehouse;
        if (! $warehouse
            || $warehouse->organization_id !== $order->organization_id
            || $warehouse->status !== WarehouseStatus::Active) {
            return $this->denied('missing_local_warehouse', 'L’entrepôt POS local actif est introuvable.');
        }

        return ['allowed' => true, 'code' => 'allowed', 'reason' => null];
    }

    /** @throws ValidationException */
    public function assertAllowed(User $actor, SalesOrder $order): void
    {
        $result = $this->evaluate($actor, $order);

        if (! $result['allowed']) {
            throw ValidationException::withMessages(['order' => $result['reason']]);
        }
    }

    /** @return array{allowed: false, code: string, reason: string} */
    private function denied(string $code, string $reason): array
    {
        return ['allowed' => false, 'code' => $code, 'reason' => $reason];
    }
}
