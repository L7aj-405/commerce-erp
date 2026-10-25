<?php

namespace Tests\Feature\Attention;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Models\AttentionItem;
use App\Models\AuditLog;
use App\Models\DocumentEmailDelivery;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\Attention\QuotationAttentionDetector;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W4.1 quotation Attention Engine: detection, episodes, human actions, security. */
class QuotationAttentionTest extends QuotationTestCase
{
    private int $sequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, User} owner, organization, store, seller */
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

    /** A quotation in a given lifecycle state (state set directly: this tests detection, not issuing). */
    private function quotation(User $owner, Organization $organization, Store $store, string $status = 'issued', ?string $validUntil = null, array $data = []): Quotation
    {
        $this->activate($owner, $organization, $store);
        $quotation = $this->createQuotation($owner, $organization, $store, $data);
        DB::table('quotations')->where('id', $quotation->getKey())->update([
            'status' => $status, 'quotation_number' => 'DEV-'.(++$this->sequence), 'valid_until' => $validUntil,
        ]);

        return $quotation->fresh();
    }

    private function sent(Quotation $quotation, string $at, string $status = DocumentEmailDelivery::STATUS_SENT): void
    {
        $delivery = new DocumentEmailDelivery;
        $delivery->organization_id = $quotation->organization_id;
        $delivery->document_type = Quotation::class;
        $delivery->document_id = $quotation->getKey();
        $delivery->to_recipients = ['client@example.test'];
        $delivery->subject = 'Devis';
        $delivery->status = $status;
        $delivery->sent_at = $status === DocumentEmailDelivery::STATUS_SENT ? $at : null;
        $delivery->save();
    }

    private function scan(Organization $organization): array
    {
        return app(QuotationAttentionDetector::class)->scanOrganization($organization->fresh());
    }

    /** @return list<string> "type:quotation_id:status" of active (or all) items */
    private function items(bool $activeOnly = true): array
    {
        return AttentionItem::query()->when($activeOnly, fn ($q) => $q->whereNotNull('active_key'))->orderBy('id')->get()
            ->map(fn (AttentionItem $item) => $item->type->value.':'.$item->quotation_id.':'.$item->status->value)->all();
    }

    public function test_sent_undecided_uses_successful_sends_only_and_ignores_decided_versions(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $old = $this->quotation($owner, $organization, $store);
        $this->sent($old, '2026-11-07 09:00:00');
        $recent = $this->quotation($owner, $organization, $store);
        $this->sent($recent, '2026-11-08 09:00:00');
        $failedOnly = $this->quotation($owner, $organization, $store);
        $this->sent($failedOnly, '2026-11-01 09:00:00', DocumentEmailDelivery::STATUS_FAILED);
        foreach (['accepted', 'rejected', 'converted', 'superseded', 'draft'] as $status) {
            $this->sent($this->quotation($owner, $organization, $store, $status), '2026-11-01 09:00:00');
        }
        $before = $old->fresh()->only(['status', 'updated_at', 'valid_until']);

        $this->scan($organization);

        $this->assertSame(["quotation_sent_undecided:{$old->getKey()}:open"], $this->items());
        $item = AttentionItem::query()->sole();
        $this->assertSame('warning', $item->severity->value);
        $this->assertSame((int) $store->getKey(), (int) $item->store_id);
        $this->assertSame('3', $item->metadata['i18n']['params']['days']);
        $this->assertEquals($before, $old->fresh()->only(['status', 'updated_at', 'valid_until']), 'Detection never mutates the quotation.');
        $this->assertSame(0, FollowUp::query()->count(), 'No automatic follow-up.');

        // Idempotent: repeated scans update the same row.
        $this->scan($organization);
        $this->assertSame(1, AttentionItem::query()->count());
    }

    public function test_day_counts_use_the_organization_timezone(): void
    {
        [$owner, $organization, $store] = $this->fixture('Asia/Tokyo');
        $quotation = $this->quotation($owner, $organization, $store);
        $this->sent($quotation, '2026-11-01 14:00:00'); // 1 Nov 23:00 Tokyo

        Carbon::setTestNow('2026-11-03 14:30:00'); // 3 Nov 23:30 Tokyo → 2 local days
        $this->scan($organization);
        $this->assertSame([], $this->items());

        Carbon::setTestNow('2026-11-03 15:30:00'); // 4 Nov 00:30 Tokyo → 3 local days
        $this->scan($organization);
        $this->assertSame(["quotation_sent_undecided:{$quotation->getKey()}:open"], $this->items());
    }

    public function test_expiring_soon_and_expired_are_distinct_and_use_validity_dates(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        Carbon::setTestNow('2026-11-07 10:00:00'); // Saturday
        $monday = $this->quotation($owner, $organization, $store, 'issued', '2026-11-09');
        $tuesday = $this->quotation($owner, $organization, $store, 'issued', '2026-11-10');
        $today = $this->quotation($owner, $organization, $store, 'issued', '2026-11-07');
        $past = $this->quotation($owner, $organization, $store, 'issued', '2026-11-06'); // lazily still "issued"
        $lazyExpired = $this->quotation($owner, $organization, $store, 'expired', '2026-10-01');
        $this->quotation($owner, $organization, $store, 'accepted', '2026-11-08');

        $this->scan($organization);

        $this->assertEqualsCanonicalizing([
            "quotation_expiring_soon:{$monday->getKey()}:open",
            "quotation_expiring_soon:{$today->getKey()}:open",
            "quotation_expired_undecided:{$past->getKey()}:open",
            "quotation_expired_undecided:{$lazyExpired->getKey()}:open",
        ], $this->items());
        $this->assertSame('critical', AttentionItem::query()->where('quotation_id', $past->getKey())->sole()->severity->value);
        $this->assertSame(0, AttentionItem::query()->where('quotation_id', $tuesday->getKey())->count());

        // Next day the "today" quotation turns from expiring into expired: old episode resolves, new type opens.
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->scan($organization);
        $this->assertSame('resolved', AttentionItem::query()->where('quotation_id', $today->getKey())->where('type', 'quotation_expiring_soon')->sole()->status->value);
        $this->assertSame('open', AttentionItem::query()->where('quotation_id', $today->getKey())->where('type', 'quotation_expired_undecided')->sole()->status->value);
    }

    public function test_follow_up_backlog_counts_only_open_overdue_quotation_work_and_resolves(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-01 08:00:00');
        $customer = $this->createCustomer($organization, 'ACME');
        $two = $this->quotation($owner, $organization, $store, 'issued', null, ['customer_id' => $customer->getKey()]);
        $old = $this->quotation($owner, $organization, $store);
        $young = $this->quotation($owner, $organization, $store);
        $save = app(SaveFollowUpAction::class);
        $a = $save->create($seller, $organization, ['title' => 'A', 'quotation_id' => $two->getKey(), 'due_date' => '2026-11-09']);
        $b = $save->create($seller, $organization, ['title' => 'B', 'quotation_id' => $two->getKey(), 'due_date' => '2026-11-09']);
        $save->create($seller, $organization, ['title' => 'Old', 'quotation_id' => $old->getKey(), 'due_date' => '2026-11-07']);
        $save->create($seller, $organization, ['title' => 'Young', 'quotation_id' => $young->getKey(), 'due_date' => '2026-11-09']);
        $done = $save->create($seller, $organization, ['title' => 'Done', 'quotation_id' => $young->getKey(), 'due_date' => '2026-11-01']);
        app(TransitionFollowUpAction::class)->execute($seller, $organization, $done, ['transition' => 'complete']);
        $save->create($seller, $organization, ['title' => 'Future', 'quotation_id' => $young->getKey(), 'due_date' => '2026-12-01']);
        $save->create($seller, $organization, ['title' => 'Customer only', 'customer_id' => $customer->getKey(), 'due_date' => '2026-11-01']);

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->scan($organization);
        $this->assertEqualsCanonicalizing([
            "quotation_followup_backlog:{$two->getKey()}:open", // 2 overdue
            "quotation_followup_backlog:{$old->getKey()}:open", // 1 overdue by 3 days
        ], $this->items());

        foreach ([$a, $b] as $item) {
            app(TransitionFollowUpAction::class)->execute($seller, $organization, $item->fresh(), ['transition' => 'complete']);
        }
        Carbon::setTestNow('2026-11-10 10:15:00'); // next scan
        $this->scan($organization);
        $this->assertSame('resolved', AttentionItem::query()->where('quotation_id', $two->getKey())->sole()->status->value);
    }

    public function test_dismissal_holds_for_the_episode_and_a_new_episode_reopens(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $quotation = $this->quotation($owner, $organization, $store);
        $this->sent($quotation, '2026-11-05 09:00:00');
        $this->scan($organization);
        $item = AttentionItem::query()->sole();

        $this->actingAs($seller)->post(route('attention.dismiss', $item), ['note' => 'Client en congés', 'expected_version' => $item->version])->assertSessionHasNoErrors();
        $this->scan($organization);
        $this->assertSame(['dismissed'], AttentionItem::query()->pluck('status')->map->value->all(), 'Same unchanged condition: stays dismissed.');
        $this->assertSame('attention.dismissed', AuditLog::query()->where('event', 'like', 'attention.%')->sole()->event);

        // A new send makes the condition false (episode ends) …
        $this->sent($quotation, '2026-11-10 09:00:00');
        Carbon::setTestNow('2026-11-10 10:15:00'); // next scan
        $this->scan($organization);
        $this->assertNotNull($item->fresh()->resolved_at);
        $this->assertSame('dismissed', $item->fresh()->status->value);
        $this->assertSame([], $this->items());

        // … and its reappearance three days later is a new episode.
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->scan($organization);
        $episode = AttentionItem::query()->whereNotNull('active_key')->sole();
        $this->assertSame([2, 'open'], [$episode->episode, $episode->status->value]);
    }

    public function test_snoozed_item_returns_only_while_the_condition_holds(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture('Africa/Casablanca');
        Carbon::setTestNow('2026-11-10 10:00:00');
        $kept = $this->quotation($owner, $organization, $store);
        $decided = $this->quotation($owner, $organization, $store);
        $this->sent($kept, '2026-11-05 09:00:00');
        $this->sent($decided, '2026-11-05 09:00:00');
        $this->scan($organization);
        foreach (AttentionItem::query()->get() as $item) {
            $this->actingAs($seller)->post(route('attention.snooze', $item), ['preset' => 'tomorrow'])->assertSessionHasNoErrors();
        }
        $this->assertSame('2026-11-11 08:00:00', AttentionItem::query()->first()->snoozed_until->utc()->format('Y-m-d H:i:s')); // 09:00 Casablanca (UTC+1)
        $this->actingAs($seller)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));

        DB::table('quotations')->where('id', $decided->getKey())->update(['status' => 'accepted']);
        Carbon::setTestNow('2026-11-11 08:30:00');
        $this->scan($organization);
        $this->assertSame('open', AttentionItem::query()->where('quotation_id', $kept->getKey())->sole()->status->value);
        $this->assertSame('resolved', AttentionItem::query()->where('quotation_id', $decided->getKey())->sole()->status->value);
        $this->actingAs($seller)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 1));
    }

    public function test_visibility_follows_tenant_store_and_quotation_permissions(): void
    {
        [$owner, $organization, $storeA, $seller] = $this->fixture();
        $storeB = $this->createStore($organization, $owner, 'Store B');
        Carbon::setTestNow('2026-11-10 10:00:00');
        $inB = $this->quotation($owner, $organization, $storeB);
        $this->sent($inB, '2026-11-01 09:00:00');
        $this->scan($organization);
        $item = AttentionItem::query()->sole();

        // manage_all does not override store access.
        $manager = User::factory()->create();
        $this->addOrganizationMember($organization, $manager, ['follow_ups.view', 'follow_ups.manage_all', 'quotations.view']);
        $this->addStoreMember($storeA, $manager);
        $this->activate($manager, $organization, $storeA);
        $this->actingAs($manager)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
        $this->actingAs($manager)->post(route('attention.dismiss', $item))->assertForbidden();

        // No quotations.view: no Attention page.
        $clerk = User::factory()->create();
        $this->addOrganizationMember($organization, $clerk, ['follow_ups.view']);
        $this->addStoreMember($storeB, $clerk);
        $this->activate($clerk, $organization, $storeB);
        $this->actingAs($clerk)->get(route('attention.index'))->assertForbidden();

        // Store B member working in Store A: sees it and opens it through the W1.1 switch.
        $this->addStoreMember($storeB, $seller);
        $this->activate($seller, $organization, $storeA);
        $this->actingAs($seller)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page
            ->where('items.total', 1)->where('items.data.0.quotation.can_open', true));
        $this->actingAs($seller)->post(route('attention.open-quotation', $item))->assertRedirect(route('quotations.show', $inB->getKey()));
        $this->assertSame((int) $storeB->getKey(), (int) $seller->fresh()->active_store_id);

        // Another organization never sees or reaches it.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign');
        $this->activate($foreignOwner, $foreign, $this->createStore($foreign, $foreignOwner));
        $this->actingAs($foreignOwner)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
        $this->actingAs($foreignOwner)->post(route('attention.dismiss', $item))->assertNotFound();
    }

    public function test_create_follow_up_from_attention_uses_the_canonical_action(): void
    {
        [$owner, $organization, $store, $seller] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $customer = $this->createCustomer($organization, 'ACME');
        $quotation = $this->quotation($owner, $organization, $store, 'issued', null, ['customer_id' => $customer->getKey()]);
        $other = $this->quotation($owner, $organization, $store);
        $this->sent($quotation, '2026-11-01 09:00:00');
        $this->scan($organization);
        $item = AttentionItem::query()->sole();

        $this->actingAs($seller)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.suggestion.title', 'Relancer le client — devis '.$quotation->quotation_number)
            ->where('items.data.0.can_create_follow_up', true));

        // The link comes from the item, never from the browser; assignment rules still apply.
        $this->actingAs($seller)->post(route('attention.follow-up', $item), ['title' => 'Relancer', 'due_date' => '2026-11-10', 'assigned_user_id' => $owner->getKey()])->assertForbidden();
        $this->actingAs($seller)->post(route('attention.follow-up', $item), ['title' => 'Relancer', 'due_date' => '2026-11-10', 'quotation_id' => $other->getKey()])
            ->assertSessionHasNoErrors();

        $followUp = FollowUp::query()->sole();
        $this->assertSame([(int) $quotation->getKey(), (int) $customer->getKey(), (int) $store->getKey(), (int) $seller->getKey()],
            [(int) $followUp->quotation_id, (int) $followUp->customer_id, (int) $followUp->store_id, (int) $followUp->assigned_user_id]);
        $item->refresh();
        $this->assertSame('open', $item->status->value, 'Creating a follow-up does not resolve the condition.');
        $this->assertSame([(int) $followUp->getKey()], $item->metadata['follow_up_ids']);
        $this->assertSame(1, AuditLog::query()->where('event', 'attention.follow_up_created')->count());
        $this->scan($organization);
        $this->assertSame([(int) $followUp->getKey()], $item->fresh()->metadata['follow_up_ids'], 'Detector keeps the link.');

        // Without follow_ups.create the bridge is refused by SaveFollowUpAction.
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['quotations.view']);
        $this->addStoreMember($store, $viewer);
        $this->activate($viewer, $organization, $store);
        $this->actingAs($viewer)->post(route('attention.follow-up', $item), ['title' => 'X'])->assertForbidden();
    }

    public function test_scheduler_registration_and_sales_reset(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->sent($this->quotation($owner, $organization, $store), '2026-11-01 09:00:00');
        $this->artisan('attention:scan-quotations')->assertSuccessful();
        $this->assertSame(1, AttentionItem::query()->count());

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'attention:scan-quotations'));
        $this->assertSame('*/15 * * * *', $event->expression);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::SalesAndDocuments);
        app(OrganizationDataResetExecutor::class)->execute($organization, $owner, $plan);
        $this->assertSame(0, AttentionItem::query()->count());
    }
}
