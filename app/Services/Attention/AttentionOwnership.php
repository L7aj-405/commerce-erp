<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Models\AttentionItem;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * W4.3 single source of attention ownership (tenant/business only).
 *
 * A valid owner is an active member of the organization whose role grants the
 * subject's view permission (quotations.view, invoices.view for W4.4 invoice
 * items, sales_orders.view for W4.5 order items) and who belongs to the item's store — exactly who can see
 * the item (AttentionQuery::canView). Owner ≠ document creator/issuer ≠
 * follow-up assignee ≠ organization owner ≠ platform operator.
 *
 * Background repair (each scan, per active item, row-locked, skipped if the
 * row's version changed meanwhile — a person's action always wins):
 * 1. initial owner, once per episode, first valid of: quotation → issuer,
 *    creator; invoice → issuer, originating sales order's creator; sales
 *    order → creator, confirmer (no salesperson concept exists); otherwise
 *    unassigned;
 * 2. owner no longer valid (left, suspended, lost the view permission or the
 *    store) → unassigned;
 * 3. an unassigned CRITICAL item — unless a manager deliberately unassigned
 *    it — gets one deterministic fallback manager: a valid owner who also
 *    holds follow_ups.manage_all, lowest user id. No store-manager concept
 *    exists, platform capabilities (system.health.view) play no role, and
 *    nobody qualifying means it stays unassigned. Warnings are not forced.
 */
final class AttentionOwnership
{
    public const SOURCE_ISSUER = 'issuer';
    public const SOURCE_CREATOR = 'creator';
    public const SOURCE_ORDER_CREATOR = 'order_creator';
    public const SOURCE_CONFIRMER = 'confirmer';
    public const SOURCE_FALLBACK = 'fallback';
    public const SOURCE_CLAIM = 'claim';
    public const SOURCE_MANUAL = 'manual';

    private const CHUNK = 200;

    /** The view permission an owner of this subject must hold. */
    public static function permissionFor(string $subjectType): string
    {
        return match ($subjectType) {
            AttentionItem::SUBJECT_INVOICE => 'invoices.view',
            AttentionItem::SUBJECT_SALES_ORDER => 'sales_orders.view',
            default => 'quotations.view',
        };
    }

    /** The subset of `$userIds` who may own items of `$storeId` (one query). @param list<int> $userIds @return list<int> */
    public function eligibleUserIds(Organization $organization, ?int $storeId, array $userIds, string $permission = 'quotations.view'): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        return $this->eligibleQuery($organization, $storeId, $permission)->whereIn('users.id', $userIds)
            ->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public function isValidOwner(Organization $organization, AttentionItem $item, ?int $userId): bool
    {
        return $userId !== null && $this->eligibleUserIds($organization, $item->store_id, [$userId], self::permissionFor($item->subject_type)) !== [];
    }

    /** One deterministic manager for the store (lowest user id), optionally excluding a user. */
    public function fallbackManager(Organization $organization, ?int $storeId, ?int $exceptUserId = null, string $permission = 'quotations.view'): ?int
    {
        $id = $this->eligibleQuery($organization, $storeId, $permission)
            ->whereHas('organizationMemberships', fn (Builder $membership) => $membership
                ->where('organization_id', $organization->getKey())->where('status', 'active')
                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('key', 'follow_ups.manage_all')))
            ->when($exceptUserId !== null, fn (Builder $query) => $query->where('users.id', '!=', $exceptUserId))
            ->orderBy('users.id')->value('users.id');

