<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\AuthorizesQuotationAction;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\QuotationDocumentSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clone any Devis into a fresh editable draft: copies the customer snapshot,
 * representative, notes, terms and every line, but re-freezes the seller
 * snapshot from CURRENT settings and re-computes the validity window. The new
 * draft has no number until it is issued.
 */
class DuplicateQuotationAction
{
    use AuthorizesQuotationAction;

    public function __construct(
        private readonly QuotationDocumentSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Quotation $source): Quotation
    {
        $this->authorizeQuotation($actor, $source, 'quotations.create');

        return DB::transaction(function () use ($actor, $source) {
            $source->loadMissing('lines');
            $devisSettings = $this->settings->settings($source->organization);
            $today = now()->toDateString();

            $copy = $source->replicate([
                'quotation_number', 'status', 'seller_snapshot', 'issued_at', 'issued_by_user_id',
                'accepted_at', 'accepted_by_user_id', 'rejected_at', 'rejected_by_user_id', 'rejection_reason',
                'converted_at', 'converted_sales_order_id', 'created_by_user_id',
            ]);
            $copy->status = QuotationStatus::Draft;
            $copy->quotation_number = null;
            $copy->quotation_date = $today;
            $copy->valid_until = Carbon::parse($today)->addDays($devisSettings['default_validity_days'])->toDateString();
            $copy->seller_snapshot = $this->settings->snapshot($source->organization, $source->store);
            $copy->created_by_user_id = $actor->getKey();
            $copy->save();

            foreach ($source->lines as $line) {
                $lineCopy = $line->replicate(['quotation_id']);
                $lineCopy->quotation_id = $copy->getKey();
                $lineCopy->save();
            }

            $this->audit->record('quotation.created', $actor, $source->organization, $source->store, $copy, newValues: [
                'duplicated_from' => $source->getKey(),
                'duplicated_from_number' => $source->quotation_number,
                'status' => QuotationStatus::Draft->value,
            ]);

            return $copy->load(['lines', 'customer']);
        });
    }
}
