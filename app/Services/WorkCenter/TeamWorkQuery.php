<?php

namespace App\Services\WorkCenter;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\User;
use App\Services\Attention\AttentionQuery;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * W5 Team Work Center: operational workload per team member, for managers
 * (web now; MCP/API later — "what is my team working on?").
 *
 * "My team" — no org chart or reporting line exists and none is invented:
 * the people whose work the viewer may already manage. The viewer must hold
 * follow_ups.manage_all; the team is every active organization member whose
 * role grants follow-up or document (attention) visibility AND who shares at
 * least one store with the viewer or belongs to no store, plus anyone holding
 * work the viewer can already see (e.g. a member who left, with completed
 * work). Someone only in stores the viewer lacks never appears.
 *
 * Every number comes from the canonical scopes — FollowUpAccess::visible and
 * AttentionQuery::visible — and the canonical bucket rules
 * (FollowUpQuery::applyBucket / applyRecentlyCompleted, AttentionQuery::applyView),
 * so an aggregate never exceeds what the detail pages show, and manage_all
 * never widens store or document visibility. Counts are grouped
 * (GROUP BY assigned_user_id): a fixed number of queries whatever the team
 * size.
 *
 * No score, no ranking of people: the order is an explainable operational
 * tier (escalated attention → critical attention → overdue → due today →
 * the rest), then that tier's count, then name.
 */
final class TeamWorkQuery
{
    public const PER_PAGE = 50;

    public const SCOPES = ['all', 'mine', 'unassigned'];

    public const SIGNALS = ['escalated', 'critical', 'warning', 'overdue', 'today', 'upcoming'];

    /** Holding one of these makes an active member part of a manager's team (work or attention visibility). */
    private const TEAM_PERMISSIONS = ['follow_ups.view', 'quotations.view', 'invoices.view', 'sales_orders.view'];

    private const METRICS = ['open', 'overdue', 'today', 'upcoming', 'completed_recent', 'attention', 'critical', 'escalated', 'warning'];

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly FollowUpQuery $followUps,
        private readonly AttentionQuery $attention,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:120'],
            'scope' => ['nullable', Rule::in(self::SCOPES)],
            'signal' => ['nullable', Rule::in(self::SIGNALS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function canView(User $user, Organization $organization): bool
    {
        return $this->access->canManageAll($user, $organization);
    }

    /**
     * Summary, the Unassigned row and one page of member rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int>, unassigned: array<string, mixed>, members: LengthAwarePaginator, stores: list<array{id: int, name: string}>}
     */
    public function overview(User $user, Organization $organization, array $filters): array
    {
        $storeId = $this->storeFilter($user, $organization, $filters['store_id'] ?? null);
        $counts = $this->counts($user, $organization, $storeId);
        $rows = $this->rows($user, $organization, $storeId, $counts);
        $unassigned = $this->row(0, null, true, $counts[0] ?? []);

        $summary = ['overdue' => 0, 'today' => 0, 'critical' => 0, 'escalated' => 0];
        foreach ($counts as $metrics) {
            foreach (array_keys($summary) as $metric) {
                $summary[$metric] += $metrics[$metric] ?? 0;
            }
        }
        $summary['unassigned'] = $unassigned['open'] + $unassigned['attention'];

        $scope = $filters['scope'] ?? 'all';
        $search = mb_strtolower(trim((string) ($filters['query'] ?? '')));
        $signal = $filters['signal'] ?? null;
        $rows = array_values(array_filter($rows, fn (array $row) => match ($scope) {
            'mine' => $row['id'] === (int) $user->getKey(),
            'unassigned' => false,
            default => true,
        } && ($search === '' || str_contains(mb_strtolower($row['name']), $search))
          && ($signal === null || $row[$signal] > 0)));

        $page = max(1, (int) ($filters['page'] ?? 1));
        $members = new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($rows), self::PER_PAGE, $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => array_filter($filters, fn ($value) => $value !== null && $value !== '')]);

        return ['summary' => $summary, 'unassigned' => $unassigned, 'members' => $members, 'stores' => $this->stores($user, $organization)];
    }

    /**
     * One team member's row (`0` = Unassigned work), or null when that person is
     * not part of the viewer's team — callers answer 404, revealing nothing.
     *
     * @return array<string, mixed>|null
     */
    public function member(User $user, Organization $organization, int $memberId, ?int $storeId = null): ?array
    {
        $storeId = $this->storeFilter($user, $organization, $storeId);
        $counts = $this->counts($user, $organization, $storeId);
        if ($memberId === 0) {
            return $this->row(0, null, true, $counts[0] ?? []);
        }
        // Team membership never depends on the store filter (only the counts do).
        foreach ($this->rows($user, $organization, null, $counts) as $row) {
            if ($row['id'] === $memberId) {
                return $row;
            }
        }

        return null;
    }

