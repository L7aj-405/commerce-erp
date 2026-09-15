<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\AuthorizesQuotationAction;
use App\Enums\QuotationStatus;
use App\Models\DocumentStampApposition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStampApposer;
use Illuminate\Validation\ValidationException;

/**
 * Explicit "Apposer le cachet" action for a Devis. Only the current, official
 * (issued-or-later, non-superseded) version is eligible — a revision is a new
 * row with no apposition of its own (see StartQuotationRevisionAction), and a
 * superseded revision keeps whatever apposition it already had.
 */
class StampQuotationAction
{
    use AuthorizesQuotationAction;

    public function __construct(
        private readonly DocumentStampApposer $apposer,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Quotation $quotation): DocumentStampApposition
    {
        $this->authorizeQuotation($actor, $quotation, 'documents.stamp');

        if (! $quotation->status->isOfficial() || $quotation->status === QuotationStatus::Superseded) {
            throw ValidationException::withMessages(['stamp' => 'Seul un devis émis et courant peut être cacheté.']);
        }

        $apposition = $this->apposer->apply($quotation->organization, $quotation, $actor->getKey());

        $this->audit->record('quotation.stamped', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
            'organization_document_stamp_id' => $apposition->organization_document_stamp_id,
        ]);

        return $apposition;
    }
}
