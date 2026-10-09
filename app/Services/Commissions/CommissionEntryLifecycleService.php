<?php

namespace App\Services\Commissions;

use App\Models\CommissionEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\CommissionPayoutNotifier;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommissionEntryLifecycleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommissionPayoutNotifier $notifier,
    ) {}

    /** @param list<int> $ids @return array{count:int,amount:string} */
    public function approve(User $actor, Organization $organization, array $ids): array
    {
        return $this->transition($actor, $organization, $ids, CommissionEntry::STATUS_PENDING, CommissionEntry::STATUS_APPROVED);
    }

    /** @param list<int> $ids @return array{count:int,amount:string} */
    public function markPaid(User $actor, Organization $organization, array $ids): array
    {
        return $this->transition($actor, $organization, $ids, CommissionEntry::STATUS_APPROVED, CommissionEntry::STATUS_PAID);
    }

    /** @param list<int> $ids @return array{count:int,amount:string} */
    private function transition(User $actor, Organization $organization, array $ids, string $from, string $to): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw ValidationException::withMessages(['entries' => 'Sélectionnez au moins une écriture.']);
        }

        $batch = DB::transaction(function () use ($actor, $organization, $ids, $from, $to) {
            $entries = CommissionEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($entries->count() !== count($ids)) {
                abort(404);
            }
            if ($entries->contains(fn (CommissionEntry $entry) => $entry->status !== $from)) {
                throw ValidationException::withMessages([
                    'entries' => $from === CommissionEntry::STATUS_PENDING
                        ? 'Seules les écritures en attente peuvent être approuvées.'
                        : 'Seules les écritures approuvées peuvent être marquées comme payées.',
                ]);
            }

            $amount = '0.0000';
            foreach ($entries as $entry) {
                $entry->status = $to;
                if ($to === CommissionEntry::STATUS_APPROVED) {
                    $entry->approved_by_user_id = $actor->getKey();
                    $entry->approved_at = now();
                } else {
                    $entry->paid_by_user_id = $actor->getKey();
                    $entry->paid_at = now();
                }
                $entry->save();
                $amount = Decimal::add($amount, $entry->commission_amount);
            }

            $log = $this->audit->record(
                $to === CommissionEntry::STATUS_APPROVED ? 'commission.entry_approved' : 'commission.entry_paid',
                $actor,
                $organization,
                newValues: ['entry_ids' => $ids, 'count' => count($ids), 'amount' => $amount],
            );

            return ['count' => count($ids), 'amount' => $amount, 'entries' => $entries, 'batch_id' => $log->getKey()];
        });

        // Published only after the state transition committed: one aggregate
        // per salesperson per batch, never one notification per entry.
        $this->notifier->batch($organization, $batch['entries'], $to, $batch['batch_id']);

        return ['count' => $batch['count'], 'amount' => $batch['amount']];
    }
}
