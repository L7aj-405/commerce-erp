<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\QuotationDocumentSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open a revision of an already-issued Devis after customer feedback.
 *
 * The issued Devis is NEVER mutated. A new draft Devis is created from its
 * immutable snapshots — customer identity, seller snapshot, representative,
 * notes, terms and every QuotationLine (quantity / PU / discount / tax /
 * totals basis) — and linked into the same commercial proposal chain via
 * `root_quotation_id` + `revised_from_quotation_id` + `revision_number`.
 *
 * Current Product prices are NOT consulted: the quote basis is copied verbatim.
 * The employee may afterwards explicitly change a product, price, quantity,
 * discount, add/remove lines, or edit the client / validity / notes through the
 * normal draft editor. IssueQuotationAction then allocates `DEV-N/YYYY-R{n}`
 * (never a fresh sequence value) and supersedes the version it replaces.
 *
 * Distinct from DuplicateQuotationAction, which produces an unrelated new
 * commercial Devis and re-freezes the seller snapshot from CURRENT settings.
 */
class StartQuotationRevisionAction
{
    use MutatesQuotation;

    public function __construct(
        private readonly QuotationDocumentSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Quotation $source, string $reason): Quotation
    {
        $this->authorizeQuotation($actor, $source, 'quotations.issue');

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Un motif de révision est requis.']);
        }

        return DB::transaction(function () use ($actor, $source, $reason) {
            $source = Quotation::query()
                ->where('organization_id', $source->organization_id)
                ->where('store_id', $source->store_id)
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->with(['lines', 'organization', 'store'])
                ->firstOrFail();

            if (! $source->status->isCurrentEligible()) {
                throw ValidationException::withMessages([
                    'quotation' => 'Seule la version courante d’un devis émis peut être révisée.',
                ]);
            }

            $rootId = $source->chainRootId();

            $activeRevisionExists = Quotation::query()
                ->where('organization_id', $source->organization_id)
                ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_quotation_id', $rootId))
                ->where('status', QuotationStatus::Draft->value)
                ->where('revision_number', '>', 0)
                ->exists();
            if ($activeRevisionExists) {
                throw ValidationException::withMessages(['quotation' => 'Une révision est déjà en cours pour ce devis.']);
            }

            $devisSettings = $this->settings->settings($source->organization);
            $today = now()->toDateString();

            $revision = $source->replicate([
                'quotation_number', 'status', 'issued_at', 'issued_by_user_id',
                'accepted_at', 'accepted_by_user_id', 'rejected_at', 'rejected_by_user_id', 'rejection_reason',
                'converted_at', 'converted_sales_order_id',
                'root_quotation_id', 'revised_from_quotation_id', 'revision_number', 'revision_reason',
            ]);
            $revision->status = QuotationStatus::Draft;
            $revision->quotation_number = null;
            $revision->quotation_date = $today;
            $revision->valid_until = Carbon::parse($today)
                ->addDays($devisSettings['default_validity_days'])->toDateString();
            // The revision carries the ORIGINAL immutable seller snapshot forward —
            // it is never re-frozen from current Settings (that is what Dupliquer
            // does). An old issued document must keep rendering from its snapshot.
            $revision->seller_snapshot = $source->seller_snapshot;
            $revision->root_quotation_id = $rootId;
            $revision->revised_from_quotation_id = $source->getKey();
            $revision->revision_number = (int) $source->revision_number + 1;
            $revision->revision_reason = $reason;
            $revision->created_by_user_id = $actor->getKey();
            $revision->save();

            foreach ($source->lines as $line) {
                $copy = $line->replicate(['quotation_id']);
                $copy->quotation_id = $revision->getKey();
                $copy->save();
            }

            // Copied lines already sum to the source totals; reconcile defensively
            // so the aggregates are provably derived from the persisted lines.
            $this->reconcileTotals($revision);

            $this->audit->record('quotation.revision_started', $actor, $source->organization, $source->store, $revision, newValues: [
                'root_quotation_id' => $rootId,
                'revised_from_quotation_id' => $source->getKey(),
                'source_quotation_number' => $source->quotation_number,
                'revision_number' => $revision->revision_number,
                'reason' => $reason,
            ]);

            return $revision->load(['lines', 'customer']);
        });
    }
}
