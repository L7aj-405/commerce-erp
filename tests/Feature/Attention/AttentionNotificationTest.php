<?php

namespace Tests\Feature\Attention;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserNotificationPreference;
use App\Services\Attention\AttentionNotifier;
use App\Services\Attention\AttentionOwnership;
use App\Services\Attention\QuotationAttentionDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W4.2: critical-only attention notifications, one per episode, and prioritization. */
class AttentionNotificationTest extends QuotationTestCase
{
    private int $sequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, User} owner, organization, store, seller */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => 'UTC'];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);

        return [$owner, $organization->fresh(), $store, $seller];
    }

    /** Quotation issued by `$issuer` in a given state (set directly: this tests notifications, not issuing). */
    private function quotation(User $owner, Organization $organization, Store $store, User $issuer, string $status = 'issued', ?string $validUntil = null, array $data = []): Quotation
    {
        $this->activate($owner, $organization, $store);
        $quotation = $this->createQuotation($owner, $organization, $store, $data);
        DB::table('quotations')->where('id', $quotation->getKey())->update([
            'status' => $status, 'quotation_number' => 'DEV-'.(++$this->sequence), 'valid_until' => $validUntil, 'issued_by_user_id' => $issuer->getKey(),
        ]);

        return $quotation->fresh();
    }

    /** One scheduled run: scan, owners, then notify (as attention:scan-quotations does). */
    private function tick(Organization $organization): array
    {
        app(QuotationAttentionDetector::class)->scanOrganization($organization->fresh());
        app(AttentionOwnership::class)->repairOrganization($organization->fresh());

        return app(AttentionNotifier::class)->notifyOrganization($organization->fresh());
    }

    private function notifications(?User $user = null)
    {
        return UserNotification::query()->where('source_type', 'attention_item')->when($user, fn ($q) => $q->where('user_id', $user->getKey()));
    }

    public function test_open_critical_item_notifies_its_quotation_issuer_once_and_warnings_never(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $customer = $this->createCustomer($organization, 'ACME');
        $expired = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-05', ['customer_id' => $customer->getKey()]);
        $expiring = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-11');
        $before = $expired->fresh()->only(['status', 'valid_until', 'updated_at']);

        $this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $this->tick($organization));
        $this->assertSame(0, $this->tick($organization)['sent'] + app(AttentionNotifier::class)->notifyOrganization($organization)['sent'], 'Repeated / overlapping runs never notify twice.');

        $notification = $this->notifications()->sole();
        $this->assertSame((int) $seller->getKey(), (int) $notification->user_id, 'Issuer only: no manager / owner fan-out.');
        $this->assertSame(['sales', 'critical'], [$notification->category->value, $notification->severity->value]);
        $this->assertSame('Devis expiré sans décision : '.$expired->quotation_number, $notification->title);
        $this->assertSame('ACME — Validité dépassée depuis le 05/11/2026.', $notification->message);
        $item = AttentionItem::query()->where('quotation_id', $expired->getKey())->sole();
        $this->assertSame('/work/attention?view=all&focus='.$item->getKey(), $notification->action_url);
        $this->assertSame('attention.notifyExpiredMessageContext', $notification->metadata['i18n']['message']);
        $this->assertNotNull($item->notified_at);
        $this->assertNotNull($item->notification_processed_at);
        $this->assertSame('warning', AttentionItem::query()->where('quotation_id', $expiring->getKey())->sole()->severity->value);
        $this->assertNull(AttentionItem::query()->where('quotation_id', $expiring->getKey())->sole()->notification_processed_at, 'Warnings are never processed for notification.');
        $this->assertEquals($before, $expired->fresh()->only(['status', 'valid_until', 'updated_at']), 'No quotation mutation.');
    }

    public function test_backlog_escalation_notifies_once_per_episode(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-01 08:00:00');
        $quotation = $this->quotation($owner, $organization, $store, $seller);
        $save = app(SaveFollowUpAction::class);
        $a = $save->create($seller, $organization, ['title' => 'A', 'quotation_id' => $quotation->getKey(), 'due_date' => '2026-11-01']);
        $b = $save->create($seller, $organization, ['title' => 'B', 'quotation_id' => $quotation->getKey(), 'due_date' => '2026-11-01']);

        Carbon::setTestNow('2026-11-03 10:00:00'); // 2 overdue, 2 days → warning
        $this->tick($organization);
        $this->assertSame(['warning', 0], [AttentionItem::query()->sole()->severity->value, $this->notifications()->count()]);

        Carbon::setTestNow('2026-11-08 10:00:00'); // 7 days → critical: same episode escalates and notifies once
        $this->tick($organization);
        $item = AttentionItem::query()->sole();
        $this->assertSame(['critical', 1, 1], [$item->severity->value, $item->episode, $this->notifications()->count()]);
        $this->assertSame('Suivis en retard sur le devis '.$quotation->quotation_number, $this->notifications()->sole()->title);

        // critical → warning → critical within the same episode: no second notification.
        foreach ([$a, $b] as $followUp) {
            $save->update($seller, $organization, $followUp->fresh(), ['due_date' => '2026-11-07']);
        }
        Carbon::setTestNow('2026-11-08 10:15:00');
        $this->tick($organization);
        $this->assertSame('warning', AttentionItem::query()->sole()->severity->value);
        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->tick($organization);
        $this->assertSame(['critical', 1], [AttentionItem::query()->sole()->severity->value, $this->notifications()->count()]);
    }

    public function test_snooze_dismiss_resolve_and_new_episodes(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $snoozed = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-05');
        $dismissed = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-05');
        app(QuotationAttentionDetector::class)->scanOrganization($organization);
        $item = fn (Quotation $quotation) => AttentionItem::query()->where('quotation_id', $quotation->getKey())->whereNotNull('active_key')->first();

        // Snoozed / dismissed before the notifier reached them: nothing sent.
        $this->actingAs($seller)->post(route('attention.snooze', $item($snoozed)), ['preset' => 'tomorrow'])->assertSessionHasNoErrors();
        $this->actingAs($seller)->post(route('attention.dismiss', $item($dismissed)))->assertSessionHasNoErrors();
        app(AttentionNotifier::class)->notifyOrganization($organization);
        $this->assertSame(0, $this->notifications()->count());

        // The never-notified snoozed episode notifies once it returns open while still critical.
        Carbon::setTestNow('2026-11-11 09:30:00');
        $this->tick($organization);
        $this->assertSame(1, $this->notifications()->count());

        // Already notified → snoozed → returns: no second notification.
        $this->actingAs($seller)->post(route('attention.snooze', $item($snoozed)), ['preset' => 'tomorrow'])->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-11-12 09:30:00');
        $this->tick($organization);
        $this->assertSame(1, $this->notifications()->count());
        $this->assertSame('open', $item($snoozed)->status->value);

        // Resolved (accepted) → no notification; a genuinely new episode notifies again.
        DB::table('quotations')->where('id', $snoozed->getKey())->update(['status' => 'accepted']);
        Carbon::setTestNow('2026-11-12 09:45:00');
        $this->tick($organization);
        $this->assertNull($item($snoozed));
        DB::table('quotations')->where('id', $snoozed->getKey())->update(['status' => 'expired']); // e.g. decision reverted
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->tick($organization);
        $this->assertSame(2, $item($snoozed)->episode);
        $this->assertSame(2, $this->notifications()->count());
        $this->assertSame(0, $this->notifications()->where('source_id', (string) AttentionItem::query()->where('quotation_id', $dismissed->getKey())->value('id'))->count());
    }

    public function test_preferences_recipient_rules_and_stale_items(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');

        // Sales notifications disabled: processed (no retry loop), not delivered, item stays open.
        $preference = new UserNotificationPreference;
        $preference->user_id = $seller->getKey();
        $preference->disabled_categories = ['sales'];
        $preference->save();
        $muted = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-05');

        // Issuer and creator both without access to the store: W4.3 gives it to one fallback manager (the owner here), no broadcast.
        $orphan = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-05');
        $outsider = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $outsider);
        DB::table('quotations')->where('id', $orphan->getKey())->update(['issued_by_user_id' => $outsider->getKey(), 'created_by_user_id' => $outsider->getKey()]);
        StoreMembership::query()->where('store_id', $store->getKey())->where('user_id', $outsider->getKey())->delete();

        $this->tick($organization);
        $this->assertSame([(int) $owner->getKey()], $this->notifications()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $item = AttentionItem::query()->where('quotation_id', $muted->getKey())->sole();
        $this->assertSame(['open', (int) $seller->getKey()], [$item->status->value, (int) $item->assigned_user_id]);
        $this->assertNotNull($item->notification_processed_at);
        $this->assertNull($item->notified_at);
        $this->assertSame('fallback', AttentionItem::query()->where('quotation_id', $orphan->getKey())->sole()->assignment_source);

        // Stale before the next scan: decided after detection → not notified, not processed.
        $stale = $this->quotation($owner, $organization, $store, $owner, 'issued', '2026-11-05');
        app(QuotationAttentionDetector::class)->scanOrganization($organization);
        DB::table('quotations')->where('id', $stale->getKey())->update(['status' => 'accepted']);
        app(AttentionNotifier::class)->notifyOrganization($organization);
        $this->assertSame(0, $this->notifications()->where('source_id', (string) AttentionItem::query()->where('quotation_id', $stale->getKey())->value('id'))->count());
        $this->assertNull(AttentionItem::query()->where('quotation_id', $stale->getKey())->sole()->notification_processed_at);
    }

    public function test_notification_target_rechecks_access_and_ordering_is_by_priority(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $recent = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-08');  // critical, 2 days
        $older = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-10-31');   // critical, 10 days
        $warning = $this->quotation($owner, $organization, $store, $seller, 'issued', '2026-11-11'); // expiring, warning
        $this->artisan('attention:scan-quotations')->expectsOutputToContain('2 critical notification(s)')->assertSuccessful();

        $this->actingAs($seller)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.quotation.id', (int) $older->getKey())
            ->where('items.data.1.quotation.id', (int) $recent->getKey())
            ->where('items.data.2.quotation.id', (int) $warning->getKey())
            ->where('items.data.0.notified_at', fn ($value) => $value !== null));

        $url = $this->notifications($seller)->where('source_id', (string) AttentionItem::query()->where('quotation_id', $older->getKey())->value('id'))->sole()->action_url;
        $this->actingAs($seller)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items.total', 1)->where('items.data.0.quotation.id', (int) $older->getKey()));

        // Someone without the quotation's store sees nothing through the same URL.
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $other = User::factory()->create();
        $this->addOrganizationMember($organization, $other, ['quotations.view', 'follow_ups.view']);
        $this->addStoreMember($storeB, $other);
        $this->activate($other, $organization, $storeB);
        $this->actingAs($other)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
    }
}
