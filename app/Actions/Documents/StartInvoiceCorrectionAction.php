<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Kept as a fail-closed compatibility boundary for callers of the former
 * Invoice-only correction flow. Commercial correction now starts from the
 * SalesOrder so Inventory, payments and document snapshots stay aligned.
 */
class StartInvoiceCorrectionAction
{
    use AuthorizesDocumentAction;

    public function execute(User $actor, Invoice $original, string $reason): Invoice
    {
        $this->authorizeInvoice($actor, $original, 'invoices.issue');

        throw ValidationException::withMessages([
            'invoice' => 'Les corrections financières doivent être effectuées depuis la commande commerciale.',
        ]);
    }
}
