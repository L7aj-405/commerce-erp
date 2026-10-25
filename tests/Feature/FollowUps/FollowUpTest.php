<?php

namespace Tests\Feature\FollowUps;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\FollowUps\FollowUpQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

class FollowUpTest extends QuotationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store} */
    private function fixture(string $timezone = 'Africa/Casablanca'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => $timezone];
        $organization->save();
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization->fresh(), $store];
    }

    private function save(): SaveFollowUpAction
    {
        return app(SaveFollowUpAction::class);
    }

    public function test_default_roles_receive_follow_up_permissions_without_manage_all_for_sales(): void
    {
        [, $organization] = $this->fixture();
        $keys = fn (string $slug) => Role::query()->where('organization_id', $organization->getKey())->where('slug', $slug)->firstOrFail()->permissions()->pluck('key')->all();

        $this->assertContains('follow_ups.manage_all', $keys('owner'));
        $this->assertContains('follow_ups.manage_all', $keys('admin'));
        $sales = $keys('sales-employee');
        $this->assertContains('follow_ups.view', $sales);
        $this->assertContains('follow_ups.update', $sales);
        $this->assertNotContains('follow_ups.manage_all', $sales);
    }

    public function test_date_only_follow_up_is_today_and_only_overdue_after_the_local_day_ends(): void
    {
        [$owner, $organization] = $this->fixture('Asia/Tokyo');
        Carbon::setTestNow('2026-10-26 01:00:00'); // 10:00 on 26 Oct in Tokyo

        $this->actingAs($owner)->post(route('follow-ups.store'), ['title' => 'Relancer ACME', 'due_date' => '2026-10-26'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $followUp = FollowUp::query()->sole();
        $this->assertSame('2026-10-26', $followUp->due_date);
        $this->assertNull($followUp->due_at);
        $this->assertSame((int) $owner->getKey(), (int) $followUp->assigned_user_id);
        $this->assertSame('normal', $followUp->priority->value);

        $query = app(FollowUpQuery::class);
        $this->assertSame(['today' => 1, 'overdue' => 0, 'upcoming' => 0], $query->counts($owner, $organization));

        Carbon::setTestNow('2026-10-26 14:59:59'); // 23:59:59 local — still today
        $this->assertSame(['today' => 1, 'overdue' => 0, 'upcoming' => 0], $query->counts($owner, $organization));

        Carbon::setTestNow('2026-10-26 15:00:00'); // 00:00 on 27 Oct local
        $this->assertSame(['today' => 0, 'overdue' => 1, 'upcoming' => 0], $query->counts($owner, $organization));

        $this->assertDatabaseHas('audit_logs', ['event' => 'follow_up.created', 'auditable_id' => (string) $followUp->getKey()]);
    }

    public function test_timed_follow_up_is_stored_in_utc_and_bare_datetimes_are_rejected(): void
    {
        [$owner, $organization] = $this->fixture('Europe/Paris');
        Carbon::setTestNow('2026-10-26 08:00:00');

        $timed = $this->save()->create($owner, $organization, ['title' => 'Appeler', 'due_date' => '2026-10-26', 'due_time' => '10:00']);
        $this->assertSame('2026-10-26T09:00:00+00:00', $timed->fresh()->due_at->utc()->toIso8601String());
        $this->assertSame('2026-10-26', $timed->due_date);

        $offset = $this->save()->create($owner, $organization, ['title' => 'Offset', 'due_at' => '2026-10-27T23:30:00-05:00']);
        $this->assertSame('2026-10-28T04:30:00+00:00', $offset->fresh()->due_at->utc()->toIso8601String());
        $this->assertSame('2026-10-28', $offset->due_date);

        foreach ([['due_at' => '2026-10-27T10:00:00'], ['due_time' => '10:00'], ['due_date' => '2026-03-29', 'due_time' => '02:30']] as $input) {
            try {
                $this->save()->create($owner, $organization, ['title' => 'Bad', ...$input]);
                $this->fail('Expected a validation error for '.json_encode($input));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        Carbon::setTestNow('2026-10-26 09:30:00'); // 10:30 Paris: the 10:00 item is late but still today's work
        $presented = app(FollowUpQuery::class)->present($owner, $organization, $timed->fresh());
        $this->assertTrue($presented['is_overdue']);
        $this->assertTrue($presented['is_today']);
        $this->assertSame('10:00', $presented['due_time']);
    }

    public function test_sales_user_manages_only_own_work_and_cannot_assign_to_others(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);

        $ownerItem = $this->save()->create($owner, $organization, ['title' => 'Owner task']);
        $this->actingAs($seller)->post(route('follow-ups.store'), ['title' => 'Mine'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($seller)->post(route('follow-ups.store'), ['title' => 'For owner', 'assigned_user_id' => $owner->getKey()])->assertForbidden();

        $this->actingAs($seller)->post(route('follow-ups.transition', $ownerItem), ['transition' => 'complete'])->assertForbidden();
        $this->actingAs($seller)->patch(route('follow-ups.update', $ownerItem), ['title' => 'Hijack'])->assertForbidden();

        $this->actingAs($seller)->get(route('follow-ups.index', ['tab' => 'all', 'assigned_to' => 'all']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Work/Index')->where('followUps.total', 1)
                ->where('followUps.data.0.title', 'Mine')->where('context.can_manage_all', false));

        // manage_all: the owner can reassign and complete the seller's work.
        $mine = FollowUp::query()->where('title', 'Mine')->sole();
        $this->save()->update($owner, $organization, $mine, ['assigned_user_id' => $owner->getKey()]);
        $this->assertSame((int) $owner->getKey(), (int) $mine->fresh()->assigned_user_id);
        $log = AuditLog::query()->where('event', 'follow_up.reassigned')->sole();
        $this->assertSame(['assigned_user_id' => (int) $seller->getKey()], $log->old_values);
    }

    public function test_assignee_must_be_an_active_member_with_follow_up_access(): void
    {
        [$owner, $organization] = $this->fixture();
        $outsider = User::factory()->create();
        $noAccess = User::factory()->create();
        $this->addOrganizationMember($organization, $noAccess, ['customers.view']);

        foreach ([$outsider, $noAccess] as $candidate) {
            try {
                $this->save()->create($owner, $organization, ['title' => 'X', 'assigned_user_id' => $candidate->getKey()]);
                $this->fail('Expected assignee validation error.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assigned_user_id', $exception->errors());
            }
        }
    }

    public function test_transitions_follow_the_lifecycle_and_reject_stale_or_invalid_moves(): void
    {
        [$owner, $organization] = $this->fixture();
        $transition = app(TransitionFollowUpAction::class);
        $followUp = $this->save()->create($owner, $organization, ['title' => 'Call back', 'due_date' => '2026-10-30']);

        $completed = $transition->execute($owner, $organization, $followUp, ['transition' => 'complete', 'outcome_note' => 'Client OK', 'expected_version' => 1]);
        $this->assertSame('completed', $completed->status->value);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame((int) $owner->getKey(), (int) $completed->completed_by_user_id);
        $this->assertSame(2, $completed->version);

        $this->expectStatusError(fn () => $transition->execute($owner, $organization, $followUp, ['transition' => 'cancel']));
        $this->expectStatusError(fn () => $transition->execute($owner, $organization, $followUp, ['transition' => 'reopen', 'expected_version' => 1]), 'version');
        $this->expectStatusError(fn () => $this->save()->update($owner, $organization, $followUp, ['title' => 'Edit closed']));

        $reopened = $transition->execute($owner, $organization, $followUp, ['transition' => 'reopen', 'expected_version' => 2]);
        $this->assertSame('open', $reopened->status->value);
        $this->assertNull($reopened->completed_at);
        $this->assertNull($reopened->outcome_note);

        $cancelled = $transition->execute($owner, $organization, $followUp, ['transition' => 'cancel']);
        $this->assertNotNull($cancelled->cancelled_at);
        foreach (['follow_up.completed', 'follow_up.reopened', 'follow_up.cancelled'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'auditable_id' => (string) $followUp->getKey()]);
        }
    }

    public function test_reschedule_is_audited_and_stale_edits_are_rejected(): void
    {
        [$owner, $organization] = $this->fixture();
        $followUp = $this->save()->create($owner, $organization, ['title' => 'Relance', 'due_date' => '2026-10-30']);

        $this->save()->update($owner, $organization, $followUp, ['due_date' => '2026-11-02', 'due_time' => '09:15', 'expected_version' => 1]);
        $log = AuditLog::query()->where('event', 'follow_up.rescheduled')->sole();
        // assertEquals: MySQL JSON columns do not preserve object key order.
        $this->assertEquals(['due_date' => '2026-10-30', 'due_at' => null], $log->old_values);
        $this->assertSame('2026-11-02', $log->new_values['due_date']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'follow_up.updated']);

        $this->expectStatusError(fn () => $this->save()->update($owner, $organization, $followUp, ['title' => 'Old tab', 'expected_version' => 1]), 'version');

        // Partial update: untouched keys (the due date/time) are preserved.
        $updated = $this->save()->update($owner, $organization, $followUp, ['title' => 'Relance v2', 'expected_version' => 2]);
        $this->assertSame('2026-11-02', $updated->due_date);
        $this->assertNotNull($updated->due_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'follow_up.updated', 'auditable_id' => (string) $followUp->getKey()]);
    }

    public function test_quotation_link_derives_store_and_customer_and_enforces_consistency(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $customer = $this->createCustomer($organization, 'ACME');
        $other = $this->createCustomer($organization, 'Other');
        $quotation = $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->getKey()]);

        $linked = $this->save()->create($owner, $organization, ['title' => 'Relancer devis', 'quotation_id' => $quotation->getKey()]);
        $this->assertSame((int) $store->getKey(), (int) $linked->store_id);
        $this->assertSame((int) $customer->getKey(), (int) $linked->customer_id);

        $this->expectStatusError(fn () => $this->save()->create($owner, $organization, ['title' => 'Mismatch', 'quotation_id' => $quotation->getKey(), 'customer_id' => $other->getKey()]), 'customer_id');

        // Another organization's records can never be linked.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignCustomer = $this->createCustomer($foreign, 'Foreign customer');
        $this->expectStatusError(fn () => $this->save()->create($owner, $organization, ['title' => 'Cross', 'customer_id' => $foreignCustomer->getKey()]), 'customer_id');

        // A store-linked follow-up can only be assigned to members of that store.
        $outsideStore = User::factory()->create();
        $this->addOrganizationMember($organization, $outsideStore, ['follow_ups.view']);
        $this->expectStatusError(fn () => $this->save()->create($owner, $organization, ['title' => 'Store', 'quotation_id' => $quotation->getKey(), 'assigned_user_id' => $outsideStore->getKey()]), 'assigned_user_id');

        $this->actingAs($owner)->get(route('quotations.show', $quotation))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.items.0.title', 'Relancer devis')->where('followUps.prefill.quotation_id', (int) $quotation->getKey()));
        $this->actingAs($owner)->get(route('sales.customers.show', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.items.0.title', 'Relancer devis'));
    }

    public function test_follow_ups_never_cross_organizations(): void
    {
        [$owner, $organization] = $this->fixture();
        $item = $this->save()->create($owner, $organization, ['title' => 'Private']);

        $intruder = User::factory()->create();
        $other = $this->createOrganization($intruder, 'Other');
        $this->activate($intruder, $other);

        $this->actingAs($intruder)->post(route('follow-ups.transition', $item), ['transition' => 'complete'])->assertNotFound();
        $this->actingAs($intruder)->get(route('follow-ups.index', ['tab' => 'all', 'assigned_to' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('followUps.total', 0));

        $this->expectException(AuthorizationException::class);
        app(TransitionFollowUpAction::class)->execute($intruder, $organization, $item, ['transition' => 'complete']);
    }

    private function expectStatusError(callable $callback, string $key = 'status'): void
    {
        try {
            $callback();
            $this->fail("Expected a [{$key}] validation error.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
