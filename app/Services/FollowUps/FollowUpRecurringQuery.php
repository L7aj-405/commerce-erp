<?php

namespace App\Services\FollowUps;

use App\Enums\FollowUpRecurringStatus;
use App\Models\FollowUpRecurringRule;
use App\Models\FollowUpTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** W3 canonical listing/presentation of recurring rules and templates (web now; MCP/API later). */
final class FollowUpRecurringQuery
{
    public function __construct(private readonly FollowUpAccess $access) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(FollowUpRecurringStatus::class)],
            'assigned_to' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function search(User $user, Organization $organization, array $filters): LengthAwarePaginator
    {
        $assignedTo = $filters['assigned_to'] ?? 'me';
        $query = $this->access->visibleRules($user, $organization)
            ->with(['assignee:id,name', 'customer:id,organization_id,display_name', 'quotation:id,organization_id,quotation_number,status'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status));

        if (! $this->access->canManageAll($user, $organization) || $assignedTo === 'me') {
            $query->where('assigned_user_id', $user->getKey());
        } elseif ($assignedTo === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif ($assignedTo !== 'all' && ctype_digit((string) $assignedTo)) {
            $query->where('assigned_user_id', (int) $assignedTo);
        }

        return $query->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('title')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
    }

    /** @return array<string, mixed> */
    public function present(User $user, Organization $organization, FollowUpRecurringRule $rule): array
    {
        $today = BusinessTime::today($organization);
        $active = $rule->status === FollowUpRecurringStatus::Active;
        // The held-ahead occurrence is the next one people will see; otherwise the cursor.
        $next = ! $active ? null : (($rule->last_generated_on !== null && $rule->last_generated_on >= $today) ? $rule->last_generated_on : $rule->next_occurrence_on);
        $customer = $rule->customer && $this->access->allows($user, $organization, 'customers.view') ? $rule->customer : null;
        $quotation = $rule->quotation && $this->access->allows($user, $organization, 'quotations.view') ? $rule->quotation : null;

        return [
            'id' => (int) $rule->getKey(),
            'title' => $rule->title,
            'note' => $rule->note,
            'priority' => $rule->priority->value,
            'frequency' => $rule->frequency->value,
            'interval_count' => (int) $rule->interval_count,
            'weekdays' => $rule->weekdays ?? [],
            'month_day' => $rule->month_day,
            'local_time' => $rule->local_time,
            'starts_on' => $rule->starts_on,
            'ends_on' => $rule->ends_on,
            'max_occurrences' => $rule->max_occurrences,
            'occurrences_generated' => (int) $rule->occurrences_generated,
            'status' => $rule->status->value,
            'status_reason' => $rule->status_reason,
            'next_occurrence_on' => $next,
            'assignee' => $rule->assignee ? ['id' => (int) $rule->assignee->getKey(), 'name' => $rule->assignee->name] : null,
            'customer' => $customer ? ['id' => (int) $customer->getKey(), 'name' => $customer->display_name] : null,
            'quotation' => $quotation ? ['id' => (int) $quotation->getKey(), 'number' => $quotation->quotation_number] : null,
            'version' => (int) $rule->version,
            'can_manage' => $this->access->canManage($user, $rule),
        ];
    }

    /** Active templates offered in the create form (bounded). @return list<array<string, mixed>> */
    public function activeTemplates(Organization $organization, int $limit = 100): array
    {
        return FollowUpTemplate::query()->where('organization_id', $organization->getKey())->where('active', true)
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (FollowUpTemplate $template) => $this->presentTemplate($template))->all();
    }

    /** @return array<string, mixed> */
    public function presentTemplate(FollowUpTemplate $template): array
    {
        return [
            'id' => (int) $template->getKey(),
            'name' => $template->name,
            'title' => $template->title,
            'default_note' => $template->default_note,
            'default_priority' => $template->default_priority->value,
            'default_due_offset_days' => $template->default_due_offset_days,
            'default_time' => $template->default_time,
            'active' => (bool) $template->active,
        ];
    }
}
