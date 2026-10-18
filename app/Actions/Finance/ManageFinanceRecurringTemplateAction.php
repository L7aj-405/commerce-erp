<?php

namespace App\Actions\Finance;

use App\Enums\FinanceRecurringDueDayMode;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceNumberGenerator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageFinanceRecurringTemplateAction
{
    public function __construct(
        private readonly FinanceNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FinanceRecurringObligationTemplate
    {
        $this->authorize($actor, $organization);

        return DB::transaction(function () use ($actor, $organization, $data) {
            $this->assertReferences($organization, $data);
            $this->assertDatesAndDueRule($data);
            $template = new FinanceRecurringObligationTemplate;
            $template->organization_id = $organization->getKey();
            $template->template_number = $this->numbers->nextRecurringTemplate($organization, (int) Carbon::parse($data['start_date'])->format('Y'));
            $template->created_by_user_id = $actor->getKey();
            $template->active = (bool) ($data['active'] ?? true);
            $this->apply($template, $data);
            $template->save();
            $this->audit->record('finance_recurring_template.created', $actor, $organization, $template->store, $template, newValues: $this->auditValues($template));

            return $template;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, FinanceRecurringObligationTemplate $template, array $data): FinanceRecurringObligationTemplate
    {
        $this->authorize($actor, $template->organization);

        return DB::transaction(function () use ($actor, $template, $data) {
            $template = FinanceRecurringObligationTemplate::query()
                ->where('organization_id', $template->organization_id)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            $this->assertReferences($template->organization, $data);
            $this->assertDatesAndDueRule($data);
            $old = $this->auditValues($template);
            $this->apply($template, $data);
            $template->save();
            $this->audit->record('finance_recurring_template.updated', $actor, $template->organization, $template->store, $template, $old, $this->auditValues($template));

            return $template;
        }, 3);
    }

    public function deactivate(User $actor, FinanceRecurringObligationTemplate $template): FinanceRecurringObligationTemplate
    {
        return $this->setActive($actor, $template, false);
    }

    public function reactivate(User $actor, FinanceRecurringObligationTemplate $template): FinanceRecurringObligationTemplate
    {
        return $this->setActive($actor, $template, true);
    }

    private function setActive(User $actor, FinanceRecurringObligationTemplate $template, bool $active): FinanceRecurringObligationTemplate
    {
        $this->authorize($actor, $template->organization);

        return DB::transaction(function () use ($actor, $template, $active) {
            $template = FinanceRecurringObligationTemplate::query()
                ->where('organization_id', $template->organization_id)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            if ($template->active === $active) {
                return $template;
            }
            $template->active = $active;
            $template->deactivated_at = $active ? null : now();
            $template->deactivated_by_user_id = $active ? null : $actor->getKey();
            $template->save();
            $this->audit->record(
                $active ? 'finance_recurring_template.reactivated' : 'finance_recurring_template.deactivated',
                $actor, $template->organization, $template->store, $template,
                newValues: ['active' => $active, 'template_number' => $template->template_number],
            );

            return $template;
        }, 3);
    }

    private function authorize(User $actor, Organization $organization): void
    {
        abort_unless($organization->status === 'active' && $actor->active_organization_id === $organization->getKey()
            && $actor->hasPermission($organization, 'finance.monthly_situation.manage'), 403);
    }

    /** @param array<string, mixed> $data */
    private function assertReferences(Organization $organization, array $data): void
    {
        foreach ([
            ['key' => 'store_id', 'model' => Store::class],
            ['key' => 'supplier_id', 'model' => Supplier::class],
            ['key' => 'preferred_financial_account_id', 'model' => FinancialAccount::class],
        ] as $reference) {
            $id = $data[$reference['key']] ?? null;
            if ($id !== null && ! $reference['model']::query()->where('organization_id', $organization->getKey())->whereKey($id)->exists()) {
                throw ValidationException::withMessages([$reference['key'] => 'La référence sélectionnée n’appartient pas à cette organisation.']);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function assertDatesAndDueRule(array $data): void
    {
        $start = Carbon::parse($data['start_date']);
        if (! empty($data['end_date']) && Carbon::parse($data['end_date'])->lt($start)) {
            throw ValidationException::withMessages(['end_date' => 'La date de fin doit être postérieure ou égale à la date de début.']);
        }
        $mode = FinanceRecurringDueDayMode::from($data['due_day_mode']);
        $day = $data['due_day'] ?? null;
        if ($mode === FinanceRecurringDueDayMode::Fixed && (! is_numeric($day) || (int) $day < 1 || (int) $day > 28)) {
            throw ValidationException::withMessages(['due_day' => 'Le jour fixe doit être compris entre 1 et 28.']);
        }
        if ($mode === FinanceRecurringDueDayMode::EndOfMonth && $day !== null && $day !== '') {
            throw ValidationException::withMessages(['due_day' => 'Aucun jour fixe ne doit être fourni pour une échéance en fin de mois.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function apply(FinanceRecurringObligationTemplate $template, array $data): void
    {
        $template->store_id = $data['store_id'] ?? null;
        $template->category = $data['category'];
        $template->label = trim($data['label']);
        $template->supplier_id = $data['supplier_id'] ?? null;
        $template->beneficiary_name = ($data['beneficiary_name'] ?? null) ?: null;
        $template->reference = ($data['reference'] ?? null) ?: null;
        $template->planned_amount = Decimal::positive($data['planned_amount'], 'planned_amount');
        $template->currency_code = strtoupper($data['currency_code']);
        $template->planned_payment_method = $data['planned_payment_method'] ?? null;
        $template->preferred_financial_account_id = $data['preferred_financial_account_id'] ?? null;
        $template->due_day_mode = $data['due_day_mode'];
        $template->due_day = $data['due_day_mode'] === FinanceRecurringDueDayMode::Fixed->value ? (int) $data['due_day'] : null;
        $template->start_date = $data['start_date'];
        $template->end_date = ($data['end_date'] ?? null) ?: null;
        $template->notes = ($data['notes'] ?? null) ?: null;
    }

    /** @return array<string, mixed> */
    private function auditValues(FinanceRecurringObligationTemplate $template): array
    {
        return $template->only(['template_number', 'category', 'label', 'store_id', 'supplier_id', 'beneficiary_name', 'reference', 'planned_amount', 'currency_code', 'planned_payment_method', 'preferred_financial_account_id', 'due_day_mode', 'due_day', 'start_date', 'end_date', 'active']);
    }
}
