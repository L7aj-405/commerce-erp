<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Procurement\SupplierAvailabilityRequestRules;
use App\Services\QuotationSourcing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record, inside the ERP only, that supplier availability must be checked for
 * one draft Devis line. Sends nothing, orders nothing, reserves nothing.
 */
class CreateQuotationSupplierRequestAction
{
    use MutatesQuotation;

    public function __construct(
        private readonly QuotationSourcing $sourcing,
        private readonly SupplierAvailabilityRequestRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{supplier_id: int|string, requested_quantity?: string|int|float|null, note?: string|null} $data */
    public function execute(User $actor, Quotation $quotation, QuotationLine $line, array $data): QuotationSupplierAvailabilityRequest
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.update');
        abort_unless($actor->hasPermission($quotation->organization, 'suppliers.view'), 403);

        return DB::transaction(function () use ($actor, $quotation, $line, $data) {
            $quotation = $this->lockDraft($quotation);
            $line = $this->lockLine($quotation, $line);

            $evaluation = $this->sourcing->evaluate($quotation, lockRequests: true)[$line->getKey()];
            if (! $evaluation['requires_sourcing']) {
                throw ValidationException::withMessages(['line' => 'Cette ligne est entièrement couverte par le stock interne.']);
            }

            $supplier = $this->rules->activeSupplier((int) $quotation->organization_id, $data['supplier_id']);
            $quantity = $this->rules->requestedQuantity($data, $evaluation['shortage'], (string) $line->quantity);
            $this->rules->assertNoPendingDuplicate(
                QuotationSupplierAvailabilityRequest::query()
                    ->where('organization_id', $quotation->organization_id)
                    ->where('quotation_line_id', $line->getKey())
                    ->where('supplier_id', $supplier->getKey())
                    ->get(),
                $supplier,
                $line->product_variant_id,
                $line->non_stock_item_id,
            );

            $request = $this->rules->newPendingRequest($actor, [
                'organization_id' => $quotation->organization_id,
                'store_id' => $quotation->store_id,
                'quotation_id' => $quotation->getKey(),
                'quotation_line_id' => $line->getKey(),
            ], $supplier, $line->product_variant_id, $line->non_stock_item_id, $quantity, $data['note'] ?? null);

            $this->audit->record('quotation_supplier_availability.requested', $actor, $quotation->organization, $quotation->store, $request, newValues: [
                'quotation_id' => $quotation->getKey(),
                'quotation_line_id' => $line->getKey(),
                'supplier_id' => $supplier->getKey(),
                'requested_quantity' => $request->requested_quantity,
                'shortage' => $evaluation['shortage'],
            ]);

            return $request;
        });
    }
}
