<?php

namespace Tests\Feature\FollowUps;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\FollowUps\FollowUpAssignmentReleaser;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\MembershipService;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W1.1: cross-store quotation navigation and "Unassigned" work after membership loss. */
class FollowUpHardeningTest extends QuotationTestCase
{
    /** @return array{User, Organization, Store} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'Africa/Casablanca'];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);

        return [$owner, $organization->fresh(), $store];
    }

    private function save(): SaveFollowUpAction
    {
        return app(SaveFollowUpAction::class);
    }

    /** Quotations are created in the actor's active store; switch back afterwards. */
    private function quotationIn(User $owner, Organization $organization, Store $store, Store $back): Quotation
    {
        $this->activate($owner, $organization, $store);
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->activate($owner, $organization, $back);

        return $quotation;
    }

    private function membership(Organization $organization, User $user): OrganizationMembership
    {
        return OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->firstOrFail();
    }

    public function test_follow_up_opens_a_quotation_of_another_store_the_user_belongs_to(): void
    {
        [$owner, $organization, $storeA] = $this->fixture();
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $storeA, $seller);
        $this->addStoreMember($storeB, $seller);
        $quotation = $this->quotationIn($owner, $organization, $storeB, $storeA);
        $followUp = $this->save()->create($seller, $organization, ['title' => 'Relancer devis B', 'quotation_id' => $quotation->getKey()]);
        $this->activate($seller, $organization, $storeA);

        // The store-scoped quotation page alone 404s from Store A (unchanged scoping).
        $this->actingAs($seller)->get(route('quotations.show', $quotation))->assertNotFound();

