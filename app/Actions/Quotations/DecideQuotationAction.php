<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\AuthorizesQuotationAction;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record the customer's decision on an issued Devis: Accepté or Refusé.
 * Both are reversible between each other while the Devis has not been converted.
 */
class DecideQuotationAction
{
    use AuthorizesQuotationAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function accept(User $actor, Quotation $quotation): Quotation
    {
        return $this->decide($actor, $quotation, QuotationStatus::Accepted, null);
    }

    public function reject(User $actor, Quotation $quotation, ?string $reason): Quotation
    {
        return $this->decide($actor, $quotation, QuotationStatus::Rejected, $reason);
    }

    private function decide(User $actor, Quotation $quotation, QuotationStatus $target, ?string $reason): Quotation
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.accept');

        return DB::transaction(function () use ($actor, $quotation, $target, $reason) {
            $quotation = Quotation::query()
                ->where('organization_id', $quotation->organization_id)
                ->where('store_id', $quotation->store_id)
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->with(['organization', 'store'])
                ->firstOrFail();

            if (! in_array($quotation->status, [QuotationStatus::Issued, QuotationStatus::Expired, QuotationStatus::Accepted, QuotationStatus::Rejected], true)) {
                throw ValidationException::withMessages(['quotation' => 'Seul un devis émis peut être accepté ou refusé.']);
            }
            if ($quotation->status === QuotationStatus::Converted) {
                throw ValidationException::withMessages(['quotation' => 'Un devis transformé en commande ne peut plus changer de décision.']);
            }

            $quotation->status = $target;
            if ($target === QuotationStatus::Accepted) {
                $quotation->accepted_at = now();
                $quotation->accepted_by_user_id = $actor->getKey();
                $quotation->rejected_at = null;
                $quotation->rejected_by_user_id = null;
                $quotation->rejection_reason = null;
            } else {
                $quotation->rejected_at = now();
                $quotation->rejected_by_user_id = $actor->getKey();
                $quotation->rejection_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
                $quotation->accepted_at = null;
                $quotation->accepted_by_user_id = null;
            }
            $quotation->save();

            $this->audit->record(
                $target === QuotationStatus::Accepted ? 'quotation.accepted' : 'quotation.rejected',
                $actor, $quotation->organization, $quotation->store, $quotation,
                newValues: ['status' => $target->value, 'reason' => $quotation->rejection_reason],
            );

            return $quotation;
        });
    }
}
