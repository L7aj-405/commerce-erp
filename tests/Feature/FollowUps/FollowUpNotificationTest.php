<?php

namespace Tests\Feature\FollowUps;

use App\Actions\FollowUps\NotifyDueFollowUpsAction;
use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Actions\Settings\UpdateOrganizationTimezoneAction;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserNotificationPreference;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W2: in-app due reminders for follow-ups. */
class FollowUpNotificationTest extends QuotationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, User} owner, organization, store, seller */
    private function fixture(?string $timezone = 'Africa/Casablanca'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        if ($timezone !== null) {
            $organization->settings = ['timezone' => $timezone];
            $organization->save();
        }
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);

        return [$owner, $organization->fresh(), $store, $seller];
    }

    private function save(): SaveFollowUpAction
    {
        return app(SaveFollowUpAction::class);
    }

    /** @return array{sent: int, skipped: int, failed: int} */
    private function notifyDue(): array
    {
        return app(NotifyDueFollowUpsAction::class)->execute();
    }

    private function notifications(?User $user = null)
    {
        return UserNotification::query()->where('source_type', 'follow_up')->when($user, fn ($query) => $query->where('user_id', $user->getKey()));
    }

    public function test_timed_due_follow_up_notifies_its_assignee_exactly_once(): void
    {
        [$owner, $organization, , $seller] = $this->fixture('Europe/Paris');
        Carbon::setTestNow('2026-10-26 08:00:00');
        $customer = $this->createCustomer($organization, 'ACME');
        $followUp = $this->save()->create($seller, $organization, [
            'title' => 'Appeler le client', 'note' => 'Secret margin 12%', 'due_date' => '2026-10-26', 'due_time' => '10:00', 'customer_id' => $customer->getKey(),
        ]);
        $this->assertSame('2026-10-26 09:00:00', $followUp->fresh()->remind_at->utc()->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-10-26 08:59:59');
        $this->assertSame(0, $this->notifyDue()['sent']);

        Carbon::setTestNow('2026-10-26 09:02:00');
        $this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $this->notifyDue());
        $this->assertSame(0, $this->notifyDue()['sent'] + $this->notifyDue()['skipped'], 'An overlapping/second run finds nothing to claim.');

        $notification = $this->notifications($seller)->sole();
        $this->assertSame('sales', $notification->category->value);
        $this->assertSame('Suivi à effectuer : Appeler le client', $notification->title);
        $this->assertSame('Échéance : 26/10/2026 10:00 — ACME', $notification->message);
        $this->assertStringNotContainsString('Secret', $notification->title.$notification->message.json_encode($notification->metadata));
        $this->assertSame('/work?tab=all&assigned_to=all&focus='.$followUp->getKey(), $notification->action_url);
        $this->assertSame('work.notifyMessageContext', $notification->metadata['i18n']['message']);
        $this->assertSame(0, $this->notifications($owner)->count());

        $followUp->refresh();
        $this->assertNull($followUp->remind_at);
        $this->assertSame('2026-10-26 09:02:00', $followUp->notified_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, $followUp->version, 'Delivering a reminder is not a business edit.');
    }

    public function test_a_re_armed_identical_occurrence_is_deduplicated_by_the_publisher(): void
    {
        [, $organization, , $seller] = $this->fixture();
        Carbon::setTestNow('2026-10-26 08:00:00');
        $followUp = $this->save()->create($seller, $organization, ['title' => 'Call', 'due_at' => '2026-10-26T09:00:00Z']);
        Carbon::setTestNow('2026-10-26 09:00:00');
        $this->notifyDue();

        // Uncertain-outcome retry of the same occurrence (same instant + version): no duplicate.
        FollowUp::query()->whereKey($followUp->getKey())->update(['remind_at' => '2026-10-26 09:00:00']);
        $this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $this->notifyDue());
        $this->assertSame(1, $this->notifications()->count());
        $this->assertNull($followUp->fresh()->remind_at);
    }

    public function test_date_only_reminder_fires_at_nine_in_the_organization_timezone_or_utc_fallback(): void
    {
        [, $tokyo, , $seller] = $this->fixture('Asia/Tokyo');
        Carbon::setTestNow('2026-10-26 10:00:00');
        $item = $this->save()->create($seller, $tokyo, ['title' => 'Relancer', 'due_date' => '2026-10-27']);
        $this->assertSame('2026-10-27 00:00:00', $item->fresh()->remind_at->utc()->format('Y-m-d H:i:s')); // 09:00 Tokyo

        Carbon::setTestNow('2026-10-26 23:59:59');
        $this->assertSame(0, $this->notifyDue()['sent']);
        Carbon::setTestNow('2026-10-27 00:00:00');
        $this->assertSame(1, $this->notifyDue()['sent']);
        $this->assertSame('Échéance : 27/10/2026', $this->notifications()->sole()->message);

        [, $utc, , $utcSeller] = $this->fixture(null);
        Carbon::setTestNow('2026-10-26 10:00:00');
        $fallback = $this->save()->create($utcSeller, $utc, ['title' => 'UTC', 'due_date' => '2026-10-27']);
        $this->assertSame('2026-10-27 09:00:00', $fallback->fresh()->remind_at->utc()->format('Y-m-d H:i:s'));

        // A date-only item due today whose 09:00 has already passed is not armed (never at 00:00, no catch-up burst).
        Carbon::setTestNow('2026-10-27 14:00:00');
        $this->assertNull($this->save()->create($utcSeller, $utc, ['title' => 'Late', 'due_date' => '2026-10-27'])->remind_at);
    }

    public function test_timezone_change_realigns_pending_date_only_reminders(): void
    {
        [$owner, $organization, , $seller] = $this->fixture('Africa/Casablanca');
        Carbon::setTestNow('2026-10-26 10:00:00');
        $dateOnly = $this->save()->create($seller, $organization, ['title' => 'Date', 'due_date' => '2026-10-30']);
        $timed = $this->save()->create($seller, $organization, ['title' => 'Timed', 'due_at' => '2026-10-30T15:00:00Z']);

        app(UpdateOrganizationTimezoneAction::class)->execute($owner, $organization, 'Asia/Tokyo');

        $this->assertSame('2026-10-30 00:00:00', $dateOnly->fresh()->remind_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 15:00:00', $timed->fresh()->remind_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_disabled_sales_notifications_consume_the_occurrence_without_delivering(): void
    {
        [$owner, $organization, , $seller] = $this->fixture();
        $preference = new UserNotificationPreference;
        $preference->user_id = $seller->getKey();
        $preference->disabled_categories = ['sales'];
        $preference->save();
        Carbon::setTestNow('2026-10-26 08:00:00');
        $followUp = $this->save()->create($seller, $organization, ['title' => 'Muted', 'due_at' => '2026-10-26T09:00:00Z']);

        Carbon::setTestNow('2026-10-26 09:05:00');
        $this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $this->notifyDue());
        $this->assertSame(0, $this->notifications()->count());
        $followUp->refresh();
        $this->assertNull($followUp->remind_at, 'Not reprocessed every run.');
        $this->assertNull($followUp->notified_at, 'Nothing was delivered.');
        $this->actingAs($seller)->get(route('follow-ups.index', ['tab' => 'overdue']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('followUps.total', 1));
    }

    public function test_unassigned_completed_and_cancelled_work_never_notifies(): void
    {
        [$owner, $organization, , $seller] = $this->fixture();
        Carbon::setTestNow('2026-10-26 08:00:00');
        $unassigned = $this->save()->create($owner, $organization, ['title' => 'Pool', 'assigned_user_id' => null, 'due_at' => '2026-10-26T09:00:00Z']);
        $completed = $this->save()->create($seller, $organization, ['title' => 'Done', 'due_at' => '2026-10-26T09:00:00Z']);
        $cancelled = $this->save()->create($seller, $organization, ['title' => 'Dropped', 'due_at' => '2026-10-26T09:00:00Z']);
        app(TransitionFollowUpAction::class)->execute($seller, $organization, $completed, ['transition' => 'complete']);
        app(TransitionFollowUpAction::class)->execute($seller, $organization, $cancelled, ['transition' => 'cancel']);
        $this->assertNull($completed->fresh()->remind_at);
        $this->assertNull($cancelled->fresh()->remind_at);

        Carbon::setTestNow('2026-10-26 09:05:00');
        $this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $this->notifyDue());
        $this->assertSame(0, $this->notifications()->count());
        $this->assertNull($unassigned->fresh()->remind_at);
    }

    public function test_snooze_after_a_reminder_arms_one_new_reminder_and_plain_edits_do_not(): void
    {
        [, $organization, , $seller] = $this->fixture('UTC');
        Carbon::setTestNow('2026-10-26 08:00:00');
        $followUp = $this->save()->create($seller, $organization, ['title' => 'Call', 'due_date' => '2026-10-26', 'due_time' => '10:00']);
        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->assertSame(1, $this->notifyDue()['sent']);

        // Title/priority/note edits keep the delivered occurrence.
        $this->save()->update($seller, $organization, $followUp->fresh(), ['title' => 'Call again', 'priority' => 'high', 'note' => 'n']);
        $this->assertNull($followUp->fresh()->remind_at);
        $this->assertNotNull($followUp->fresh()->notified_at);
        $this->assertSame(0, $this->notifyDue()['sent']);

        // Snooze to tomorrow 10:00 (the W1 snooze sends due_date + due_time).
        $this->actingAs($seller)->patch(route('follow-ups.update', $followUp), ['due_date' => '2026-10-27', 'due_time' => '10:00'])->assertSessionHasNoErrors();
        $followUp->refresh();
        $this->assertSame('2026-10-27 10:00:00', $followUp->remind_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($followUp->notified_at);

        Carbon::setTestNow('2026-10-27 09:59:00');
        $this->assertSame(0, $this->notifyDue()['sent']);
        Carbon::setTestNow('2026-10-27 10:00:00');
        $this->assertSame(1, $this->notifyDue()['sent']);
        $this->assertSame(0, $this->notifyDue()['sent']);
        $this->assertSame(2, $this->notifications($seller)->count());

        // Rescheduling into the past does not fire a catch-up reminder.
        $this->save()->update($seller, $organization, $followUp->fresh(), ['due_date' => '2026-10-20']);
        $this->assertNull($followUp->fresh()->remind_at);
    }

    public function test_reopen_arms_only_a_future_occurrence(): void
    {
        [, $organization, , $seller] = $this->fixture('UTC');
        Carbon::setTestNow('2026-10-26 08:00:00');
        $future = $this->save()->create($seller, $organization, ['title' => 'Future', 'due_at' => '2026-10-28T09:00:00Z']);
        $past = $this->save()->create($seller, $organization, ['title' => 'Past', 'due_at' => '2026-10-26T09:00:00Z']);
        Carbon::setTestNow('2026-10-26 09:00:00');
        $this->notifyDue();
        $transition = app(TransitionFollowUpAction::class);
        foreach ([$future, $past] as $item) {
            $transition->execute($seller, $organization, $item->fresh(), ['transition' => 'complete']);
            $transition->execute($seller, $organization, $item->fresh(), ['transition' => 'reopen']);
        }

        $this->assertSame('2026-10-28 09:00:00', $future->fresh()->remind_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($past->fresh()->remind_at, 'Reopening overdue work does not re-notify.');
        $this->assertNotNull($past->fresh()->notified_at);
        $this->notifyDue();
        $this->assertSame(1, $this->notifications()->count());
    }

    public function test_assignee_losing_access_is_never_notified(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture('UTC');
        Carbon::setTestNow('2026-10-26 08:00:00');
        $removed = $this->save()->create($seller, $organization, ['title' => 'Removed', 'due_at' => '2026-10-26T09:00:00Z']);
        app(MembershipService::class)->removeOrganizationMember($owner, OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())->where('user_id', $seller->getKey())->firstOrFail());
        $this->assertNull($removed->fresh()->assigned_user_id);

        // Stale state (store access gone without the W1.1 release having run): the claim re-checks access.
        $other = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $other);
        $quotation = $this->createQuotation($owner, $organization, $store);
        $storeLinked = $this->save()->create($other, $organization, ['title' => 'Store', 'quotation_id' => $quotation->getKey(), 'due_at' => '2026-10-26T09:00:00Z']);
        StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $other->getKey())->delete();

        Carbon::setTestNow('2026-10-26 09:05:00');
        $this->assertSame(0, $this->notifyDue()['sent']);
        $this->assertSame(0, $this->notifications()->count());
        $this->assertNull($storeLinked->fresh()->remind_at);
    }

    public function test_notification_target_is_authorized_and_reaches_a_cross_store_quotation(): void
    {
        [$owner, $organization, $storeA, $seller] = $this->fixture('UTC');
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $this->addStoreMember($storeB, $seller);
        $this->activate($owner, $organization, $storeB);
        $quotation = $this->createQuotation($owner, $organization, $storeB);
        $this->activate($owner, $organization, $storeA);
        Carbon::setTestNow('2026-10-26 08:00:00');
        $followUp = $this->save()->create($seller, $organization, ['title' => 'Devis B', 'quotation_id' => $quotation->getKey(), 'due_at' => '2026-10-26T09:00:00Z']);
        Carbon::setTestNow('2026-10-26 09:00:00');
        $this->notifyDue();
        $url = $this->notifications($seller)->sole()->action_url;

        $this->activate($seller, $organization, $storeA);
        $this->actingAs($seller)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('followUps.total', 1)->where('followUps.data.0.id', (int) $followUp->getKey())
            ->where('followUps.data.0.quotation.can_open', true)->where('filters.focus', (string) $followUp->getKey()));
        $this->actingAs($seller)->post(route('follow-ups.open-quotation', $followUp))->assertRedirect(route('quotations.show', $quotation->getKey()));

        // Someone who cannot see the follow-up gets an empty, unavailable view.
        $colleague = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $storeA, $colleague);
        $this->actingAs($colleague)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('followUps.total', 0));
    }

    public function test_scheduled_command_runs_and_is_registered_every_five_minutes(): void
    {
        [, $organization, , $seller] = $this->fixture('UTC');
        Carbon::setTestNow('2026-10-26 08:00:00');
        $this->save()->create($seller, $organization, ['title' => 'Cmd', 'due_at' => '2026-10-26T09:00:00Z']);
        Carbon::setTestNow('2026-10-26 09:00:00');

        $this->artisan('follow-ups:notify-due')->expectsOutputToContain('1 sent')->assertSuccessful();
        $this->artisan('follow-ups:notify-due')->expectsOutputToContain('0 sent')->assertSuccessful();

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'follow-ups:notify-due'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
