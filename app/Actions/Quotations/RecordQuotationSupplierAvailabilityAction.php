<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record what the supplier answered for one Devis availability request. The
 * status is derived from the confirmed quantity versus the requested quantity:
 * full → available, some → partial, none → unavailable.
 */
class RecordQuotationSupplierAvailabilityAction
{
    use MutatesQuotation;

    public function __construct(private readonly AuditLogger $audit) {}

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

            if (! $request->status->isResolvable()) {
                throw ValidationException::withMessages(['status' => 'Cette demande ne peut plus être modifiée.']);
            }

            if ($data['outcome'] === 'unavailable') {
                $confirmed = '0.0000';
            } else {
                $confirmed = filled($data['confirmed_quantity'] ?? null)
                    ? Decimal::nonNegative((string) $data['confirmed_quantity'], 'confirmed_quantity')
                    : Decimal::normalize((string) $request->requested_quantity);
            }

            $status = match (true) {
                Decimal::compare($confirmed, '0') <= 0 => Status::Unavailable,
                Decimal::compare($confirmed, (string) $request->requested_quantity) >= 0 => Status::Available,
                default => Status::Partial,
            };

            $previous = $request->status->value;
            $request->status = $status;
            $request->confirmed_quantity = $confirmed;
            $request->resolved_by_user_id = $actor->getKey();
            $request->resolved_at = now();
            if (array_key_exists('note', $data)) {
                $request->note = filled($data['note']) ? trim((string) $data['note']) : $request->note;
            }
            $request->save();

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
