<?php

namespace Tests\Feature\Attention;

use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\AttentionItem;
use App\Models\FinancialAccount;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserNotificationPreference;
use App\Services\Attention\AttentionScanner;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W4.5: Sales Order receivables attention on the shared Attention Engine. */
class SalesOrderAttentionTest extends QuotationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Organization, Store, FinancialAccount} */
    private function fixture(string $timezone = 'UTC'): array
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['timezone' => $timezone];
        $organization->save();
        $store = $this->createStore($organization, $owner, 'Store A');
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization->fresh(), ['name' => 'Caisse', 'code' => 'CAISSE']);

        return [$owner, $organization->fresh(), $store, $account];
    }

    /** A confirmed order (sale_date 2026-11-01 → due 2026-11-08 with the 7-day term). */
    private function order(User $actor, Organization $organization, Store $store, string $total = '100.0000', ?User $creator = null): SalesOrder
    {
        $customer = $this->createCustomer($organization, 'ACME');
        $order = $this->createDraftOrder($creator ?? $actor, $organization, $store, $customer, ['sale_date' => '2026-11-01']);
        $this->addCustomLine($creator ?? $actor, $order, ['unit_price_excl_tax' => $total]);
        $this->activate($actor, $organization, $store);

        return app(ConfirmSalesOrderAction::class)->execute($actor, $order)->fresh();
    }

    private function tick(Organization $organization, string $at): void
    {
        Carbon::setTestNow($at);
        app(AttentionScanner::class)->scanOrganization($organization->fresh(), CarbonImmutable::now('UTC'));
    }

    /** @return list<string> */
    private function active(SalesOrder $order): array
    {
        return AttentionItem::query()->where('sales_order_id', $order->getKey())->whereNotNull('active_key')->orderBy('id')->get()
            ->map(fn (AttentionItem $item) => $item->type->value.':'.$item->status->value)->all();
    }

    public function test_due_date_is_stamped_at_confirmation_and_drafts_or_cancelled_orders_are_ignored(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $order = $this->order($owner, $organization, $store);
        $this->assertSame('2026-11-08', $order->due_date->toDateString());
        $draft = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-10-01']);
        $cancelled = $this->order($owner, $organization, $store);
        DB::table('sales_orders')->where('id', $cancelled->getKey())->update(['status' => 'cancelled']);

        $this->tick($organization, '2026-11-20 10:00:00');
        $this->assertSame(['sales_order_unpaid_overdue:open'], $this->active($order));
        $this->assertSame([], $this->active($draft));
        $this->assertSame([], $this->active($cancelled));
        $this->assertNull($draft->fresh()->due_date);
        $this->assertSame('2026-11-08', $order->fresh()->due_date->toDateString(), 'Never mutated by detection.');
    }

    public function test_payments_drive_one_receivable_state_then_paid_not_invoiced_until_invoiced(): void
    {
        [$owner, $organization, $store, $account] = $this->fixture();
        $order = $this->order($owner, $organization, $store);

        $this->tick($organization, '2026-11-05 10:00:00');
        $this->assertSame([], $this->active($order), 'Three days before due: outside the window.');
        $this->tick($organization, '2026-11-06 10:00:00');
        $this->assertSame(['sales_order_payment_due_soon:open'], $this->active($order));
        $this->tick($organization, '2026-11-09 10:00:00');
        $this->assertSame(['sales_order_unpaid_overdue:open'], $this->active($order));

        $this->recordPayment($owner, $order, $account, '40.0000');
        $this->tick($organization, '2026-11-09 10:15:00');
        $this->assertSame(['sales_order_partial_overdue:open'], $this->active($order));
        $this->assertSame('60.0000', AttentionItem::query()->where('type', 'sales_order_partial_overdue')->sole()->metadata['facts']['outstanding']);

        $almost = $this->recordPayment($owner, $order, $account, '59.9999');
        $this->tick($organization, '2026-11-09 10:30:00');
        $this->assertSame(['sales_order_partial_overdue:open'], $this->active($order), '0.0001 still owed (exact Decimal).');
        $last = $this->recordPayment($owner, $order, $account, '0.0001');
        $this->tick($organization, '2026-11-09 10:45:00');
        $this->assertSame([], $this->active($order), 'Fully paid: receivable resolves; paid-not-invoiced waits a local day.');

        $this->tick($organization, '2026-11-10 10:00:00');
        $this->assertSame(['sales_order_paid_not_invoiced:open'], $this->active($order));
        $this->assertSame('warning', AttentionItem::query()->where('type', 'sales_order_paid_not_invoiced')->sole()->severity->value);

        // A reversal makes it owe again: paid-not-invoiced resolves, a new partial episode opens.
        app(ReversePaymentAction::class)->execute($owner, $last, 'Chèque impayé');
        $this->tick($organization, '2026-11-10 10:15:00');
        $this->assertSame(['sales_order_partial_overdue:open'], $this->active($order));
        $this->assertSame(2, AttentionItem::query()->where('type', 'sales_order_partial_overdue')->whereNotNull('active_key')->sole()->episode);

        // Paid again and invoiced: everything resolves (post-issuance anomalies are W4.4's job).
        $this->recordPayment($owner, $order, $account, '0.0001');
        app(IssueInvoiceAction::class)->execute($owner, $this->createInvoice($owner, $order->fresh()));
        $this->tick($organization, '2026-11-12 10:00:00');
        $this->assertSame([], $this->active($order));
        $this->assertNotNull($almost->fresh());
    }

    public function test_pos_orders_never_raise_paid_not_invoiced(): void
    {
        [$owner, $organization, $store, $account] = $this->fixture();
        $manual = $this->order($owner, $organization, $store);
        $pos = $this->order($owner, $organization, $store);
        DB::table('sales_orders')->where('id', $pos->getKey())->update(['source' => 'pos']);
        $this->recordPayment($owner, $manual, $account, '100.0000');
        $this->recordPayment($owner, $pos->fresh(), $account, '100.0000');

        $this->tick($organization, '2026-11-05 10:00:00');
        $this->assertSame(['sales_order_paid_not_invoiced:open'], $this->active($manual));
        $this->assertSame([], $this->active($pos));
    }

    public function test_order_linked_follow_ups_validate_and_feed_the_backlog(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $order = $this->order($owner, $organization, $store);
        $save = app(SaveFollowUpAction::class);
        $other = $this->createCustomer($organization, 'Other');

        $a = $save->create($owner, $organization, ['title' => 'A', 'sales_order_id' => $order->getKey(), 'due_date' => '2026-11-02']);
        $this->assertSame([(int) $store->getKey(), (int) $order->customer_id], [(int) $a->store_id, (int) $a->customer_id]);
        foreach ([['customer_id' => $other->getKey()], ['quotation_id' => $this->createQuotation($owner, $organization, $store)->getKey()]] as $bad) {
            try {
                $save->create($owner, $organization, ['title' => 'X', 'sales_order_id' => $order->getKey(), ...$bad]);
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $noOrders = User::factory()->create();
        $this->addOrganizationMember($organization, $noOrders, ['follow_ups.view', 'follow_ups.create', 'follow_ups.update']);
        $this->addStoreMember($store, $noOrders);
        try {
            $save->create($noOrders, $organization, ['title' => 'X', 'sales_order_id' => $order->getKey()]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sales_order_id', $exception->errors());
        }

        $b = $save->create($owner, $organization, ['title' => 'B', 'sales_order_id' => $order->getKey(), 'due_date' => '2026-11-02']);
        $done = $save->create($owner, $organization, ['title' => 'Fait', 'sales_order_id' => $order->getKey(), 'due_date' => '2026-11-02']);
        app(TransitionFollowUpAction::class)->execute($owner, $organization, $done, ['transition' => 'complete']);
        $save->create($owner, $organization, ['title' => 'Client seulement', 'customer_id' => $order->customer_id, 'due_date' => '2026-11-01']);

        $this->tick($organization, '2026-11-04 10:00:00');
        $backlog = AttentionItem::query()->where('type', 'sales_order_payment_followup_backlog')->sole();
        $this->assertSame(['warning', 2], [$backlog->severity->value, $backlog->metadata['facts']['count']]);
        foreach ([$a, $b] as $followUp) {
            app(TransitionFollowUpAction::class)->execute($owner, $organization, $followUp->fresh(), ['transition' => 'complete']);
        }
        $this->tick($organization, '2026-11-04 10:15:00');
        $this->assertSame('resolved', $backlog->fresh()->status->value);
    }

    public function test_ownership_notifications_and_escalation(): void
    {
        [$owner, $organization, $store, $account] = $this->fixture();
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);
        $order = $this->order($owner, $organization, $store, creator: $seller);

        $this->tick($organization, '2026-11-09 10:00:00');
        $item = AttentionItem::query()->sole();
        $this->assertSame([(int) $seller->getKey(), 'creator', 'critical'], [(int) $item->assigned_user_id, $item->assignment_source, $item->severity->value]);
        $notification = UserNotification::query()->where('source_type', 'attention_item')->sole();
        $this->assertSame([(int) $seller->getKey(), 'Commande impayée en retard : '.$order->order_number], [(int) $notification->user_id, $notification->title]);
        $this->assertStringNotContainsString('100', $notification->message, 'No amounts in notifications.');
        $this->tick($organization, '2026-11-09 10:15:00');
        $this->assertSame(1, UserNotification::query()->where('source_type', 'attention_item')->count());

        // Two local days after first critical: one escalation to the deterministic manager.
        $this->tick($organization, '2026-11-11 00:30:00');
        $this->assertSame([(int) $owner->getKey(), 'Attention critique non traitée : commande '.$order->order_number],
            [(int) UserNotification::query()->where('event_type', 'escalation')->sole()->user_id, UserNotification::query()->where('event_type', 'escalation')->sole()->title]);

        // A reversal-free partial payment opens a new critical episode that notifies again.
        $this->recordPayment($owner, $order, $account, '10.0000');
        $this->tick($organization, '2026-11-11 00:45:00');
        $this->assertSame(2, UserNotification::query()->where('user_id', $seller->getKey())->where('event_type', 'critical')->count());
    }

    public function test_preferences_fallback_follow_up_bridge_and_warning_types_never_notify(): void
    {
        [$owner, $organization, $store, $account] = $this->fixture();
        $preference = new UserNotificationPreference;
        $preference->user_id = $owner->getKey();
        $preference->disabled_categories = ['sales'];
        $preference->save();
        $gone = User::factory()->create(); // creator / confirmer without access → fallback manager (the owner)
        $order = $this->order($owner, $organization, $store);
        DB::table('sales_orders')->where('id', $order->getKey())->update(['created_by_user_id' => $gone->getKey(), 'confirmed_by_user_id' => $gone->getKey()]);
        $paid = $this->order($owner, $organization, $store);
        $this->recordPayment($owner, $paid, $account, '100.0000');

        $this->tick($organization, '2026-11-09 10:00:00');
        $item = AttentionItem::query()->where('sales_order_id', $order->getKey())->sole();
        $this->assertSame([(int) $owner->getKey(), 'fallback'], [(int) $item->assigned_user_id, $item->assignment_source]);
        $this->assertNotNull($item->notification_processed_at);
        $this->assertNull($item->notified_at, 'Sales disabled: processed, not delivered.');
        $this->assertSame(['sales_order_paid_not_invoiced:open'], $this->active($paid));
        $this->assertSame(0, UserNotification::query()->count(), 'Warnings never notify.');

        $this->actingAs($owner)->post(route('attention.follow-up', $item), ['title' => 'Appeler', 'due_date' => '2026-11-09', 'invoice_id' => 999])->assertSessionHasNoErrors();
        $followUp = FollowUp::query()->sole();
        $this->assertSame([(int) $order->getKey(), null, (int) $store->getKey()], [(int) $followUp->sales_order_id, $followUp->invoice_id, (int) $followUp->store_id]);
        $this->tick($organization, '2026-11-12 10:00:00');
        $this->assertNull($item->fresh()->escalated_at, 'Follow-up created from the item suppresses escalation.');
        $this->assertNull(AttentionItem::query()->where('sales_order_id', $paid->getKey())->sole()->escalated_at, 'Warnings never escalate.');
    }

    public function test_visibility_store_isolation_navigation_and_timezone(): void
    {
        [$owner, $organization, $store] = $this->fixture('Asia/Tokyo');
        $order = $this->order($owner, $organization, $store);

        $this->tick($organization, CarbonImmutable::parse('2026-11-08', 'Asia/Tokyo')->endOfDay()->utc()->toDateTimeString());
        $this->assertSame(['sales_order_payment_due_soon:open'], $this->active($order));
        $this->tick($organization, CarbonImmutable::parse('2026-11-09', 'Asia/Tokyo')->addMinute()->utc()->toDateTimeString());
        $this->assertSame(['sales_order_unpaid_overdue:open'], $this->active($order));
        $item = AttentionItem::query()->whereNotNull('active_key')->sole();

        $invoicesOnly = User::factory()->create();
        $this->addOrganizationMember($organization, $invoicesOnly, ['invoices.view', 'quotations.view', 'follow_ups.view']);
        $this->addStoreMember($store, $invoicesOnly);
        $this->activate($invoicesOnly, $organization, $store);
        $this->actingAs($invoicesOnly)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));

        $storeB = $this->createStore($organization, $owner, 'Store B');
        $managerB = User::factory()->create();
        $this->addOrganizationMember($organization, $managerB, ['sales_orders.view', 'follow_ups.view', 'follow_ups.manage_all']);
        $this->addStoreMember($storeB, $managerB);
        $this->activate($managerB, $organization, $storeB);
        $this->actingAs($managerB)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));

        $this->activate($owner, $organization, $storeB);
        $this->actingAs($owner)->get(route('attention.index', ['domain' => 'sales_order']))->assertInertia(fn (Assert $page) => $page
            ->where('items.total', 1)->where('items.data.0.sales_order.id', (int) $order->getKey()));
        $this->actingAs($owner)->post(route('attention.open-sales-order', $item))->assertRedirect(route('sales.orders.show', $order->getKey()));
        $this->assertSame((int) $store->getKey(), (int) $owner->fresh()->active_store_id);

        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign');
        $this->activate($foreignOwner, $foreign, $this->createStore($foreign, $foreignOwner));
        $this->actingAs($foreignOwner)->post(route('attention.open-sales-order', $item))->assertNotFound();
    }

    public function test_sales_reset_removes_order_attention_and_unlinks_follow_ups(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $order = $this->order($owner, $organization, $store);
        $followUp = app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Relancer', 'sales_order_id' => $order->getKey()]);
        $this->tick($organization, '2026-11-09 10:00:00');
        $this->assertSame(1, AttentionItem::query()->count());

        app(OrganizationDataResetExecutor::class)->execute($organization, $owner,
            app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::SalesAndDocuments));
        $this->assertSame(0, AttentionItem::query()->count());
        $this->assertNull($followUp->fresh()->sales_order_id);
    }
}
