<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Models\SalesOrderProcurement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * RETIRED. A procurement requirement used to carry its own supplier
 * availability answer (pending / confirmed / unavailable), and a "confirmed"
 * answer counted as coverage for Sales Order confirmation.
 *
 * Supplier availability is now only pre-sale EVIDENCE — Devis and POS cart
 * "Demandes de disponibilité" (quotation_supplier_availability_requests). Once
 * the customer has committed, a requirement is a purchasing need ("À
 * commander"): it is ordered through a purchase order, or cancelled. Kept so
 * old clients get an explicit, safe refusal instead of a silent state change.
 */
class RecordSupplierAvailabilityAction
{
    use AuthorizesProcurementAction;

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrderProcurement $procurement, array $data): SalesOrderProcurement
    {
        $this->authorizeProcurement($actor, $procurement->organization, 'procurement.manage');

        throw ValidationException::withMessages([
            'supplier_availability_status' => 'La disponibilité fournisseur se vérifie avant l’engagement du client (devis ou panier). Ce besoin d’approvisionnement se commande via une commande fournisseur, ou s’annule.',
        ]);
    }
}
