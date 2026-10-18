<?php

namespace App\Actions\Finance;

use App\Enums\FinanceRecurringDueDayMode;
use App\Models\FinanceObligation;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateRecurringFinanceObligationsAction
{
    public function __construct(
        private readonly FinanceNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{created:int,existing:int,skipped:int} */
    public function execute(Organization $organization, string|Carbon $month, ?User $actor = null, ?FinanceRecurringObligationTemplate $only = null): array
    {
        if ($actor) {
            abort_unless($actor->active_organization_id === $organization->getKey()
                && $actor->hasPermission($organization, 'finance.monthly_situation.manage'), 403);
        }
        abort_unless($organization->status === 'active', 403);
        if ($only && $only->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $period = ($month instanceof Carbon ? $month->copy() : Carbon::createFromFormat('!Y-m', $month))->startOfMonth();
        $result = ['created' => 0, 'existing' => 0, 'skipped' => 0];
        $query = FinanceRecurringObligationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->when($only, fn ($query) => $query->whereKey($only->getKey()))
            ->when(! $only, fn ($query) => $query->where('active', true)
                ->whereDate('start_date', '<=', $period->copy()->endOfMonth()->toDateString())
                ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $period->toDateString())))
            ->orderBy('id');

        $query->chunkById(100, function ($templates) use ($organization, $period, $actor, &$result): void {
            foreach ($templates as $template) {
                $outcome = $this->generateOne($organization, $template, $period, $actor);
                $result[$outcome]++;
            }
        });

        return $result;
    }

    private function generateOne(Organization $organization, FinanceRecurringObligationTemplate $template, Carbon $period, ?User $actor): string
    {
        $dueDate = $this->dueDate($template, $period);
        if (! $template->active || $dueDate->lt($template->start_date)
            || $period->lt($template->start_date->copy()->startOfMonth())
            || ($template->end_date && $period->gt($template->end_date->copy()->startOfMonth()))) {
            return 'skipped';
        }
        if (FinanceObligation::query()->where('organization_id', $organization->getKey())
            ->where('recurring_template_id', $template->getKey())->whereDate('recurring_period', $period)->exists()) {
            return 'existing';
        }

        try {
            return DB::transaction(function () use ($organization, $template, $period, $dueDate, $actor) {
                $locked = FinanceRecurringObligationTemplate::query()
                    ->where('organization_id', $organization->getKey())->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->active) {
                    return 'skipped';
                }
                if (FinanceObligation::query()->where('organization_id', $organization->getKey())
                    ->where('recurring_template_id', $locked->getKey())->whereDate('recurring_period', $period)->exists()) {
                    return 'existing';
                }

                $obligation = new FinanceObligation;
                $obligation->organization_id = $organization->getKey();
                $obligation->recurring_template_id = $locked->getKey();
                $obligation->recurring_period = $period->toDateString();
                $obligation->obligation_number = $this->numbers->nextObligation($organization, (int) $dueDate->format('Y'));
                $obligation->store_id = $locked->store_id;
                $obligation->category = $locked->category;
                $obligation->label = $locked->label;
                $obligation->supplier_id = $locked->supplier_id;
                $obligation->beneficiary_name = $locked->beneficiary_name;
                $obligation->reference = $locked->reference;
                $obligation->due_date = $dueDate;
                $obligation->planned_amount = $locked->planned_amount;
                $obligation->currency_code = $locked->currency_code;
                $obligation->planned_payment_method = $locked->planned_payment_method;
                $obligation->preferred_financial_account_id = $locked->preferred_financial_account_id;
                $obligation->notes = $locked->notes;
                $obligation->created_by_user_id = $actor?->getKey();
                $obligation->save();

                $this->audit->record('finance_recurring_obligation.generated', $actor, $organization, $obligation->store, $obligation, newValues: [
                    'template_id' => $locked->getKey(), 'obligation_id' => $obligation->getKey(),
                    'period' => $period->format('Y-m'), 'amount' => $obligation->planned_amount,
                    'due_date' => $obligation->due_date->toDateString(),
                ]);

                return 'created';
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return 'existing';
        }
    }

    public function dueDate(FinanceRecurringObligationTemplate $template, Carbon $period): Carbon
    {
        return $template->due_day_mode === FinanceRecurringDueDayMode::EndOfMonth
            ? $period->copy()->endOfMonth()->startOfDay()
            : $period->copy()->day((int) $template->due_day)->startOfDay();
    }
}
