<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Procurement\SupplierAvailabilityRequestRules;
use Illuminate\Support\Facades\DB;

/**
 * Record what the supplier answered for one Devis availability request. The
 * status is derived from the confirmed quantity versus the requested quantity:
 * full → available, some → partial, none → unavailable.
 */
class RecordQuotationSupplierAvailabilityAction
{
    use MutatesQuotation;

    public function __construct(
        private readonly SupplierAvailabilityRequestRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{outcome: string, confirmed_quantity?: string|int|float|null, note?: string|null} $data */
    public function execute(User $actor, Quotation $quotation, QuotationSupplierAvailabilityRequest $request, array $data): QuotationSupplierAvailabilityRequest
    {
        $this->authorizeQuotation($actor, $quotation, 'procurement.manage');

        return DB::transaction(function () use ($actor, $quotation, $request, $data) {
            $quotation = $this->lockDraft($quotation);
            $request = QuotationSupplierAvailabilityRequest::query()
                ->where('organization_id', $quotation->organization_id)
                ->where('quotation_id', $quotation->getKey())
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $this->rules->recordOutcome($actor, $request, $data);
            $status = $request->status;
            $confirmed = (string) $request->confirmed_quantity;

            $this->audit->record('quotation_supplier_availability.recorded', $actor, $quotation->organization, $quotation->store, $request, oldValues: [
                'status' => $previous,
            ], newValues: [
                'quotation_id' => $quotation->getKey(),
                'quotation_line_id' => $request->quotation_line_id,
                'supplier_id' => $request->supplier_id,
                'status' => $status->value,
                'requested_quantity' => $request->requested_quantity,
                'confirmed_quantity' => $confirmed,
            ]);

            return $request;
        });
    }
}
