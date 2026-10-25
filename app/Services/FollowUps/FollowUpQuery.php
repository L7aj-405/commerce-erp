<?php

namespace App\Services\FollowUps;

use App\Enums\FollowUpStatus;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Canonical follow-up search and presentation (web now; MCP/API later).
 *
 * Buckets use the organization business timezone, evaluated per request:
 * - today:    open, and either a date-only item due on the local today or a
 *             timed item whose instant falls inside the local today;
 * - overdue:  open, and either a timed item whose instant has passed or a
 *             date-only item whose local day has ended (never at 00:00);
 * - upcoming: open, due after the local today;
 * Timed items earlier today are both "today" and overdue (shown as late).
 */
class FollowUpQuery
{
    public const DUE_BUCKETS = ['today', 'overdue', 'upcoming'];

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly FollowUpAssignees $assignees,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(FollowUpStatus::class)],
            'due' => ['nullable', Rule::in(self::DUE_BUCKETS)],
            'assigned_to' => ['nullable', 'string', 'max:20'],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'query' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function search(User $user, Organization $organization, array $filters): LengthAwarePaginator
    {
        $query = $this->filtered($user, $organization, $filters)
            ->with(['assignee:id,name', 'customer:id,organization_id,display_name', 'quotation:id,organization_id,store_id,quotation_number,status']);

        if (($filters['status'] ?? null) === FollowUpStatus::Completed->value) {
            $query->orderByDesc('completed_at')->orderByDesc('id');
        } else {
            $query->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('due_at')
                ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")->orderBy('id');
        }

        return $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    public function filtered(User $user, Organization $organization, array $filters): Builder
    {
        $query = $this->access->visible($user, $organization);
        $this->applyAssignee($query, $user, $organization, $filters['assigned_to'] ?? 'me');

        if (isset($filters['due'])) {
            $this->applyBucket($query, $organization, (string) $filters['due']);
        } elseif (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->when($filters['quotation_id'] ?? null, fn (Builder $q, $id) => $q->where('quotation_id', (int) $id))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', (int) $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->where('due_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->where('due_date', '<=', $date))
            ->when(trim((string) ($filters['query'] ?? '')) !== '', function (Builder $q) use ($filters) {
                $like = '%'.trim((string) $filters['query']).'%';
                $q->where(fn (Builder $q) => $q->where('title', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('display_name', 'like', $like)->orWhere('company_name', 'like', $like))
                    ->orWhereHas('quotation', fn (Builder $c) => $c->where('quotation_number', 'like', $like)));
            });
    }

    /** @return array{today: int, overdue: int, upcoming: int} */
    public function counts(User $user, Organization $organization, string $assignedTo = 'me'): array
    {
        $counts = [];
        foreach (self::DUE_BUCKETS as $bucket) {
            $query = $this->access->visible($user, $organization);
            $this->applyAssignee($query, $user, $organization, $assignedTo);
            $this->applyBucket($query, $organization, $bucket);
            $counts[$bucket] = $query->count();
        }

        return $counts;
    }

    public function applyBucket(Builder $query, Organization $organization, string $bucket): void
    {
        $today = BusinessTime::today($organization);
        $dayStart = BusinessTime::localToUtc($organization, $today);
        $dayEnd = BusinessTime::localToUtc($organization, CarbonImmutable::parse($today)->addDay()->toDateString());
        $now = CarbonImmutable::now('UTC');

        $query->where('status', FollowUpStatus::Open->value);
        match ($bucket) {
            'today' => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $d) => $d->whereNull('due_at')->where('due_date', $today))
                ->orWhere(fn (Builder $t) => $t->where('due_at', '>=', $dayStart)->where('due_at', '<', $dayEnd))),
            'overdue' => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $t) => $t->whereNotNull('due_at')->where('due_at', '<', $now))
                ->orWhere(fn (Builder $d) => $d->whereNull('due_at')->where('due_date', '<', $today))),
            'upcoming' => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $d) => $d->whereNull('due_at')->where('due_date', '>', $today))
                ->orWhere('due_at', '>=', $dayEnd)),
        };
    }

    /** `me` (default) | `all` | `unassigned` | a user id; anything but `me` requires manage_all. */
    private function applyAssignee(Builder $query, User $user, Organization $organization, mixed $assignedTo): void
    {
        if (! $this->access->canManageAll($user, $organization) || $assignedTo === null || $assignedTo === 'me') {
            $query->where('assigned_user_id', $user->getKey());
        } elseif ($assignedTo === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif ($assignedTo !== 'all' && ctype_digit((string) $assignedTo)) {
            $query->where('assigned_user_id', (int) $assignedTo);
        }
    }

    /** What a create/edit form needs: business today/timezone, abilities and (for manage_all) assignable users. @return array<string, mixed> */
    public function formContext(User $user, Organization $organization, ?int $storeId = null): array
    {
        $manageAll = $this->access->canManageAll($user, $organization);

        return [
            'today' => BusinessTime::today($organization),
            'timezone' => $organization->businessTimezone(),
            'timezone_configured' => $organization->hasConfiguredTimezone(),
            'can_create' => $this->access->canCreate($user, $organization),
            'can_manage_all' => $manageAll,
            'current_user_id' => (int) $user->getKey(),
            'assignees' => $manageAll ? $this->assignees->assignable($organization, $storeId, limit: 100)->all() : [],
        ];
    }

    /**
     * Detail-page panel (customer / quotation): null when the viewer cannot use
     * follow-ups at all. @param array<string, int|null> $prefill @return array<string, mixed>|null
     */
    public function panel(User $user, Organization $organization, string $column, int $id, array $prefill, ?int $storeId = null): ?array
    {
        if (! $this->access->canViewAny($user, $organization)) {
            return null;
        }

        return [
            'items' => $this->linked($user, $organization, $column, $id),
            'context' => $this->formContext($user, $organization, $storeId),
            'prefill' => $prefill,
        ];
    }

    /** Recent follow-ups linked to a customer or quotation, for detail-page panels. @return list<array<string, mixed>> */
    public function linked(User $user, Organization $organization, string $column, int $id, int $limit = 10): array
    {
        return $this->access->visible($user, $organization)
            ->where($column, $id)
            ->with(['assignee:id,name', 'customer:id,organization_id,display_name', 'quotation:id,organization_id,store_id,quotation_number,status'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('due_at')->orderByDesc('id')
            ->limit($limit)->get()
            ->map(fn (FollowUp $followUp) => $this->present($user, $organization, $followUp))->all();
    }

    /** @return array<string, mixed> */
    public function present(User $user, Organization $organization, FollowUp $followUp): array
    {
        $local = $followUp->due_at ? BusinessTime::toLocal($organization, $followUp->due_at) : null;
        $open = $followUp->status === FollowUpStatus::Open;
        $today = BusinessTime::today($organization);
        $overdue = $open && ($followUp->due_at
            ? $followUp->due_at->isPast()
            : ($followUp->due_date !== null && $followUp->due_date < $today));
        $customer = $followUp->customer instanceof Customer && $this->access->allows($user, $organization, 'customers.view') ? $followUp->customer : null;
        $quotation = $followUp->quotation instanceof Quotation && $this->access->allows($user, $organization, 'quotations.view') ? $followUp->quotation : null;

        return [
            'id' => (int) $followUp->getKey(),
            'title' => $followUp->title,
            'note' => $followUp->note,
            'priority' => $followUp->priority->value,
            'status' => $followUp->status->value,
            'due_date' => $local?->toDateString() ?? $followUp->due_date,
            'due_time' => $local?->format('H:i'),
            'due_at' => $followUp->due_at?->utc()->toIso8601String(),
            'is_overdue' => $overdue,
            'is_today' => $open && ($local?->toDateString() ?? $followUp->due_date) === $today,
            'assignee' => $followUp->assignee ? ['id' => (int) $followUp->assignee->getKey(), 'name' => $followUp->assignee->name] : null,
            'customer' => $customer ? ['id' => (int) $customer->getKey(), 'name' => $customer->display_name] : null,
            // can_open: the viewer may open the quotation (switching to its store
            // through FollowUpController::openQuotation when needed).
            'quotation' => $quotation ? ['id' => (int) $quotation->getKey(), 'number' => $quotation->quotation_number, 'store_id' => (int) $quotation->store_id,
                'can_open' => $this->access->hasStoreAccess($user, $quotation->store_id)] : null,
            'completed_at' => $followUp->completed_at?->toIso8601String(),
            'cancelled_at' => $followUp->cancelled_at?->toIso8601String(),
            'outcome_note' => $followUp->outcome_note,
            'version' => (int) $followUp->version,
            'can_manage' => $this->access->canManage($user, $followUp),
        ];
    }
}
