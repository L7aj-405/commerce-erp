<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelQuotationSupplierRequestAction
{
    use MutatesQuotation;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Quotation $quotation, QuotationSupplierAvailabilityRequest $request): QuotationSupplierAvailabilityRequest
    {
        // Either the Devis editor or the supplier owner may withdraw a request.
        $permission = $actor->hasPermission($quotation->organization, 'quotations.update') ? 'quotations.update' : 'procurement.manage';
        $this->authorizeQuotation($actor, $quotation, $permission);

        return DB::transaction(function () use ($actor, $quotation, $request) {
            $quotation = $this->lockDraft($quotation);
            $request = QuotationSupplierAvailabilityRequest::query()
                ->where('organization_id', $quotation->organization_id)
                ->where('quotation_id', $quotation->getKey())
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === Status::Cancelled) {
                throw ValidationException::withMessages(['status' => 'Cette demande est déjà annulée.']);
            }

            $previous = $request->status->value;
            $request->status = Status::Cancelled;
            $request->resolved_by_user_id = $actor->getKey();
            $request->resolved_at = now();
            $request->save();

            $this->audit->record('quotation_supplier_availability.cancelled', $actor, $quotation->organization, $quotation->store, $request, oldValues: [
                'status' => $previous,
            ], newValues: [
                'quotation_id' => $quotation->getKey(),
                'quotation_line_id' => $request->quotation_line_id,
                'supplier_id' => $request->supplier_id,
                'status' => Status::Cancelled->value,
            ]);

            return $request;
        });
    }
}
