<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
use App\Enums\AttentionType;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * W4.1 single source of attention visibility, search and presentation (web
 * now; MCP later). An item is visible only to users who may see the
 * underlying quotation: quotations.view in the organization AND membership of
 * the item's store. follow_ups.manage_all grants nothing extra here — tenant
 * and store security stay authoritative.
 */
final class AttentionQuery
{
    public const VIEWS = ['open', 'snoozed', 'dismissed', 'resolved', 'all'];

    public function __construct(private readonly FollowUpAccess $access) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'type' => ['nullable', Rule::enum(AttentionType::class)],
            'severity' => ['nullable', Rule::enum(AttentionSeverity::class)],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            // A single item (notification target); still bounded by visible().
            'focus' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function canViewAny(User $user, Organization $organization): bool
    {
        return $this->access->allows($user, $organization, 'quotations.view');
    }

    public function canView(User $user, AttentionItem $item): bool
    {
        return $this->access->allows($user, (int) $item->organization_id, 'quotations.view')
            && $this->access->hasStoreAccess($user, $item->store_id);
    }

    public function visible(User $user, Organization $organization): Builder
    {
        $storeIds = $this->access->storeIds($user, $organization);

        return AttentionItem::query()
            ->where('organization_id', $organization->getKey())
            ->when(! $this->canViewAny($user, $organization), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where(fn (Builder $query) => $query->whereNull('store_id')->orWhereIn('store_id', $storeIds));
    }

    /** @param array<string, mixed> $filters */
    public function search(User $user, Organization $organization, array $filters): LengthAwarePaginator
    {
        $now = CarbonImmutable::now('UTC');
        $query = $this->visible($user, $organization)
            ->with(['customer:id,organization_id,display_name', 'quotation:id,organization_id,store_id,quotation_number,status'])
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['severity'] ?? null, fn (Builder $q, string $severity) => $q->where('severity', $severity))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', (int) $id))
            ->when($filters['quotation_id'] ?? null, fn (Builder $q, $id) => $q->where('quotation_id', (int) $id))
            ->when($filters['focus'] ?? null, fn (Builder $q, $id) => $q->whereKey((int) $id));

        match ($filters['view'] ?? 'open') {
            // A snooze that has elapsed counts as open right away (the detector confirms it on its next scan).
            'open' => $query->where(fn (Builder $q) => $q->where('status', AttentionStatus::Open->value)
                ->orWhere(fn (Builder $s) => $s->where('status', AttentionStatus::Snoozed->value)->where('snoozed_until', '<=', $now))),
            'snoozed' => $query->where('status', AttentionStatus::Snoozed->value)->where('snoozed_until', '>', $now),
            'dismissed' => $query->where('status', AttentionStatus::Dismissed->value),
            'resolved' => $query->where('status', AttentionStatus::Resolved->value),
            default => null,
        };

        // Deterministic priority: still-active episodes first, then critical > warning > info,
        // then business urgency (QuotationAttentionDetector::urgency), oldest detection, id.
        return $query->orderByRaw('CASE WHEN active_key IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('urgency')->orderBy('detected_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
    }

    public function openCount(User $user, Organization $organization): int
    {
        return $this->search($user, $organization, ['view' => 'open', 'per_page' => 1])->total();
    }

    /** @return array<string, mixed> */
    public function present(User $user, Organization $organization, AttentionItem $item): array
    {
        $quotation = $item->quotation;
        $customer = $item->customer && $this->access->allows($user, $organization, 'customers.view') ? $item->customer : null;
        $number = $quotation?->quotation_number ?? ($item->metadata['quotation_number'] ?? null);
        $active = $item->active_key !== null;

        return [
            'id' => (int) $item->getKey(),
            'type' => $item->type->value,
            'severity' => $item->severity->value,
            'status' => $item->status->value,
            'title' => $item->title,
            'summary' => $item->summary,
            'i18n' => $item->metadata['i18n'] ?? null,
            'quotation' => $quotation ? ['id' => (int) $quotation->getKey(), 'number' => $number,
                'can_open' => $this->access->hasStoreAccess($user, $quotation->store_id)] : null,
            'customer' => $customer ? ['id' => (int) $customer->getKey(), 'name' => $customer->display_name] : null,
            'detected_at' => $item->detected_at->toIso8601String(),
            'snoozed_until' => $item->snoozed_until?->toIso8601String(),
            'dismissed_at' => $item->dismissed_at?->toIso8601String(),
            'dismiss_note' => $item->dismiss_note,
            'resolved_at' => $item->resolved_at?->toIso8601String(),
            'follow_up_count' => count($item->metadata['follow_up_ids'] ?? []),
            'notified_at' => $item->notified_at?->toIso8601String(),
            'is_active' => $active,
            'can_act' => $active && in_array($item->status, [AttentionStatus::Open, AttentionStatus::Snoozed], true),
            'can_create_follow_up' => $quotation !== null && $this->access->canCreate($user, $organization),
            'suggestion' => ['title' => $this->suggestedTitle($item, $number), 'due_date' => BusinessTime::today($organization)],
            'version' => (int) $item->version,
        ];
    }

    private function suggestedTitle(AttentionItem $item, ?string $number): string
    {
        $number ??= '';

        return trim(match ($item->type) {
            AttentionType::QuotationSentUndecided => "Relancer le client — devis {$number}",
            AttentionType::QuotationExpiringSoon => "Relancer avant expiration — devis {$number}",
            AttentionType::QuotationExpiredUndecided => "Proposer une prolongation ou une révision — devis {$number}",
            AttentionType::QuotationFollowUpBacklog => "Traiter les relances en retard — devis {$number}",
        });
    }
}