    /** The viewer's stores, for the store filter. @return list<array{id: int, name: string}> */
    public function stores(User $user, Organization $organization): array
    {
        return Store::query()->where('organization_id', $organization->getKey())->whereIn('id', $this->access->storeIds($user, $organization))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (Store $store) => ['id' => (int) $store->getKey(), 'name' => (string) $store->name])->all();
    }

    /** A store filter only ever narrows to one of the viewer's own stores (anything else is ignored). */
    private function storeFilter(User $user, Organization $organization, mixed $storeId): ?int
    {
        return $storeId !== null && in_array((int) $storeId, $this->access->storeIds($user, $organization), true) ? (int) $storeId : null;
    }

    /**
     * Grouped counts keyed by assignee id (0 = unassigned): 5 follow-up queries
     * and 1 attention query, each GROUP BY assigned_user_id.
     *
     * @return array<int, array<string, int>>
     */
    private function counts(User $user, Organization $organization, ?int $storeId): array
    {
        $counts = [];
        $collect = function (Builder $query, string $metric) use (&$counts): void {
            foreach ($query->toBase()->selectRaw('assigned_user_id, COUNT(*) AS aggregate')->groupBy('assigned_user_id')->get() as $row) {
                $counts[(int) $row->assigned_user_id][$metric] = (int) $row->aggregate;
            }
        };
        // Covering index (organization, status, store, assignee, due/completed): index-only grouped counts. Without the
        // hint MySQL picks the assignee index for the GROUP BY order and reads every row (measured ≈2 s per bucket at 300k).
        $followUps = fn (): Builder => $this->access->visible($user, $organization)->forceIndex('follow_up_team_counts_idx')->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId));

        $collect($followUps()->where('status', 'open'), 'open');
        foreach (FollowUpQuery::DUE_BUCKETS as $bucket) {
            $query = $followUps();
            $this->followUps->applyBucket($query, $organization, $bucket);
            $collect($query, $bucket);
        }
        $query = $followUps();
        $this->followUps->applyRecentlyCompleted($query, $organization);
        $collect($query, 'completed_recent');

        if ($this->attention->canViewAny($user, $organization)) {
            $query = $this->attention->visible($user, $organization)->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId));
            $this->attention->applyView($query, 'open');
            $rows = $query->toBase()->selectRaw("assigned_user_id, COUNT(*) AS attention,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical,
                SUM(CASE WHEN escalated_at IS NOT NULL THEN 1 ELSE 0 END) AS escalated,
                SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) AS warning")->groupBy('assigned_user_id')->get();
            foreach ($rows as $row) {
                $counts[(int) $row->assigned_user_id] = [...$counts[(int) $row->assigned_user_id] ?? [],
                    'attention' => (int) $row->attention, 'critical' => (int) $row->critical, 'escalated' => (int) $row->escalated, 'warning' => (int) $row->warning];
            }
        }

        return $counts;
    }

    /**
     * Team rows in operational order (2 more queries: team members, then the
     * names/membership of anyone else holding visible work).
     *
     * @param  array<int, array<string, int>>  $counts
     * @return list<array<string, mixed>>
     */
    private function rows(User $user, Organization $organization, ?int $storeId, array $counts): array
    {
        $organizationId = $organization->getKey();
        $storeIds = $storeId !== null ? [$storeId] : $this->access->storeIds($user, $organization);
        $members = User::query()
            ->whereHas('organizationMemberships', fn (Builder $membership) => $membership
                ->where('organization_id', $organizationId)->where('status', 'active')
                ->whereHas('role.permissions', fn (Builder $permissions) => $permissions->whereIn('key', self::TEAM_PERMISSIONS)))
            ->where(fn (Builder $query) => $query
                ->whereHas('storeMemberships', fn (Builder $memberships) => $memberships->where('organization_id', $organizationId)->whereIn('store_id', $storeIds ?: [0]))
                // Organization-level members (no store) are part of every team, unless one store is filtered.
                ->when($storeId === null, fn (Builder $query) => $query->orWhereDoesntHave('storeMemberships', fn (Builder $memberships) => $memberships->where('organization_id', $organizationId))))
            ->get(['users.id', 'users.name'])->keyBy(fn (User $member) => (int) $member->getKey());

        $rows = [];
        foreach ($members as $id => $member) {
            $rows[$id] = $this->row($id, (string) $member->name, true, $counts[$id] ?? []);
        }
        // Holders of work the viewer can already see but who are not (or no longer) team members.
        $others = array_values(array_diff(array_keys($counts), [0], array_keys($rows)));
        if ($others !== []) {
            $active = OrganizationMembership::query()->where('organization_id', $organizationId)->whereIn('user_id', $others)
                ->where('status', 'active')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            foreach (User::query()->whereIn('id', $others)->get(['id', 'name']) as $other) {
                $id = (int) $other->getKey();
                $rows[$id] = $this->row($id, (string) $other->name, in_array($id, $active, true), $counts[$id]);
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => [$a['tier'], -$a['tier_count'], mb_strtolower($a['name']), $a['id']]
            <=> [$b['tier'], -$b['tier_count'], mb_strtolower($b['name']), $b['id']]);

        return $rows;
    }

    /** @param array<string, int> $metrics @return array<string, mixed> */
    private function row(int $id, ?string $name, bool $active, array $metrics): array
    {
        $row = ['id' => $id, 'name' => (string) $name, 'active' => $active];
        foreach (self::METRICS as $metric) {
            $row[$metric] = (int) ($metrics[$metric] ?? 0);
        }
        // Explainable operational tier: the first signal that applies, never a blended score.
        [$row['tier'], $row['tier_count']] = match (true) {
            $row['escalated'] > 0 => [0, $row['escalated']],
            $row['critical'] > 0 => [1, $row['critical']],
            $row['overdue'] > 0 => [2, $row['overdue']],
            $row['today'] > 0 => [3, $row['today']],
            default => [4, 0],
        };

        return $row;
    }
}
