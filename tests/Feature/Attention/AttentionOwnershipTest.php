<?php

namespace Tests\Feature\Attention;

use App\Models\AttentionItem;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ActiveTenantContext;
use App\Services\Attention\AttentionEscalator;
use App\Services\Attention\AttentionNotifier;
use App\Services\Attention\AttentionOwnership;
use App\Services\Attention\QuotationAttentionDetector;
use App\Services\MembershipService;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W4.3: attention ownership, deterministic manager fallback, claim/reassign, one escalation level. */
class AttentionOwnershipTest extends QuotationTestCase
{
    private int $sequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, User} owner (manage_all), organization, store, seller */
    private function fixture(string $timezone = 'UTC'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => $timezone];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);

        return [$owner, $organization->fresh(), $store, $seller];
    }

    private function quotation(User $owner, Organization $organization, Store $store, ?User $issuer, ?User $creator = null, string $validUntil = '2026-11-05'): Quotation
    {
        $this->activate($owner, $organization, $store);
        $quotation = $this->createQuotation($owner, $organization, $store);
        DB::table('quotations')->where('id', $quotation->getKey())->update([
            'status' => 'issued', 'quotation_number' => 'DEV-'.(++$this->sequence), 'valid_until' => $validUntil,
            'issued_by_user_id' => $issuer?->getKey(), 'created_by_user_id' => ($creator ?? $owner)->getKey(),
        ]);

        return $quotation->fresh();
    }

    /** The attention:scan-quotations pipeline for one organization. */
    private function tick(Organization $organization): void
    {
        $organization = $organization->fresh();
        app(QuotationAttentionDetector::class)->scanOrganization($organization);
        app(AttentionOwnership::class)->repairOrganization($organization);
        app(AttentionNotifier::class)->notifyOrganization($organization);
        app(AttentionEscalator::class)->escalateOrganization($organization);
    }

    private function item(Quotation $quotation): AttentionItem
    {
        return AttentionItem::query()->where('quotation_id', $quotation->getKey())->orderByDesc('id')->firstOrFail();
    }

    private function manager(Organization $organization, Store $store, array $extra = []): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, ['quotations.view', 'follow_ups.view', 'follow_ups.update', 'follow_ups.manage_all', ...$extra]);
        $this->addStoreMember($store, $user);

        return $user;
    }

    public function test_initial_owner_is_a_valid_issuer_then_creator_otherwise_unassigned(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $outsider = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $storeB, $outsider); // no access to Store A
        $noQuotes = User::factory()->create();
        $this->addOrganizationMember($organization, $noQuotes, ['follow_ups.view']);
        $this->addStoreMember($store, $noQuotes);

        $byIssuer = $this->quotation($owner, $organization, $store, $seller, $owner);
        $byCreator = $this->quotation($owner, $organization, $store, $outsider, $seller);       // issuer lacks the store
        $warningNobody = $this->quotation($owner, $organization, $store, $noQuotes, $outsider, '2026-11-11'); // expiring = warning
        $this->tick($organization);

        $this->assertSame([(int) $seller->getKey(), 'issuer'], [(int) $this->item($byIssuer)->assigned_user_id, $this->item($byIssuer)->assignment_source]);
        $this->assertSame([(int) $seller->getKey(), 'creator'], [(int) $this->item($byCreator)->assigned_user_id, $this->item($byCreator)->assignment_source]);
        $this->assertNull($this->item($warningNobody)->assigned_user_id, 'Warnings get no forced fallback.');
        $this->assertSame(2, UserNotification::query()->where('user_id', $seller->getKey())->where('event_type', 'critical')->count(), 'Critical alert goes to the owner.');
    }

    public function test_fallback_manager_is_one_deterministic_member_of_the_quotation_store(): void
    {
        // Created before the owner (lower ids) but must not be chosen: wrong store / platform capability only.
        $wrongStore = User::factory()->create();
        $platform = User::factory()->create();
        [$owner, $organization, $store] = $this->fixture();
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $this->addOrganizationMember($organization, $wrongStore, ['quotations.view', 'follow_ups.manage_all']);
        $this->addStoreMember($storeB, $wrongStore);
        $this->addOrganizationMember($organization, $platform, ['quotations.view', 'system.health.view']);
        $this->addStoreMember($store, $platform);
        $laterManager = $this->manager($organization, $store);
        Carbon::setTestNow('2026-11-10 10:00:00');
        $gone = User::factory()->create();

        $orphan = $this->quotation($owner, $organization, $store, $gone, $gone);
        $this->tick($organization);
        $this->assertSame([(int) $owner->getKey(), 'fallback'], [(int) $this->item($orphan)->assigned_user_id, $this->item($orphan)->assignment_source]);
        $this->assertSame(1, UserNotification::query()->where('source_type', 'attention_item')->count(), 'Exactly one recipient.');
        $this->assertSame(0, UserNotification::query()->whereIn('user_id', [$wrongStore->getKey(), $platform->getKey(), $laterManager->getKey()])->count());

        // No eligible manager in the store → stays unassigned, nobody notified, no crash.
        $orphanB = $this->quotation($owner, $organization, $storeB, $gone, $gone);
        StoreMembership::query()->where('store_id', $storeB->getKey())->whereIn('user_id', [$owner->getKey(), $wrongStore->getKey()])->delete();
        $this->tick($organization);
        $this->assertNull($this->item($orphanB)->assigned_user_id);
        $this->assertNotNull($this->item($orphanB)->notification_processed_at);
        $this->assertSame(1, UserNotification::query()->where('source_type', 'attention_item')->count());
    }

    public function test_owner_losing_access_is_repaired_and_manual_assignment_survives_scans(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $colleague = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $colleague);
        $lostRole = $this->quotation($owner, $organization, $store, $seller);
        $this->tick($organization);
        $this->assertSame((int) $seller->getKey(), (int) $this->item($lostRole)->assigned_user_id);

        // Seller loses quotations.view → released, critical → deterministic fallback manager.
        app(ActiveTenantContext::class)->resolve($owner);
        app(MembershipService::class)->changeRole($owner, OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $seller->getKey())->firstOrFail(),
            $this->createRole($organization, ['follow_ups.view']));
        Carbon::setTestNow('2026-11-10 10:15:00');
        $this->tick($organization);
        $this->assertSame([(int) $owner->getKey(), 'fallback'], [(int) $this->item($lostRole)->assigned_user_id, $this->item($lostRole)->assignment_source]);
        $this->assertSame(1, UserNotification::query()->where('event_type', 'critical')->count(), 'Reassignment never re-sends the critical alert.');

        // Manager assigns it to a colleague; later scans keep that decision.
        $item = $this->item($lostRole);
        $this->actingAs($owner)->post(route('attention.assign', $item), ['assigned_user_id' => $colleague->getKey(), 'expected_version' => $item->version])->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-11-10 10:30:00');
        $this->tick($organization);
        $this->assertSame([(int) $colleague->getKey(), 'manual'], [(int) $this->item($lostRole)->assigned_user_id, $this->item($lostRole)->assignment_source]);
        $this->assertSame(1, UserNotification::query()->where('user_id', $colleague->getKey())->where('event_type', 'like', 'assigned:%')->count());

        // Colleague removed from the store → released again → fallback.
        StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $colleague->getKey())->delete();
        Carbon::setTestNow('2026-11-10 10:45:00');
        $this->tick($organization);
        $this->assertSame((int) $owner->getKey(), (int) $this->item($lostRole)->assigned_user_id);

        // Resolution keeps the final owner as history.
        DB::table('quotations')->where('id', $lostRole->getKey())->update(['status' => 'accepted']);
        Carbon::setTestNow('2026-11-10 11:00:00');
        $this->tick($organization);
        $this->assertSame(['resolved', (int) $owner->getKey()], [$this->item($lostRole)->status->value, (int) $this->item($lostRole)->assigned_user_id]);
    }

    public function test_claim_assign_and_unassign_rules(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $gone = User::factory()->create();
        $warning = $this->quotation($owner, $organization, $store, $gone, $gone, '2026-11-11');
        $this->tick($organization);
        $item = $this->item($warning);
        $this->assertNull($item->assigned_user_id);

        // A normal user sees and claims it; cannot assign to others.
        $this->actingAs($seller)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.data.0.can_claim', true)->where('items.data.0.can_assign', false));
        $this->actingAs($seller)->post(route('attention.claim', $item))->assertSessionHasNoErrors();
        $this->assertSame([(int) $seller->getKey(), 'claim'], [(int) $item->fresh()->assigned_user_id, $item->fresh()->assignment_source]);
        $this->actingAs($seller)->post(route('attention.claim', $item))->assertSessionHasErrors('assigned_user_id');
        $this->actingAs($seller)->post(route('attention.assign', $item), ['assigned_user_id' => $owner->getKey()])->assertForbidden();

        // A manager can reassign only to a valid owner, and deliberately unassign.
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $outsider = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $storeB, $outsider);
        $this->actingAs($owner)->post(route('attention.assign', $item), ['assigned_user_id' => $outsider->getKey()])->assertSessionHasErrors('assigned_user_id');
        $this->actingAs($owner)->post(route('attention.assign', $item), ['assigned_user_id' => null])->assertSessionHasNoErrors();
        $this->assertSame([null, 'manual'], [$item->fresh()->assigned_user_id, $item->fresh()->assignment_source]);

        $this->assertSame(['attention.claimed', 'attention.unassigned'], AuditLog::query()->where('event', 'like', 'attention.%')->orderBy('id')->pluck('event')->all());
        $claim = AuditLog::query()->where('event', 'attention.claimed')->sole();
        $this->assertSame((int) $seller->getKey(), $claim->new_values['new_user_id']);

        // Cross-organization: not reachable.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign');
        $this->activate($foreignOwner, $foreign, $this->createStore($foreign, $foreignOwner));
        $this->actingAs($foreignOwner)->post(route('attention.claim', $item))->assertNotFound();

        // manage_all without the store: neither sees nor assigns.
        $managerB = $this->manager($organization, $storeB);
        $this->activate($managerB, $organization, $storeB);
        $this->actingAs($managerB)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
        $this->actingAs($managerB)->post(route('attention.assign', $item), ['assigned_user_id' => $managerB->getKey()])->assertForbidden();
    }

    public function test_deliberate_unassign_is_not_overridden_by_the_fallback(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $critical = $this->quotation($owner, $organization, $store, $seller);
        $this->tick($organization);
        $this->actingAs($owner)->post(route('attention.assign', $this->item($critical)), ['assigned_user_id' => null])->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-11-10 10:15:00');
        $this->tick($organization);
        $this->assertNull($this->item($critical)->assigned_user_id);
    }

    public function test_critical_escalates_once_after_two_local_days_to_one_manager(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture('Asia/Tokyo');
        $manager = $this->manager($organization, $store);
        Carbon::setTestNow('2026-11-10 14:00:00'); // 10 Nov 23:00 Tokyo
        $critical = $this->quotation($owner, $organization, $store, $seller);
        $warning = $this->quotation($owner, $organization, $store, $seller, null, '2026-11-12');
        $this->tick($organization);
        $this->assertNotNull($this->item($critical)->critical_since);

        Carbon::setTestNow('2026-11-11 14:59:00'); // 11 Nov 23:59 Tokyo: only one local day
        $this->tick($organization);
        $this->assertNull($this->item($critical)->escalated_at);

        Carbon::setTestNow('2026-11-11 15:00:00'); // 12 Nov 00:00 Tokyo: two local days
        $this->tick($organization);
        $this->tick($organization);
        $this->assertNotNull($this->item($critical)->escalated_at);
        $escalations = UserNotification::query()->where('event_type', 'escalation')->get();
        $this->assertCount(1, $escalations);
        $this->assertSame((int) $owner->getKey(), (int) $escalations->first()->user_id, 'Lowest-id eligible manager, not every manager.');
        $this->assertSame('Attention critique non traitée : devis '.$critical->quotation_number, $escalations->first()->title);
        $this->assertSame(0, UserNotification::query()->where('user_id', $manager->getKey())->count());
        $this->assertNull($this->item($warning)->escalated_at, 'Warnings never escalate.');

        // Escalated critical sorts first.
        $this->actingAs($owner)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.quotation.id', (int) $critical->getKey())->where('items.data.0.escalated_at', fn ($value) => $value !== null));
    }

    public function test_handled_items_do_not_escalate_and_manager_owners_escalate_silently(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $snoozed = $this->quotation($owner, $organization, $store, $seller);
        $dismissed = $this->quotation($owner, $organization, $store, $seller);
        $followedUp = $this->quotation($owner, $organization, $store, $seller);
        $managerOwned = $this->quotation($owner, $organization, $store, $owner);
        $this->tick($organization);
        $this->actingAs($seller)->post(route('attention.snooze', $this->item($snoozed)), ['preset' => 'date', 'until_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->actingAs($seller)->post(route('attention.dismiss', $this->item($dismissed)))->assertSessionHasNoErrors();
        $this->actingAs($seller)->post(route('attention.follow-up', $this->item($followedUp)), ['title' => 'Relancer', 'due_date' => '2026-11-10'])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->tick($organization);
        $this->assertNull($this->item($snoozed)->escalated_at);
        $this->assertNull($this->item($dismissed)->escalated_at);
        $this->assertNull($this->item($followedUp)->escalated_at);
        $this->assertNotNull($this->item($managerOwned)->escalated_at, 'Escalated…');
        $this->assertSame(0, UserNotification::query()->where('event_type', 'escalation')->count(), '…without notifying another manager.');
    }

    public function test_users_and_access_reset_releases_non_owner_attention_owners(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $quotation = $this->quotation($owner, $organization, $store, $seller);
        $this->tick($organization);
        $this->assertSame((int) $seller->getKey(), (int) $this->item($quotation)->assigned_user_id);

        app(OrganizationDataResetExecutor::class)->execute($organization, $owner,
            app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::UsersAndAccess));
        $this->assertNull($this->item($quotation)->assigned_user_id);
        Carbon::setTestNow('2026-11-10 10:15:00');
        $this->tick($organization);
        $this->assertSame([(int) $owner->getKey(), 'fallback'], [(int) $this->item($quotation)->assigned_user_id, $this->item($quotation)->assignment_source]);
    }
}