        $this->actingAs($seller)->get(route('follow-ups.index', ['tab' => 'all']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.data.0.quotation.can_open', true));

        $this->actingAs($seller)->post(route('follow-ups.open-quotation', $followUp))
            ->assertRedirect(route('quotations.show', $quotation->getKey()));
        $this->assertSame((int) $storeB->getKey(), (int) $seller->fresh()->active_store_id);
        $this->actingAs($seller->fresh())->get(route('quotations.show', $quotation))->assertOk();
    }

    public function test_open_quotation_never_switches_to_or_reveals_an_unauthorized_store(): void
    {
        [$owner, $organization, $storeA] = $this->fixture();
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $quotation = $this->quotationIn($owner, $organization, $storeB, $storeA);

        // A manager of Store A only: the Store B follow-up is not visible, so nothing switches.
        $manager = User::factory()->create();
        $this->addOrganizationMember($organization, $manager, ['follow_ups.view', 'follow_ups.update', 'follow_ups.manage_all', 'quotations.view']);
        $this->addStoreMember($storeA, $manager);
        $this->activate($manager, $organization, $storeA);
        $followUp = $this->save()->create($owner, $organization, ['title' => 'B', 'quotation_id' => $quotation->getKey()]);

        $this->actingAs($manager)->post(route('follow-ups.open-quotation', $followUp))->assertForbidden();
        $this->assertSame((int) $storeA->getKey(), (int) $manager->fresh()->active_store_id);

        // Visible follow-up, but no quotations.view: no link, and the route refuses without switching.
        $assistant = User::factory()->create();
        $this->addOrganizationMember($organization, $assistant, ['follow_ups.view', 'follow_ups.update']);
        $this->addStoreMember($storeA, $assistant);
        $this->addStoreMember($storeB, $assistant);
        $this->activate($assistant, $organization, $storeA);
        $own = $this->save()->create($owner, $organization, ['title' => 'Assistant', 'quotation_id' => $quotation->getKey(), 'assigned_user_id' => $assistant->getKey()]);

        $this->actingAs($assistant)->get(route('follow-ups.index', ['tab' => 'all']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.data.0.title', 'Assistant')->where('followUps.data.0.quotation', null));
        $this->actingAs($assistant)->from(route('follow-ups.index'))->post(route('follow-ups.open-quotation', $own))
            ->assertRedirect(route('follow-ups.index'))->assertSessionHasErrors('quotation');
        $this->assertSame((int) $storeA->getKey(), (int) $assistant->fresh()->active_store_id);

        // Another organization's follow-up never resolves.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignStore = $this->createStore($foreign, $foreignOwner);
        $this->activate($foreignOwner, $foreign, $foreignStore);
        $foreignFollowUp = $this->save()->create($foreignOwner, $foreign->fresh(), ['title' => 'Foreign']);
        $this->actingAs($owner)->post(route('follow-ups.open-quotation', $foreignFollowUp))->assertNotFound();
    }

    public function test_removing_a_member_unassigns_only_their_open_work_and_keeps_history(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);
        $open = $this->save()->create($seller, $organization, ['title' => 'Open']);
        $done = $this->save()->create($seller, $organization, ['title' => 'Done']);
        app(TransitionFollowUpAction::class)->execute($seller, $organization, $done, ['transition' => 'complete']);
        $ownerItem = $this->save()->create($owner, $organization, ['title' => 'Owner']);

        app(MembershipService::class)->removeOrganizationMember($owner, $this->membership($organization, $seller));

        $open->refresh();
        $this->assertNull($open->assigned_user_id);
        $this->assertSame(2, $open->version);
        $this->assertSame((int) $seller->getKey(), (int) $open->created_by_user_id);
        $this->assertSame((int) $seller->getKey(), (int) $done->fresh()->assigned_user_id);
        $this->assertSame((int) $seller->getKey(), (int) $done->fresh()->completed_by_user_id);
        $this->assertSame((int) $owner->getKey(), (int) $ownerItem->fresh()->assigned_user_id);

        $audit = AuditLog::query()->where('event', 'follow_up.reassigned')->where('auditable_id', (string) $open->getKey())->sole();
        $this->assertSame((int) $seller->getKey(), $audit->old_values['assigned_user_id']);
        $this->assertNull($audit->new_values['assigned_user_id']);
        $this->assertSame('membership_removed', $audit->new_values['reason']);

        // Managers find it in the Unassigned bucket; "me" stays personal.
        $query = app(FollowUpQuery::class);
        $this->assertSame([(int) $open->getKey()], $query->filtered($owner, $organization, ['assigned_to' => 'unassigned'])->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([(int) $ownerItem->getKey()], $query->filtered($owner, $organization, ['assigned_to' => 'me', 'status' => 'open'])->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, $query->filtered($owner, $organization, ['assigned_to' => 'all', 'status' => 'open'])->count());
        $this->actingAs($owner)->get(route('follow-ups.index', ['tab' => 'all', 'assigned_to' => 'unassigned']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.total', 1)->where('followUps.data.0.assignee', null));

        // Retry-safe: a second run releases nothing.
        $this->assertSame(0, app(FollowUpAssignmentReleaser::class)->releaseDeparted($organization, $owner, 'retry'));
    }

    public function test_suspension_role_change_and_store_removal_release_affected_open_work(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        app(ActiveTenantContext::class)->resolve($owner);
        $memberships = app(MembershipService::class);
        $quotation = $this->createQuotation($owner, $organization, $store);

        $suspended = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $suspended);
        $a = $this->save()->create($suspended, $organization, ['title' => 'Suspended']);
        $memberships->changeStatus($owner, $this->membership($organization, $suspended), 'suspended');
        $this->assertNull($a->fresh()->assigned_user_id);

        $demoted = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $demoted);
        $b = $this->save()->create($demoted, $organization, ['title' => 'Demoted']);
        $memberships->changeRole($owner, $this->membership($organization, $demoted), $this->createRole($organization, ['customers.view']));
        $this->assertNull($b->fresh()->assigned_user_id);

        $moved = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $moved);
        $storeLinked = $this->save()->create($moved, $organization, ['title' => 'Store work', 'quotation_id' => $quotation->getKey()]);
        $orgLevel = $this->save()->create($moved, $organization, ['title' => 'Org work']);
        $memberships->removeStoreMember($owner, StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $moved->getKey())->firstOrFail());
        $this->assertNull($storeLinked->fresh()->assigned_user_id);
        $this->assertSame((int) $moved->getKey(), (int) $orgLevel->fresh()->assigned_user_id);

        $reasons = AuditLog::query()->where('event', 'follow_up.reassigned')->orderBy('id')->get()->map(fn (AuditLog $log) => $log->new_values['reason'])->all();
        $this->assertSame(['membership_suspended', 'role_changed', 'store_access_removed'], $reasons);
    }

    public function test_only_manage_all_creates_or_handles_unassigned_work(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);

        $unassigned = $this->save()->create($owner, $organization, ['title' => 'Pool', 'assigned_user_id' => null]);
        $this->assertNull($unassigned->assigned_user_id);

        // A normal user's explicit null still means their own work.
        $sellerItem = $this->save()->create($seller, $organization, ['title' => 'Mine', 'assigned_user_id' => null]);
        $this->assertSame((int) $seller->getKey(), (int) $sellerItem->assigned_user_id);

        $query = app(FollowUpQuery::class);
        $this->assertSame(['Mine'], $query->filtered($seller, $organization, ['assigned_to' => 'unassigned'])->pluck('title')->all());
        $this->actingAs($seller)->patch(route('follow-ups.update', $unassigned), ['title' => 'Grab'])->assertForbidden();

        $this->save()->update($owner, $organization, $unassigned, ['assigned_user_id' => $seller->getKey()]);
        $this->assertSame((int) $seller->getKey(), (int) $unassigned->fresh()->assigned_user_id);
        $audit = AuditLog::query()->where('event', 'follow_up.reassigned')->where('auditable_id', (string) $unassigned->getKey())->sole();
        $this->assertNull($audit->old_values['assigned_user_id']);

        $this->save()->update($owner, $organization, $unassigned->fresh(), ['assigned_user_id' => null]);
        $this->assertNull($unassigned->fresh()->assigned_user_id);

        // Departed users are not offered as assignees.
        $gone = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $gone);
        app(MembershipService::class)->removeOrganizationMember($owner, $this->membership($organization, $gone));
        $ids = collect($query->formContext($owner, $organization)['assignees'])->pluck('id')->all();
        $this->assertNotContains((int) $gone->getKey(), $ids);
        $this->assertContains((int) $seller->getKey(), $ids);
    }

    public function test_reopening_history_of_a_departed_assignee_makes_it_unassigned(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);
        $done = $this->save()->create($seller, $organization, ['title' => 'Done']);
        app(TransitionFollowUpAction::class)->execute($seller, $organization, $done, ['transition' => 'complete']);
        app(MembershipService::class)->removeOrganizationMember($owner, $this->membership($organization, $seller));
        $this->assertSame((int) $seller->getKey(), (int) $done->fresh()->assigned_user_id);

        app(TransitionFollowUpAction::class)->execute($owner, $organization, $done->fresh(), ['transition' => 'reopen']);
        $this->assertNull($done->fresh()->assigned_user_id);
        $audit = AuditLog::query()->where('event', 'follow_up.reopened')->sole();
        $this->assertSame((int) $seller->getKey(), $audit->old_values['assigned_user_id']);
        $this->assertNull($audit->new_values['assigned_user_id']);
    }

    public function test_users_and_access_reset_unassigns_non_owner_open_work(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);
        $open = $this->save()->create($seller, $organization, ['title' => 'Open']);
        $ownerItem = $this->save()->create($owner, $organization, ['title' => 'Owner']);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::UsersAndAccess);
        $this->assertSame(1, $plan->counts['follow_ups.unassign_removed_members']);
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);

        $this->assertNull($open->fresh()->assigned_user_id);
        $this->assertSame((int) $owner->getKey(), (int) $ownerItem->fresh()->assigned_user_id);
        $this->assertDatabaseHas('follow_ups', ['id' => $open->getKey(), 'organization_id' => $organization->getKey()]);
    }
}
