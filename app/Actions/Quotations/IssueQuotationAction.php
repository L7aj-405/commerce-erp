<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentTemplateRegistry;
use App\Services\QuotationNumberGenerator;
use App\Services\QuotationSourcing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueQuotationAction
{
    use MutatesQuotation;

    public function __construct(
        private readonly QuotationNumberGenerator $numbers,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentTemplateRegistry $templates,
        private readonly AuditLogger $audit,
        private readonly QuotationSourcing $sourcing,
    ) {}

    /**
     * @param  bool  $confirmSupplierAvailability  "Confirmer la disponibilité et émettre":
     *                                             the actor attests supplier availability for every unresolved
     *                                             sourcing line. Requires quotations.override_supplier_availability.
     */
    public function execute(User $actor, Quotation $quotation, bool $confirmSupplierAvailability = false): Quotation
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.issue');
        if ($confirmSupplierAvailability) {
            abort_unless($actor->hasPermission($quotation->organization, 'quotations.override_supplier_availability'), 403);
        }

        return DB::transaction(function () use ($actor, $quotation, $confirmSupplierAvailability) {
            $quotation = $this->lockDraft($quotation);
            $this->assertHasLines($quotation);
            $quotation->seller_snapshot = $this->sellerProfile->freezeCurrentPresentationSettings(
                $quotation->seller_snapshot,
                $quotation->organization,
            );
            $this->sellerProfile->validate($quotation->seller_snapshot);
            $this->templates->quotationView($quotation->template_version);

            if ($quotation->isPastValidity()) {
                throw ValidationException::withMessages(['valid_until' => 'La date de validité doit être postérieure à aujourd’hui.']);
            }

            // Sourcing gate: every line not fully covered by internal stock (or a
            // non-stock article) needs confirmed supplier availability for its
            // CURRENT shortage. The quotation row lock above serializes this with
            // every request mutation, which all lock the same draft first.
            $unresolved = $this->sourcing->unresolved($this->sourcing->evaluate($quotation, lockRequests: true));
            $manuallyConfirmed = [];
            if ($unresolved !== []) {
                if (! $confirmSupplierAvailability) {
                    throw ValidationException::withMessages([
                        'sourcing' => count($unresolved) === 1
                            ? 'Une ligne nécessite une confirmation de disponibilité fournisseur avant l’émission.'
                            : count($unresolved).' lignes nécessitent une confirmation de disponibilité fournisseur avant l’émission.',
                    ]);
                }
                $manuallyConfirmed = $this->sourcing->confirmManually($actor, $quotation, $unresolved);
            }

            $isRevision = $quotation->isRevision();

            if ($isRevision) {
                // A revision stays inside the same commercial proposal: it takes
                // the root number with an -R{n} suffix and never consumes a fresh
                // annual sequence value.
                $root = Quotation::query()
                    ->where('organization_id', $quotation->organization_id)
                    ->whereKey($quotation->root_quotation_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $quotation->quotation_number = $root->quotation_number.'-R'.$quotation->revision_number;
            } else {
                // Allocate the dedicated annual Devis number transactionally.
                $quotation->quotation_number = $this->numbers->next($quotation->organization, (int) $quotation->quotation_date->format('Y'));
            }

            $quotation->status = QuotationStatus::Issued;
            $quotation->issued_at = now();
            $quotation->issued_by_user_id = $actor->getKey();
            $quotation->save();

            $this->audit->record('quotation.issued', $actor, $quotation->organization, $quotation->store, $quotation, oldValues: ['status' => QuotationStatus::Draft->value], newValues: [
                'quotation_number' => $quotation->quotation_number,
                'status' => QuotationStatus::Issued->value,
                'total_incl_tax' => $quotation->total_incl_tax,
                'valid_until' => $quotation->valid_until?->toDateString(),
                'revision_number' => $quotation->revision_number,
            ] + ($manuallyConfirmed !== [] ? ['supplier_availability_manually_confirmed_requests' => $manuallyConfirmed] : []));

            if ($isRevision) {
                // Issuing a revision supersedes the version it replaces, in the
                // same transaction. The predecessor stays immutable and viewable.
                $predecessor = Quotation::query()
                    ->where('organization_id', $quotation->organization_id)
                    ->whereKey($quotation->revised_from_quotation_id)
                    ->lockForUpdate()
                    ->first();
                if ($predecessor && in_array($predecessor->status, [
                    QuotationStatus::Issued, QuotationStatus::Accepted,
                    QuotationStatus::Rejected, QuotationStatus::Expired,
                ], true)) {
                    $predecessor->status = QuotationStatus::Superseded;
                    $predecessor->save();
                }

                $this->audit->record('quotation.revision_issued', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                    'root_quotation_id' => $quotation->root_quotation_id,
                    'revision_quotation_id' => $quotation->getKey(),
                    'revision_quotation_number' => $quotation->quotation_number,
                    'revision_number' => $quotation->revision_number,
                    'superseded_quotation_id' => $predecessor?->getKey(),
                    'superseded_quotation_number' => $predecessor?->quotation_number,
                    'reason' => $quotation->revision_reason,
                ]);
            }

            return $quotation->fresh(['lines', 'customer']);
        });
    }
}