        return $id === null ? null : (int) $id;
    }

    /** Eligible owners for an assignment picker (bounded). @return list<array{id: int, name: string, store_ids: list<int>}> */
    public function candidates(Organization $organization, string $permission = 'quotations.view', int $limit = 100): array
    {
        return $this->eligibleQuery($organization, null, $permission)
            ->with(['storeMemberships' => fn ($query) => $query->where('organization_id', $organization->getKey())->select(['id', 'user_id', 'store_id'])])
            ->orderBy('name')->limit($limit)->get(['users.id', 'users.name'])
            ->map(fn (User $user) => ['id' => (int) $user->getKey(), 'name' => (string) $user->name,
                'store_ids' => $user->storeMemberships->pluck('store_id')->map(fn ($id) => (int) $id)->values()->all()])
            ->all();
    }

    /**
     * Manager assignment picker: valid owners with their stores and the subjects they may own
     * (the server re-validates every assignment). @return list<array{id: int, name: string, store_ids: list<int>, subjects: list<string>}>
     */
    public function assignmentOptions(Organization $organization): array
    {
        $owners = [];
        foreach ([AttentionItem::SUBJECT_QUOTATION, AttentionItem::SUBJECT_SALES_ORDER, AttentionItem::SUBJECT_INVOICE] as $subject) {
            foreach ($this->candidates($organization, self::permissionFor($subject)) as $candidate) {
                $owners[$candidate['id']] ??= [...$candidate, 'subjects' => []];
                $owners[$candidate['id']]['subjects'][] = $subject;
            }
        }

        return array_values($owners);
    }

    /** Background ownership repair for one organization's active items. Returns the number of items changed. */
    public function repairOrganization(Organization $organization): int
    {
        $changed = 0;
        AttentionItem::query()->where('organization_id', $organization->getKey())->whereNotNull('active_key')
            ->select(['id', 'subject_type', 'store_id', 'quotation_id', 'invoice_id', 'sales_order_id', 'assigned_user_id', 'assignment_source', 'ownership_resolved_at', 'severity', 'version'])
            ->chunkById(self::CHUNK, function ($items) use ($organization, &$changed) {
                $sources = $this->initialSources($organization, $items);
                // Batched eligibility: one query per (store, subject) present in the chunk.
                $eligible = [];
                foreach ($items->groupBy(fn ($item) => $item->store_id.'|'.$item->subject_type) as $group => $groupItems) {
                    [$storeId, $subjectType] = explode('|', (string) $group, 2);
                    $ids = $groupItems->pluck('assigned_user_id')
                        ->merge($groupItems->flatMap(fn ($item) => array_column($sources[$item->getKey()] ?? [], 0)))
                        ->filter()->all();
                    $eligible[$group] = array_flip($this->eligibleUserIds($organization, $storeId === '' ? null : (int) $storeId, $ids, self::permissionFor($subjectType)));
                }
                $fallbacks = [];

                foreach ($items as $item) {
                    $group = $item->store_id.'|'.$item->subject_type;
                    $valid = $eligible[$group] ?? [];
                    $owner = $item->assigned_user_id === null ? null : (int) $item->assigned_user_id;
                    $source = $item->assignment_source;
                    $resolved = $item->ownership_resolved_at !== null;

                    if (! $resolved) {
                        if ($owner === null || ! isset($valid[$owner])) {
                            [$owner, $source] = [null, null];
                            foreach ($sources[$item->getKey()] ?? [] as [$candidate, $candidateSource]) {
                                if ($candidate !== null && isset($valid[(int) $candidate])) {
                                    [$owner, $source] = [(int) $candidate, $candidateSource];
                                    break;
                                }
                            }
                        }
                    } elseif ($owner !== null && ! isset($valid[$owner])) {
                        [$owner, $source] = [null, null]; // Owner lost access.
                    }
                    if ($owner === null && $source !== self::SOURCE_MANUAL && $item->severity === AttentionSeverity::Critical) {
                        $fallback = $fallbacks[$group] ??= ($this->fallbackManager($organization, $item->store_id, null, self::permissionFor($item->subject_type)) ?? 0);
                        if ($fallback > 0) {
                            [$owner, $source] = [$fallback, self::SOURCE_FALLBACK];
                        }
                    }

                    $unchanged = $resolved && $owner === ($item->assigned_user_id === null ? null : (int) $item->assigned_user_id) && $source === $item->assignment_source;
                    if (! $unchanged && $this->apply((int) $item->getKey(), (int) $item->version, $owner, $source)) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * Ordered initial-owner candidates per item (preloaded per chunk, no N+1).
     *
     * @return array<int, list<array{0: int|null, 1: string}>>
     */
    private function initialSources(Organization $organization, $items): array
    {
        $quotations = Quotation::query()->where('organization_id', $organization->getKey())
            ->whereKey($items->where('subject_type', AttentionItem::SUBJECT_QUOTATION)->pluck('quotation_id')->filter()->unique()->all())
            ->get(['id', 'issued_by_user_id', 'created_by_user_id'])->keyBy('id');
        $invoices = Invoice::query()->where('organization_id', $organization->getKey())
            ->whereKey($items->where('subject_type', AttentionItem::SUBJECT_INVOICE)->pluck('invoice_id')->filter()->unique()->all())
            ->get(['id', 'sales_order_id', 'issued_by_user_id'])->keyBy('id');
        $orders = SalesOrder::query()->where('organization_id', $organization->getKey())
            ->whereKey($invoices->pluck('sales_order_id')
                ->merge($items->where('subject_type', AttentionItem::SUBJECT_SALES_ORDER)->pluck('sales_order_id'))->filter()->unique()->values()->all())
            ->get(['id', 'created_by_user_id', 'confirmed_by_user_id'])->keyBy('id');

        $sources = [];
        foreach ($items as $item) {
            if ($item->subject_type === AttentionItem::SUBJECT_SALES_ORDER) {
                $order = $orders->get($item->sales_order_id);
                $sources[$item->getKey()] = [
                    [$order?->created_by_user_id, self::SOURCE_CREATOR],
                    [$order?->confirmed_by_user_id, self::SOURCE_CONFIRMER],
                ];
            } elseif ($item->subject_type === AttentionItem::SUBJECT_INVOICE) {
                $invoice = $invoices->get($item->invoice_id);
                $sources[$item->getKey()] = [
                    [$invoice?->issued_by_user_id, self::SOURCE_ISSUER],
                    [$invoice ? $orders->get($invoice->sales_order_id)?->created_by_user_id : null, self::SOURCE_ORDER_CREATOR],
                ];
            } else {
                $quotation = $quotations->get($item->quotation_id);
                $sources[$item->getKey()] = [
                    [$quotation?->issued_by_user_id, self::SOURCE_ISSUER],
                    [$quotation?->created_by_user_id, self::SOURCE_CREATOR],
                ];
            }
        }

        return $sources;
    }

    /** Apply a background decision only if nobody changed the row since it was read. */
    private function apply(int $id, int $version, ?int $owner, ?string $source): bool
    {
        return DB::transaction(function () use ($id, $version, $owner, $source) {
            $item = AttentionItem::query()->whereKey($id)->lockForUpdate()->first();
            if (! $item || $item->active_key === null || (int) $item->version !== $version) {
                return false;
            }
            $item->assigned_user_id = $owner;
            $item->assignment_source = $source;
            $item->ownership_resolved_at ??= now();
            $item->version = $version + 1;
            $item->save();

            return true;
        });
    }

    private function eligibleQuery(Organization $organization, ?int $storeId, string $permission): Builder
    {
        $organizationId = $organization->getKey();

        return User::query()
            ->whereHas('organizationMemberships', fn (Builder $membership) => $membership
                ->where('organization_id', $organizationId)->where('status', 'active')
                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->where('key', $permission)))
            ->when($storeId !== null, fn (Builder $query) => $query->whereHas('storeMemberships', fn (Builder $memberships) => $memberships
                ->where('organization_id', $organizationId)->where('store_id', $storeId)));
    }
}
