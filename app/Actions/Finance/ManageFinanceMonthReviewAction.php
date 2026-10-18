<?php

namespace App\Actions\Finance;

use App\Models\FinanceMonthReview;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceMonthReviewService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageFinanceMonthReviewAction
{
    public function __construct(private readonly FinanceMonthReviewService $service, private readonly AuditLogger $audit) {}

    public function review(User $actor, Organization $organization, FinancePeriod $period, ?string $note): FinanceMonthReview
    {
        $this->authorize($actor, $organization);
        if ($period->start->gt(now()->startOfMonth())) throw ValidationException::withMessages(['month' => 'Un mois futur ne peut pas être vérifié.']);

        return DB::transaction(function () use ($actor, $organization, $period, $note) {
            $row = $this->locked($organization, $period);
            if ($row->status === 'closed') throw ValidationException::withMessages(['month' => 'Réouvrez le mois avant de le vérifier à nouveau.']);
            $row->status = 'reviewed'; $row->reviewed_at = now(); $row->reviewed_by_user_id = $actor->id; $row->note = $note; $row->save();
            $this->audit->record('finance_month.reviewed', $actor, $organization, auditable: $row,
                newValues: ['period' => $period->month, 'note' => $note]);
            return $row;
        });
    }

    public function close(User $actor, Organization $organization, FinancePeriod $period, ?string $note, bool $acknowledged): FinanceMonthReview
    {
        $this->authorize($actor, $organization);
        if (! $period->end->lt(now()->startOfDay())) throw ValidationException::withMessages(['month' => 'Seul un mois terminé peut être clôturé.']);
        $warnings = $this->service->warnings($organization, $period);
        if ($warnings !== [] && ! $acknowledged) throw ValidationException::withMessages(['acknowledge_warnings' => 'Confirmez avoir pris connaissance des avertissements avant la clôture.']);
        $snapshot = $this->service->snapshot($organization, $period);

        return DB::transaction(function () use ($actor, $organization, $period, $note, $warnings, $snapshot) {
            $row = $this->locked($organization, $period);
            if ($row->status === 'closed') throw ValidationException::withMessages(['month' => 'Ce mois est déjà clôturé.']);
            $row->status = 'closed'; $row->reviewed_at ??= now(); $row->reviewed_by_user_id ??= $actor->id;
            $row->closed_at = now(); $row->closed_by_user_id = $actor->id; $row->note = $note; $row->close_snapshot = $snapshot; $row->save();
            $this->audit->record('finance_month.closed', $actor, $organization, auditable: $row,
                newValues: ['period' => $period->month, 'note' => $note, 'warnings' => collect($warnings)->pluck('key')->all(), 'snapshot' => $snapshot]);
            return $row;
        });
    }

    public function reopen(User $actor, Organization $organization, FinancePeriod $period, string $reason): FinanceMonthReview
    {
        $this->authorize($actor, $organization);
        return DB::transaction(function () use ($actor, $organization, $period, $reason) {
            $row = $this->locked($organization, $period);
            if ($row->status !== 'closed') throw ValidationException::withMessages(['month' => 'Seul un mois clôturé peut être réouvert.']);
            $row->status = 'open'; $row->reopened_at = now(); $row->reopened_by_user_id = $actor->id; $row->note = $reason; $row->save();
            $this->audit->record('finance_month.reopened', $actor, $organization, auditable: $row,
                oldValues: ['status' => 'closed'], newValues: ['status' => 'open', 'period' => $period->month, 'reason' => $reason]);
            return $row;
        });
    }

    private function authorize(User $actor, Organization $organization): void
    {
        abort_unless($organization->status === 'active' && $actor->active_organization_id === $organization->id
            && $actor->hasPermission($organization, 'finance.monthly_situation.manage'), 403);
    }

    private function locked(Organization $organization, FinancePeriod $period): FinanceMonthReview
    {
        $row = FinanceMonthReview::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->whereDate('period', $period->start->toDateString())->lockForUpdate()->first();
        if ($row) return $row;
        $row = new FinanceMonthReview; $row->organization_id = $organization->id; $row->period = $period->start->toDateString(); $row->status = 'open'; $row->save();
        return $row;
    }
}
