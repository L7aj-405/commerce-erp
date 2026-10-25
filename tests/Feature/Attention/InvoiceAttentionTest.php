<?php

namespace Tests\Feature\Attention;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Models\AttentionItem;
use App\Models\FinancialAccount;
use App\Models\FollowUp;
use App\Models\Invoice;
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

/** W4.4: invoice / payment attention on the shared Attention Engine. */
class InvoiceAttentionTest extends QuotationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * An issued invoice with its full balance outstanding (the fixture pays to satisfy the
     * fully-paid issuance rule, then reverses that payment — as a post-issue reversal would).
     *
     * @return array{User, Organization, Store, SalesOrder, Invoice, FinancialAccount}
     */
    private function outstandingInvoice(string $timezone = 'UTC', string $total = '100.0000'): array
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        [$owner, $organization, $store, $order] = $this->documentFixture(total: $total);
        $organization->settings = ['timezone' => $timezone];
        $organization->save();
        $this->activate($owner, $organization, $store);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization, ['name' => 'Caisse', 'code' => 'CAISSE']);

        return [$owner, $organization->fresh(), $store, $order->fresh(), $invoice->fresh(), $account];
    }

    private function tick(Organization $organization, string $at): void
    {
        Carbon::setTestNow($at);
        app(AttentionScanner::class)->scanOrganization($organization->fresh(), CarbonImmutable::now('UTC'));
    }

    /** @return list<string> "type:status" of active items for the invoice */
    private function active(Invoice $invoice): array
    {
        return AttentionItem::query()->where('invoice_id', $invoice->getKey())->whereNotNull('active_key')->orderBy('id')->get()
            ->map(fn (AttentionItem $item) => $item->type->value.':'.$item->status->value)->all();
    }

    public function test_due_date_is_stamped_once_at_issue(): void
    {
        [, , , , $invoice] = $this->outstandingInvoice();
        $this->assertSame($invoice->invoice_date->copy()->addDays(30)->toDateString(), $invoice->due_date->toDateString());

        config()->set('documents.invoice.payment_term_days', 10);
        $this->assertSame($invoice->invoice_date->copy()->addDays(30)->toDateString(), $invoice->fresh()->due_date->toDateString(), 'A config change never moves an issued due date.');
    }

    public function test_payment_state_drives_type_transitions_and_episodes(): void
    {
        [$owner, $organization, , $order, $invoice, $account] = $this->outstandingInvoice();
        $due = $invoice->due_date->toDateString(); // 2026-12-01

        $this->tick($organization, '2026-11-20 10:00:00');
        $this->assertSame([], $this->active($invoice), 'Outside the 2-day window.');

        $this->tick($organization, CarbonImmutable::parse($due)->subDays(2)->setTime(10, 0)->toDateTimeString());
        $this->assertSame(['invoice_due_soon:open'], $this->active($invoice));
        $this->assertSame('warning', AttentionItem::query()->sole()->severity->value);

        $this->tick($organization, CarbonImmutable::parse($due)->addDay()->setTime(10, 0)->toDateTimeString());
        $this->assertSame(['invoice_overdue_unpaid:open'], $this->active($invoice), 'Due soon resolves, overdue opens — never both.');
        $this->assertSame('resolved', AttentionItem::query()->where('type', 'invoice_due_soon')->sole()->status->value);

        // A partial payment: unpaid resolves, partial opens (with the exact Decimal balance).
        $partial = $this->recordPayment($owner, $order, $account, '40.0000');
        $this->tick($organization, CarbonImmutable::parse($due)->addDay()->setTime(10, 15)->toDateTimeString());
        $this->assertSame(['invoice_overdue_partial:open'], $this->active($invoice));
        $item = AttentionItem::query()->where('type', 'invoice_overdue_partial')->sole();
        $this->assertSame('60.0000', $item->metadata['facts']['outstanding']);
        $this->assertStringContainsString('reste 60.00 MAD', $item->summary);

        // Exact Decimal comparisons: 0.0001 still outstanding, then fully paid resolves everything.
        $almost = $this->recordPayment($owner, $order, $account, '59.9999');
        $this->tick($organization, CarbonImmutable::parse($due)->addDay()->setTime(10, 30)->toDateTimeString());
        $this->assertSame(['invoice_overdue_partial:open'], $this->active($invoice));
        $this->recordPayment($owner, $order, $account, '0.0001');
        $this->tick($organization, CarbonImmutable::parse($due)->addDay()->setTime(10, 45)->toDateTimeString());
        $this->assertSame([], $this->active($invoice));

        // A reversed payment makes the condition genuinely reappear: new episode.
        app(ReversePaymentAction::class)->execute($owner, $almost, 'Chèque impayé');
        $this->tick($organization, CarbonImmutable::parse($due)->addDay()->setTime(11, 0)->toDateTimeString());
        $this->assertSame(['invoice_overdue_partial:open'], $this->active($invoice));
        $this->assertSame(2, AttentionItem::query()->where('type', 'invoice_overdue_partial')->whereNotNull('active_key')->sole()->episode);
        $this->assertNotNull($partial->fresh());
    }

    public function test_paid_draft_and_superseded_invoices_are_never_flagged(): void
    {
        [$owner, $organization, $store, , $outstanding] = $this->outstandingInvoice();
        // Paid at issue (the normal flow): never flagged.
        [, , , $paidOrder] = [$owner, $organization, $store, $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-11-01'])];
        $this->addCustomLine($owner, $paidOrder, ['description' => 'Service', 'unit_price_excl_tax' => '50.0000']);
        $paidOrder = app(\App\Actions\Sales\ConfirmSalesOrderAction::class)->execute($owner, $paidOrder)->fresh();
        $this->recordPayment($owner, $paidOrder, $this->createFinancialAccount($organization, ['name' => 'B', 'code' => 'B']), app(\App\Services\SalesOrderPaymentCalculator::class)->remainingAmount($paidOrder));
        $paid = app(\App\Actions\Documents\IssueInvoiceAction::class)->execute($owner, $this->createInvoice($owner, $paidOrder))->fresh();
        DB::table('invoices')->where('id', $outstanding->getKey())->update(['status' => 'superseded']);

        $this->tick($organization, '2026-12-05 10:00:00');
        // (Its sales order, now without an issued invoice and still owing, is W4.5 order attention.)
        $this->assertSame(0, AttentionItem::query()->where('subject_type', 'invoice')->count());
        $this->assertSame('issued', $paid->fresh()->status->value, 'Detection never mutates invoices.');
    }

    public function test_invoice_linked_follow_ups_validate_and_feed_the_backlog(): void
    {
        [$owner, $organization, $store, , $invoice] = $this->outstandingInvoice();
        $save = app(SaveFollowUpAction::class);
        $other = $this->createCustomer($organization, 'Other');

        $followUp = $save->create($owner, $organization, ['title' => 'Relancer', 'invoice_id' => $invoice->getKey(), 'due_date' => '2026-11-02']);
        $this->assertSame([(int) $store->getKey(), (int) $invoice->customer_id], [(int) $followUp->store_id, (int) $followUp->customer_id], 'Store and customer derive from the invoice.');
        foreach ([['invoice_id' => $invoice->getKey(), 'customer_id' => $other->getKey()], ['invoice_id' => $invoice->getKey(), 'quotation_id' => $this->createQuotation($owner, $organization, $store)->getKey()]] as $bad) {
            try {
                $save->create($owner, $organization, ['title' => 'X', ...$bad]);
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $noInvoices = User::factory()->create();
        $this->addOrganizationMember($organization, $noInvoices, ['follow_ups.view', 'follow_ups.create', 'follow_ups.update']);
        $this->addStoreMember($store, $noInvoices);
        $this->expectValidation(fn () => $save->create($noInvoices, $organization, ['title' => 'X', 'invoice_id' => $invoice->getKey()]), 'invoice_id');

        $second = $save->create($owner, $organization, ['title' => 'Relancer 2', 'invoice_id' => $invoice->getKey(), 'due_date' => '2026-11-02']);
        $done = $save->create($owner, $organization, ['title' => 'Fait', 'invoice_id' => $invoice->getKey(), 'due_date' => '2026-11-02']);
        app(TransitionFollowUpAction::class)->execute($owner, $organization, $done, ['transition' => 'complete']);
        $save->create($owner, $organization, ['title' => 'Client seulement', 'customer_id' => $invoice->customer_id, 'due_date' => '2026-11-01']);

        $this->tick($organization, '2026-11-04 10:00:00'); // 2 open overdue, 2 days
        $backlog = AttentionItem::query()->where('type', 'invoice_payment_followup_backlog')->sole();
        $this->assertSame(['warning', 2], [$backlog->severity->value, $backlog->metadata['facts']['count']]);

        app(TransitionFollowUpAction::class)->execute($owner, $organization, $followUp->fresh(), ['transition' => 'cancel']);
        app(TransitionFollowUpAction::class)->execute($owner, $organization, $second->fresh(), ['transition' => 'complete']);
        $this->tick($organization, '2026-11-04 10:15:00');
        $this->assertSame('resolved', $backlog->fresh()->status->value);

        $this->actingAs($owner)->get(route('follow-ups.index', ['tab' => 'all', 'assigned_to' => 'all']))->assertInertia(fn (Assert $page) => $page
            ->where('followUps.data', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['invoice']['id'] ?? null) === (int) $invoice->getKey())));
    }

    public function test_ownership_notification_and_escalation_reuse_the_engine(): void
    {
        [$owner, $organization, $store, $order, $invoice] = $this->outstandingInvoice();
        $seller = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $seller);
        // Issuer without invoice access → originating order's creator… made the seller here.
        $noAccess = User::factory()->create();
        $this->addOrganizationMember($organization, $noAccess, ['quotations.view']);
        $this->addStoreMember($store, $noAccess);
        DB::table('invoices')->where('id', $invoice->getKey())->update(['issued_by_user_id' => $noAccess->getKey()]);
        DB::table('sales_orders')->where('id', $order->getKey())->update(['created_by_user_id' => $seller->getKey()]);

        $this->tick($organization, '2026-12-02 10:00:00');
        $item = AttentionItem::query()->sole();
        $this->assertSame(['invoice_overdue_unpaid', 'critical', (int) $seller->getKey(), 'order_creator'],
            [$item->type->value, $item->severity->value, (int) $item->assigned_user_id, $item->assignment_source]);
        $notification = UserNotification::query()->where('source_type', 'attention_item')->sole();
        $this->assertSame([(int) $seller->getKey(), 'Facture en retard : '.$invoice->invoice_number], [(int) $notification->user_id, $notification->title]);
        $this->assertStringNotContainsString('100', $notification->message, 'No amounts in notifications.');

        $this->tick($organization, '2026-12-02 10:15:00');
        $this->assertSame(1, UserNotification::query()->where('source_type', 'attention_item')->count(), 'Once per episode.');

        // Two local days later: escalates once to the deterministic manager (the owner here).
        $this->tick($organization, '2026-12-04 00:30:00');
        $this->assertNotNull($item->fresh()->escalated_at);
        $escalation = UserNotification::query()->where('event_type', 'escalation')->sole();
        $this->assertSame([(int) $owner->getKey(), 'Attention critique non traitée : facture '.$invoice->invoice_number], [(int) $escalation->user_id, $escalation->title]);
    }

    public function test_follow_up_bridge_suppresses_escalation_and_respects_preferences(): void
    {
        [$owner, $organization, $store, , $invoice] = $this->outstandingInvoice();
        $preference = new UserNotificationPreference;
        $preference->user_id = $owner->getKey();
        $preference->disabled_categories = ['sales'];
        $preference->save();

        $this->tick($organization, '2026-12-02 10:00:00');
        $item = AttentionItem::query()->sole();
        $this->assertSame((int) $owner->getKey(), (int) $item->assigned_user_id);
        $this->assertNotNull($item->notification_processed_at);
        $this->assertNull($item->notified_at, 'Sales disabled: processed, not delivered.');

        $this->actingAs($owner)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.invoice.id', (int) $invoice->getKey())->where('items.data.0.suggestion.title', 'Relancer le paiement — facture '.$invoice->invoice_number));
        $this->actingAs($owner)->post(route('attention.follow-up', $item), ['title' => 'Appeler le client', 'due_date' => '2026-12-02', 'quotation_id' => 999])->assertSessionHasNoErrors();
        $followUp = FollowUp::query()->sole();
        $this->assertSame([(int) $invoice->getKey(), null, (int) $store->getKey(), (int) $invoice->customer_id],
            [(int) $followUp->invoice_id, $followUp->quotation_id, (int) $followUp->store_id, (int) $followUp->customer_id]);

        $this->tick($organization, '2026-12-05 10:00:00');
        $this->assertNull($item->fresh()->escalated_at, 'A follow-up created from the item suppresses escalation.');
        $this->assertSame('open', $item->fresh()->status->value, 'The condition still holds.');
    }

    public function test_visibility_store_isolation_navigation_and_timezone(): void
    {
        [$owner, $organization, $store, , $invoice] = $this->outstandingInvoice('Asia/Tokyo');
        $due = $invoice->due_date->toDateString();

        // Overdue only once the Tokyo calendar day after the due date starts.
        $this->tick($organization, CarbonImmutable::parse($due, 'Asia/Tokyo')->endOfDay()->utc()->toDateTimeString());
        $this->assertSame(['invoice_due_soon:open'], $this->active($invoice));
        $this->tick($organization, CarbonImmutable::parse($due, 'Asia/Tokyo')->addDay()->startOfDay()->addMinute()->utc()->toDateTimeString());
        $this->assertSame(['invoice_overdue_unpaid:open'], $this->active($invoice));
        $item = AttentionItem::query()->whereNotNull('active_key')->sole();

        // quotations.view alone does not show invoice attention.
        $quotesOnly = User::factory()->create();
        $this->addOrganizationMember($organization, $quotesOnly, ['quotations.view', 'follow_ups.view']);
        $this->addStoreMember($store, $quotesOnly);
        $this->activate($quotesOnly, $organization, $store);
        $this->actingAs($quotesOnly)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
        $this->actingAs($quotesOnly)->post(route('attention.claim', $item))->assertForbidden();

        // Invoice viewer of another store only: nothing; manage_all does not widen it.
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $managerB = User::factory()->create();
        $this->addOrganizationMember($organization, $managerB, ['invoices.view', 'follow_ups.view', 'follow_ups.manage_all']);
        $this->addStoreMember($storeB, $managerB);
        $this->activate($managerB, $organization, $storeB);
        $this->actingAs($managerB)->get(route('attention.index'))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));

        // Safe cross-store opening for an authorized member working in another store.
        $this->activate($owner, $organization, $storeB);
        $this->actingAs($owner)->post(route('attention.open-invoice', $item))->assertRedirect(route('invoices.show', $invoice->getKey()));
        $this->assertSame((int) $store->getKey(), (int) $owner->fresh()->active_store_id);

        // Another organization never reaches it.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Foreign');
        $this->activate($foreignOwner, $foreign, $this->createStore($foreign, $foreignOwner));
        $this->actingAs($foreignOwner)->post(route('attention.open-invoice', $item))->assertNotFound();
    }

    public function test_sales_reset_removes_invoice_attention_and_unlinks_follow_ups(): void
    {
        [$owner, $organization, , , $invoice] = $this->outstandingInvoice();
        $followUp = app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Relancer', 'invoice_id' => $invoice->getKey()]);
        $this->tick($organization, '2026-12-02 10:00:00');
        $this->assertSame(1, AttentionItem::query()->count());

        app(OrganizationDataResetExecutor::class)->execute($organization, $owner,
            app(OrganizationDataResetPreviewService::class)->preview($organization, OrganizationDataResetScope::SalesAndDocuments));
        $this->assertSame(0, AttentionItem::query()->count());
        $this->assertNull($followUp->fresh()->invoice_id);
        $this->assertNotNull($followUp->fresh(), 'Follow-ups survive; only the link goes.');
    }

    private function expectValidation(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
