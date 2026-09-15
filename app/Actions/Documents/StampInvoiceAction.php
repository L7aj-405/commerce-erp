<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\InvoiceStatus;
use App\Models\DocumentStampApposition;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStampApposer;
use Illuminate\Validation\ValidationException;

/**
 * Explicit "Apposer le cachet" action for an Invoice. Separate from the
 * organization's stamp CONFIGURATION — configuring a stamp never stamps any
 * document by itself (see DocumentStampApposer / the migration docblock).
 * Only an Issued Invoice is eligible: a Draft is not an official document in
 * this architecture, and a Superseded original keeps whatever apposition it
 * already had (a correction is a new row with no apposition of its own —
 * see StartInvoiceCorrectionAction).
 */
class StampInvoiceAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DocumentStampApposer $apposer,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Invoice $invoice): DocumentStampApposition
    {
        $this->authorizeInvoice($actor, $invoice, 'documents.stamp');

        if ($invoice->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages(['stamp' => 'Seule une facture émise peut être cachetée.']);
        }

        $apposition = $this->apposer->apply($invoice->organization, $invoice, $actor->getKey());

        $this->audit->record('invoice.stamped', $actor, $invoice->organization, $invoice->store, $invoice, newValues: [
            'organization_document_stamp_id' => $apposition->organization_document_stamp_id,
        ]);

        return $apposition;
    }
}
