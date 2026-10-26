<?php

namespace Tests\Feature\WorkCenter;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\User;
use App\Services\WorkCenter\TeamWorkQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W5: Team Work Center — team definition, store isolation, grouped counts and explainable order. */
class TeamWorkTest extends QuotationTestCase
{
    private const MANAGER = ['follow_ups.view', 'follow_ups.update', 'follow_ups.create', 'follow_ups.manage_all', 'quotations.view'];

    private const SELLER = ['follow_ups.view', 'follow_ups.update', 'follow_ups.create', 'quotations.view'];

    private int $sequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Organization, Store, Store, User} organization (Africa/Casablanca), Store A, Store B, owner */
    private function fixture(): array
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $owner = User::factory()->create(['name' => 'Owner']);
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'Africa/Casablanca'];
        $organization->save();
        $storeA = $this->createStore($organization, $owner, 'Store A');
        $storeB = $this->createStore($organization, $owner, 'Store B');

        return [$organization->fresh(), $storeA, $storeB, $owner];
    }

    /** @param list<Store> $stores */
    private function member(Organization $organization, array $stores, array $permissions, string $name, string $status = 'active'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->addOrganizationMember($organization, $user, $permissions, $status);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }

        return $user;
    }

    private function followUp(Organization $organization, ?Store $store, ?User $assignee, array $attributes = []): int
    {
        return DB::table('follow_ups')->insertGetId([
            'organization_id' => $organization->getKey(), 'store_id' => $store?->getKey(), 'assigned_user_id' => $assignee?->getKey(),
            'title' => 'Suivi '.(++$this->sequence), 'priority' => 'normal', 'status' => 'open', 'due_date' => '2026-11-10', 'due_at' => null,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(), ...$attributes,
        ]);
    }

    private function attention(Organization $organization, Store $store, ?User $assignee, string $severity, bool $escalated = false, string $status = 'open'): void
    {
        $id = ++$this->sequence;
        DB::table('attention_items')->insert([
            'organization_id' => $organization->getKey(), 'store_id' => $store->getKey(), 'type' => 'quotation_sent_undecided',
            'subject_type' => 'quotation', 'subject_id' => 900000 + $id, 'episode' => 1, 'active_key' => $status === 'resolved' ? null : "k:{$id}",
            'severity' => $severity, 'status' => $status, 'title' => 'Alerte', 'summary' => 'Résumé', 'metadata' => json_encode([]),
            'detected_at' => now(), 'last_detected_at' => now(), 'assigned_user_id' => $assignee?->getKey(),
            'escalated_at' => $escalated ? now() : null, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> rows keyed by user id */
    private function rows(User $viewer, Organization $organization, array $filters = []): array
    {
        $overview = app(TeamWorkQuery::class)->overview($viewer, $organization, $filters);

        return collect($overview['members']->items())->keyBy('id')->all();
    }

    public function test_manager_sees_only_users_and_work_of_authorized_stores_and_manage_all_does_not_bypass_store_access(): void
    {
        [$organization, $storeA, $storeB, $owner] = $this->fixture();
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $ahmed = $this->member($organization, [$storeA, $storeB], self::SELLER, 'Ahmed');
        $onlyB = $this->member($organization, [$storeB], self::SELLER, 'Only B');
        $orgLevel = $this->member($organization, [], self::SELLER, 'Org level');
        $noWorkRole = $this->member($organization, [$storeA], [], 'No work role');

        $this->followUp($organization, $storeA, $ahmed, ['due_date' => '2026-11-01']);
        $this->followUp($organization, $storeB, $ahmed, ['due_date' => '2026-11-01']); // Store B: invisible to the manager
        $this->followUp($organization, $storeB, $onlyB, ['due_date' => '2026-11-01']);
        $this->attention($organization, $storeB, $ahmed, 'critical', true);

        $rows = $this->rows($manager, $organization);
        $this->assertEqualsCanonicalizing([(int) $owner->getKey(), (int) $manager->getKey(), (int) $ahmed->getKey(), (int) $orgLevel->getKey()], array_keys($rows));
        $this->assertSame(1, $rows[$ahmed->getKey()]['overdue'], 'Store B work never leaks into counts.');
        $this->assertSame(0, $rows[$ahmed->getKey()]['critical']);
        $this->assertArrayNotHasKey($onlyB->getKey(), $rows, 'A user only in a store the manager lacks never appears.');
        $this->assertArrayNotHasKey($noWorkRole->getKey(), $rows);

        $summary = app(TeamWorkQuery::class)->overview($manager, $organization, [])['summary'];
        $this->assertSame(['overdue' => 1, 'today' => 0, 'critical' => 0, 'escalated' => 0, 'unassigned' => 0], $summary);

        // Detail of a Store-B-only user: 404, nothing revealed. A forged store filter is ignored, never widened.
        $this->activate($manager, $organization, $storeA);
        $this->actingAs($manager)->get('/work/team/'.$onlyB->getKey())->assertNotFound();
        $this->assertSame(1, $this->rows($manager, $organization, ['store_id' => $storeB->getKey()])[$ahmed->getKey()]['overdue']);
    }

    public function test_normal_user_cannot_access_the_team_view(): void
    {
        [$organization, $storeA] = $this->fixture();
        $seller = $this->member($organization, [$storeA], self::SELLER, 'Seller');
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $this->activate($seller, $organization, $storeA);
        $this->activate($manager, $organization, $storeA);

        $this->actingAs($seller)->get('/work/team')->assertForbidden();
        $this->actingAs($seller)->get('/work/team/'.$manager->getKey())->assertForbidden();
        $this->actingAs($manager)->get('/work/team')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Work/Team')->where('members.total', 3));
    }

    public function test_grouped_overdue_today_upcoming_completed_counts_use_business_timezone(): void
    {
        [$organization, $storeA] = $this->fixture();
        // 2026-11-10 10:00 UTC = 11:00 in Casablanca (UTC+1).
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $seller = $this->member($organization, [$storeA], self::SELLER, 'Seller');

        $this->followUp($organization, $storeA, $seller, ['due_date' => '2026-11-09']);                                   // overdue
        $this->followUp($organization, $storeA, $seller, ['due_date' => '2026-11-10']);                                   // today
        $this->followUp($organization, $storeA, $seller, ['due_date' => '2026-11-10', 'due_at' => '2026-11-10 09:30:00']); // today + overdue (10:30 local passed)
        $this->followUp($organization, $storeA, $seller, ['due_date' => '2026-11-10', 'due_at' => '2026-11-10 22:30:00']); // 23:30 local: today
        $this->followUp($organization, $storeA, $seller, ['due_date' => '2026-11-11', 'due_at' => '2026-11-10 23:30:00']); // 00:30 local tomorrow: upcoming
        $this->followUp($organization, $storeA, $seller, ['due_date' => null]);                                             // open, undated
        $this->followUp($organization, $storeA, $seller, ['status' => 'completed', 'completed_at' => '2026-11-03 23:30:00']); // 04/11 local: in window
        $this->followUp($organization, $storeA, $seller, ['status' => 'completed', 'completed_at' => '2026-11-03 22:30:00']); // 03/11 23:30 local: out
        $this->followUp($organization, $storeA, $seller, ['status' => 'cancelled', 'cancelled_at' => now()]);

        $row = $this->rows($manager, $organization)[$seller->getKey()];
        $this->assertSame(['open' => 6, 'overdue' => 2, 'today' => 3, 'upcoming' => 1, 'completed_recent' => 1],
            array_intersect_key($row, array_flip(['open', 'overdue', 'today', 'upcoming', 'completed_recent'])));
    }

    public function test_attention_counts_unassigned_work_and_explainable_order(): void
    {
        [$organization, $storeA] = $this->fixture();
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $zed = $this->member($organization, [$storeA], self::SELLER, 'Zed escalated');
        $yan = $this->member($organization, [$storeA], self::SELLER, 'Yan critical');
        $xavier = $this->member($organization, [$storeA], self::SELLER, 'Xavier overdue');
        $will = $this->member($organization, [$storeA], self::SELLER, 'Will today');
        $alice = $this->member($organization, [$storeA], self::SELLER, 'Alice idle');

        $this->attention($organization, $storeA, $zed, 'critical', true);
        $this->attention($organization, $storeA, $yan, 'critical');
        $this->attention($organization, $storeA, $yan, 'critical');
        $this->attention($organization, $storeA, $yan, 'warning');
        $this->attention($organization, $storeA, $yan, 'critical', false, 'resolved');   // not counted
        $this->attention($organization, $storeA, $yan, 'critical', false, 'dismissed');  // not counted
        $this->followUp($organization, $storeA, $xavier, ['due_date' => '2026-11-01']);
        $this->followUp($organization, $storeA, $will, ['due_date' => '2026-11-10']);
        $this->followUp($organization, $storeA, null, ['due_date' => '2026-11-01']);      // unassigned follow-up
        $this->followUp($organization, null, null, ['due_date' => '2026-11-20']);         // unassigned, store-less
        $this->attention($organization, $storeA, null, 'critical');                       // unassigned attention

        $overview = app(TeamWorkQuery::class)->overview($manager, $organization, []);
        $order = array_column($overview['members']->items(), 'name');
        $this->assertSame(['Zed escalated', 'Yan critical', 'Xavier overdue', 'Will today', 'Alice idle', 'Manager', 'Owner'], $order);
        $rows = collect($overview['members']->items())->keyBy('id');
        $this->assertSame([2, 0, 1, 3], [$rows[$yan->getKey()]['critical'], $rows[$yan->getKey()]['escalated'], $rows[$yan->getKey()]['warning'], $rows[$yan->getKey()]['attention']]);
        $this->assertSame([1, 1], [$rows[$zed->getKey()]['critical'], $rows[$zed->getKey()]['escalated']]);
        $this->assertArrayNotHasKey('score', $rows[$zed->getKey()]);

        $this->assertSame(['open' => 2, 'overdue' => 1, 'upcoming' => 1, 'critical' => 1], array_intersect_key($overview['unassigned'], array_flip(['open', 'overdue', 'upcoming', 'critical'])));
        $this->assertSame(3, $overview['summary']['unassigned']);
        $this->assertSame(4, $overview['summary']['critical']);

        // Lightweight filters: signal, name search, scope.
        $this->assertSame(['Yan critical', 'Zed escalated'], collect($this->rows($manager, $organization, ['signal' => 'critical']))->pluck('name')->sort()->values()->all());
        $this->assertSame(['Will today'], collect($this->rows($manager, $organization, ['query' => 'will']))->pluck('name')->values()->all());
        $this->assertSame([(int) $manager->getKey()], array_keys($this->rows($manager, $organization, ['scope' => 'mine'])));
        $this->assertSame([], $this->rows($manager, $organization, ['scope' => 'unassigned']));

        // Member detail reuses the canonical lists (follow-ups + attention assigned to the member; Unassigned work).
        $this->activate($manager, $organization, $storeA);
        $this->actingAs($manager)->get('/work/team/'.$yan->getKey())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Work/TeamMember')->where('member.critical', 2)->where('attention.total', 3));
        $this->actingAs($manager)->get('/work/team/unassigned?tab=overdue')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('member.id', 0)->where('followUps.total', 1)->where('attention.total', 1));
        $this->actingAs($manager)->get('/work/team/'.$xavier->getKey().'?tab=overdue')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.total', 1)->where('followUps.data.0.assignee.id', (int) $xavier->getKey()));
    }

    public function test_departed_members_only_appear_while_holding_visible_work(): void
    {
        [$organization, $storeA] = $this->fixture();
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $left = $this->member($organization, [$storeA], self::SELLER, 'Left with history');
        $gone = $this->member($organization, [$storeA], self::SELLER, 'Gone without work');
        $this->followUp($organization, $storeA, $left, ['status' => 'completed', 'completed_at' => now()]);
        OrganizationMembership::query()->whereIn('user_id', [$left->getKey(), $gone->getKey()])->update(['status' => 'suspended']);

        $rows = $this->rows($manager, $organization);
        $this->assertArrayNotHasKey($gone->getKey(), $rows);
        $this->assertFalse($rows[$left->getKey()]['active']);
        $this->assertSame(1, $rows[$left->getKey()]['completed_recent']);
    }

    public function test_team_aggregation_query_count_is_bounded_whatever_the_team_size(): void
    {
        [$organization, $storeA] = $this->fixture();
        $manager = $this->member($organization, [$storeA], self::MANAGER, 'Manager');
        $count = function () use ($manager, $organization): int {
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(TeamWorkQuery::class)->overview($manager->fresh(), $organization, []);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        for ($i = 0; $i < 3; $i++) {
            $user = $this->member($organization, [$storeA], self::SELLER, "Seller {$i}");
            $this->followUp($organization, $storeA, $user, ['due_date' => '2026-11-01']);
            $this->attention($organization, $storeA, $user, 'critical');
        }
        $small = $count();
        for ($i = 0; $i < 25; $i++) {
            $user = $this->member($organization, [$storeA], self::SELLER, "Seller more {$i}");
            $this->followUp($organization, $storeA, $user, ['due_date' => '2026-11-01']);
            $this->attention($organization, $storeA, $user, 'warning');
        }

        $this->assertSame($small, $count(), 'Team aggregation must not grow with the number of members.');
        $this->assertLessThanOrEqual(14, $small);
    }
}
