<?php

namespace App\Actions\Pos\Concerns;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Shared guards of the POS cart sourcing actions. Tenancy comes from the
 * actor's trusted active organisation / store (never a client id); the cart is
 * a still-open POS draft Sales Order of that store, locked FOR UPDATE.
 */
trait MutatesPosCart
{
    use AuthorizesSalesAction;

    /**
     * Tenant + store membership + every permission. Creating evidence / handing
     * the cart over are POS acts (callers add pos.access); recording or
     * withdrawing a supplier answer is the purchasing owner's job, exactly like
     * on a Devis, and may happen from Achats › Demandes fournisseur.
     *
     * @param  list<string>  $permissions
     */
    private function authorizeCart(User $actor, SalesOrder $cart, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->authorizeOrder($actor, $cart, $permission);
        }
    }

    private function lockCart(SalesOrder $cart): SalesOrder
    {
        $locked = SalesOrder::query()
            ->where('organization_id', $cart->organization_id)
            ->where('store_id', $cart->store_id)
            ->where('source', SalesOrderSource::Pos->value)
            ->whereKey($cart->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== SalesOrderStatus::Draft || $locked->pos_converted_at !== null) {
            throw ValidationException::withMessages(['order' => 'Ce panier n’est plus modifiable (vente finalisée, annulée ou transformée en commande).']);
        }

        return $locked;
    }

    private function cartLine(SalesOrder $cart, SalesOrderLine $line): SalesOrderLine
    {
        return SalesOrderLine::query()
            ->where('organization_id', $cart->organization_id)
            ->where('sales_order_id', $cart->getKey())
            ->whereKey($line->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
