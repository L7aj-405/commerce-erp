<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Services\Sandbox\SandboxContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closed financial periods (Sprint 0 / WS2).
 *
 * A month closed in "Situation fin de mois" (finance_month_reviews.status =
 * closed) refuses every financial posting or change whose BUSINESS date falls
 * inside it: customer payments and their reversal, refunds, outgoing payments
 * and their reversal, obligations (create / update — old and new due date —
 * / cancel), supplier installment schedules and payment instruments.
 *
 * The business date is the operation's own calendar date (payment_date,
 * refund_date, due_date, maturity_date) — a `Y-m-d` calendar value already
 * expressed in the organization's business timezone, never a UTC instant.
 *
 * Correction path: reopen the month (ManageFinanceMonthReviewAction::reopen,
 * mandatory reason, audited), correct, close it again (a new close snapshot
 * is taken). There is deliberately no silent override.
 *
 * Concurrency: the period row is read with a SHARED lock inside the caller's
 * transaction. Closing takes an exclusive lock on the same row (or inserts it
 * under the (organization_id, period) unique key, which the shared locking
 * read's gap lock blocks on MySQL/InnoDB), so a posting and a close of the
 * same month serialize: either the posting commits first and is part of the
 * closed month, or it sees the month closed and is refused.
 */
class FinanceClosedPeriodGuard
{
    /**
     * @param  iterable<CarbonInterface|string|null>|CarbonInterface|string|null  $dates
     */
    public function assertOpen(Organization|int $organization, iterable|CarbonInterface|string|null $dates, string $field = 'date'): void
    {
        // Month reviews describe PRODUCTION books only. A Test Mode request writes
        // to the sandbox ledgers, which belong to no Production month: it neither
        // reads nor is blocked by Production closings.
        if (app(SandboxContext::class)->isSandbox()) {
            return;
        }
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $months = [];
        foreach (is_iterable($dates) ? $dates : [$dates] as $date) {
            if ($date === null || $date === '') {
                continue;
            }
            $day = $date instanceof CarbonInterface ? $date->format('Y-m-d') : Carbon::parse($date)->format('Y-m-d');
            $months[substr($day, 0, 7)] ??= $day;
        }
        ksort($months);

        foreach ($months as $month => $day) {
            if ($this->isClosed($organizationId, $month)) {
                $label = FinancePeriod::fromMonth($month)->label();
                throw ValidationException::withMessages([$field => "La période {$label} est clôturée : aucune opération datée du "
                    .Carbon::parse($day)->format('d/m/Y').' ne peut être enregistrée, modifiée ou annulée. '
                    .'Pour corriger, réouvrez le mois dans « Situation fin de mois » (motif obligatoire, tracé dans l’audit), puis clôturez-le à nouveau.']);
            }
        }
    }

    public function isClosed(int $organizationId, string $month): bool
    {
        $query = DB::table('finance_month_reviews')
            ->where('organization_id', $organizationId)
            ->whereDate('period', $month.'-01');
        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }

        return $query->value('status') === 'closed';
    }
}
