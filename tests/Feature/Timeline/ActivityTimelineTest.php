<?php

namespace Tests\Feature\Timeline;

use App\Actions\Attention\HandleAttentionItemAction;
use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\FollowUps\TransitionFollowUpAction;
use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\DecideQuotationAction;
use App\Models\AttentionItem;
use App\Models\Customer;
use App\Models\DocumentEmailDelivery;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuotationTestCase;

/** W5: business activity timeline — real events only, permission/store isolation, deterministic cursor pagination. */
class ActivityTimelineTest extends QuotationTestCase
{
    private int $minute = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Advance the clock one minute per business step, so every step has its own instant. */
    private function tick(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'UTC')->addMinutes(++$this->minute));
    }

    /** @return list<array<string, mixed>> */
    private function entries(User $viewer, string $subject, int $id, array $query = []): array
    {
        $response = $this->actingAs($viewer)->getJson("/timeline/{$subject}/{$id}?".http_build_query(['per_page' => 50, ...$query]))->assertOk();

        return $response->json('entries');
    }

    /** @return list<string> */
    private function events(User $viewer, string $subject, int $id, array $query = []): array
    {
        return array_column($this->entries($viewer, $subject, $id, $query), 'event');
    }

    private function viewer(Organization $organization, array $stores, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, $permissions);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }
        $this->activate($user, $organization, $stores[0] ?? null);

        return $user;
    }

    private function email(User $sender, Quotation $quotation, string $status = DocumentEmailDelivery::STATUS_SENT): void
    {
        DB::table('document_email_deliveries')->insert([
            'organization_id' => $quotation->organization_id, 'sender_user_id' => $sender->getKey(), 'document_type' => Quotation::class,
            'document_id' => $quotation->getKey(), 'to_recipients' => json_encode(['client@example.test']), 'cc_recipients' => json_encode([]),
            'bcc_recipients' => json_encode([]), 'subject' => 'Devis', 'status' => $status, 'sent_at' => $status === 'sent' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attention(Organization $organization, SalesOrder $order): AttentionItem
    {
        $id = DB::table('attention_items')->insertGetId([
            'organization_id' => $organization->getKey(), 'store_id' => $order->store_id, 'type' => 'sales_order_unpaid_overdue',
            'subject_type' => 'sales_order', 'subject_id' => $order->getKey(), 'sales_order_id' => $order->getKey(), 'customer_id' => $order->customer_id,
            'episode' => 1, 'active_key' => 'sales_order_unpaid_overdue:sales_order:'.$order->getKey(), 'severity' => 'critical', 'status' => 'open',
            'title' => 'Impayé', 'summary' => 'Commande impayée', 'metadata' => json_encode(['document_number' => $order->order_number]),
            'detected_at' => now(), 'last_detected_at' => now(), 'critical_since' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return AttentionItem::query()->findOrFail($id);
    }

    public function test_quotation_timeline_orders_issue_email_revision_accept_and_convert(): void
    {
        $this->tick();
        [$owner, $organization, $store, $variant] = $this->quotationCatalogFixture();
        $organization->settings = ['timezone' => 'Africa/Casablanca'];
        $organization->save();
        $warehouse = $this->createWarehouse($organization);
        $this->openStock($owner, $organization, $warehouse, $variant, '50');
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '2']);
        $this->tick();
        $issued = $this->issueQuotation($owner, $quotation);
        $this->tick();
        $this->email($owner, $issued);
        $this->tick();
        $revision = $this->reviseQuotation($owner, $issued);
        $this->addCatalogQuotationLine($owner, $revision, $variant, ['quantity' => '1']);
        $this->tick();
        $revision = $this->issueQuotation($owner, $revision);
        $this->tick();
        app(DecideQuotationAction::class)->accept($owner, $revision);
        $this->tick();
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $revision->fresh(), ['warehouse_id' => $warehouse->id])['order'];

        $this->assertSame(['quotation.revision_started', 'email.sent', 'quotation.issued', 'quotation.created'],
            $this->events($owner, 'quotation', (int) $issued->getKey()));
        $entries = $this->entries($owner, 'quotation', (int) $revision->getKey());
        $this->assertSame(['quotation.converted', 'quotation.accepted', 'quotation.issued', 'quotation.revision_started'], array_column($entries, 'event'));

        // Business language, safe parameters, a human actor, UTC instants and the business timezone.
        $converted = $entries[0];
        $this->assertSame(['type' => 'user', 'name' => $owner->name], $converted['actor']);
        $this->assertSame($order->order_number, $converted['params']['order_number']);
        $this->assertSame(['method' => 'post', 'href' => '/documents/open/sales_order/'.$order->getKey()], $converted['link']);
        $this->assertSame('2026-11-10T08:07:00+00:00', $converted['occurred_at']);
        $this->assertArrayNotHasKey('new_values', $converted);
        $response = $this->actingAs($owner)->getJson('/timeline/quotation/'.$revision->getKey())->assertOk();
        $this->assertSame('Africa/Casablanca', $response->json('timezone'));

        // The converted order's timeline shows where it came from.
        $this->assertContains('quotation.converted', $this->events($owner, 'sales_order', (int) $order->getKey()));
    }

    public function test_sales_order_and_invoice_timelines_combine_payments_refunds_invoice_follow_ups_and_attention(): void
    {
        $this->tick();
        [$owner, $organization, $store, $order, $customer] = $this->documentFixture();
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization);
        $this->tick();
        $first = $this->recordPayment($owner, $order, $account, '40.0000');
        $this->tick();
        app(RefundPaymentAction::class)->execute($owner, $first, $order->fresh(), 'Erreur de caisse');
        $this->tick();
        $this->recordPayment($owner, $order->fresh(), $account, (string) $order->fresh()->total_incl_tax);
        $this->tick();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order->fresh()));
        $this->tick();
        $followUp = app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Relancer le client', 'sales_order_id' => $order->getKey(), 'due_date' => '2026-11-12']);
        $this->tick();
        app(TransitionFollowUpAction::class)->execute($owner, $organization, $followUp, ['transition' => 'complete']);
        $this->tick();
        $item = $this->attention($organization, $order);
        $this->tick();
        app(HandleAttentionItemAction::class)->snooze($owner, $organization, $item, ['preset' => 'tomorrow']);
        $this->tick();
        app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Envoyer la facture signée', 'invoice_id' => $invoice->getKey(), 'due_date' => '2026-11-12']);

        $entries = $this->entries($owner, 'sales_order', (int) $order->getKey());
        $this->assertSame([
            'attention.snoozed', 'attention.detected', 'follow_up.completed', 'follow_up.created', 'invoice.issued',
            'payment.posted', 'payment.refunded', 'payment.posted', 'sales_order.confirmed', 'sales_order.created',
        ], array_column($entries, 'event'));
        $this->assertSame(['type' => 'system'], $entries[1]['actor'], 'Detection is the engine, never a person.');
        $this->assertSame('critical', $entries[1]['params']['severity']);
        $this->assertSame('40.0000', $entries[6]['params']['amount']);
        $this->assertSame('Relancer le client', $entries[3]['params']['title']);

        // Invoice timeline: its own lifecycle, its follow-ups — not the order's payments.
        $this->assertSame(['follow_up.created', 'invoice.issued'], $this->events($owner, 'invoice', (int) $invoice->getKey()));

        // Category filter.
        $this->assertSame(['payment.posted', 'payment.refunded', 'payment.posted'], $this->events($owner, 'sales_order', (int) $order->getKey(), ['category' => 'payments']));

        // Permission-specific financial details: no payments.view → no amounts; no invoices.view → no invoice events / timeline.
        $seller = $this->viewer($organization, [$store], ['sales_orders.view']);
        $sellerEntries = $this->entries($seller, 'sales_order', (int) $order->getKey());
        $this->assertNotContains('invoice.issued', array_column($sellerEntries, 'event'));
        $this->assertNotContains('follow_up.created', array_column($sellerEntries, 'event'), 'No follow-up permission: no follow-up events.');
        foreach (array_filter($sellerEntries, fn ($entry) => $entry['category'] === 'payments') as $payment) {
            $this->assertArrayNotHasKey('amount', $payment['params']);
        }
        $this->actingAs($seller)->getJson('/timeline/invoice/'.$invoice->getKey())->assertNotFound();
    }

    public function test_customer_timeline_only_shows_events_of_authorized_stores(): void
    {
        $this->tick();
        [$owner, $organization, $storeA, $variant] = $this->quotationCatalogFixture();
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $customer = app(CustomerManager::class)->create($owner, $organization, ['type' => 'company', 'display_name' => 'ACME', 'company_name' => 'ACME', 'status' => 'active']);
        $this->tick();
        $quotation = $this->createQuotation($owner, $organization, $storeA, ['customer_id' => $customer->getKey()]);
        $this->tick();
        $this->activate($owner, $organization, $storeB);
        $order = app(\App\Actions\Sales\ConfirmSalesOrderAction::class)->execute($owner, tap($this->createDraftOrder($owner, $organization, $storeB, $customer),
            fn (SalesOrder $draft) => $this->addCustomLine($owner, $draft, ['unit_price_excl_tax' => '100.0000'])));
        $this->tick();
        $this->recordPayment($owner, $order->fresh(), $this->createFinancialAccount($organization), '10.0000');
        $this->tick();
        app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Suivi magasin B', 'sales_order_id' => $order->getKey(), 'due_date' => '2026-11-12']);
        app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Suivi client', 'customer_id' => $customer->getKey(), 'due_date' => '2026-11-12']);

        $all = $this->events($owner, 'customer', (int) $customer->getKey());
        $this->assertContains('sales_order.confirmed', $all);
        $this->assertContains('payment.posted', $all);

        $storeAOnly = $this->viewer($organization, [$storeA], ['customers.view', 'quotations.view', 'sales_orders.view', 'payments.view', 'follow_ups.view', 'follow_ups.manage_all']);
        $entries = $this->entries($storeAOnly, 'customer', (int) $customer->getKey());
        $this->assertSame(['follow_up.created', 'quotation.created', 'customer.created'], array_column($entries, 'event'));
        $this->assertSame('Suivi client', $entries[0]['params']['title']);
        $this->assertStringNotContainsString((string) $order->order_number, json_encode($entries));
        $this->actingAs($storeAOnly)->getJson('/timeline/sales_order/'.$order->getKey())->assertNotFound();

        // No customers.view: the customer timeline itself is not available.
        $noCustomers = $this->viewer($organization, [$storeA, $storeB], ['quotations.view']);
        $this->actingAs($noCustomers)->getJson('/timeline/customer/'.$customer->getKey())->assertNotFound();
    }

    public function test_cross_organization_and_store_isolation_and_safe_email_summary(): void
    {
        $this->tick();
        [$owner, $organization, $store] = $this->quotationCatalogFixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->tick();
        $this->email($owner, $quotation);

        $stranger = User::factory()->create();
        $other = $this->createOrganization($stranger, 'Other');
        $this->activate($stranger, $other, $this->createStore($other, $stranger, 'Other store'));
        $this->actingAs($stranger)->getJson('/timeline/quotation/'.$quotation->getKey())->assertNotFound();

        $otherStore = $this->createStore($organization, $owner, 'Store B');
        $outsider = $this->viewer($organization, [$otherStore], ['quotations.view']);
        $this->actingAs($outsider)->getJson('/timeline/quotation/'.$quotation->getKey())->assertNotFound();
        $this->actingAs($outsider)->post('/documents/open/quotation/'.$quotation->getKey())->assertSessionHasErrors('quotation');

        // Recipient addresses only for people allowed to email quotations.
        $reader = $this->viewer($organization, [$store], ['quotations.view']);
        $email = $this->entries($reader, 'quotation', (int) $quotation->getKey())[0];
        $this->assertSame('email.sent', $email['event']);
        $this->assertSame(['document' => 'quotation', 'recipients' => 1], $email['params'], 'A draft has no number yet; only a recipient count.');
        $this->assertStringNotContainsString('client@example.test', json_encode($email));
        $this->assertSame('client@example.test', $this->entries($owner, 'quotation', (int) $quotation->getKey())[0]['params']['recipient']);

        $this->actingAs($owner)->getJson('/timeline/quotation/'.$quotation->getKey().'?cursor=not-a-cursor')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/timeline/unknown/'.$quotation->getKey())->assertNotFound();
    }

    public function test_cursor_pagination_is_stable_without_duplicates_or_gaps_across_sources_and_ties(): void
    {
        $this->tick();
        [$owner, $organization, $store] = $this->quotationCatalogFixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        // Many events sharing the same second across several sources (audit, email, recurring system creation).
        for ($i = 0; $i < 9; $i++) {
            app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => "Relance {$i}", 'quotation_id' => $quotation->getKey(), 'due_date' => '2026-11-12']);
            $this->email($owner, $quotation, $i % 2 ? DocumentEmailDelivery::STATUS_FAILED : DocumentEmailDelivery::STATUS_SENT);
        }
        $this->tick();
        $this->email($owner, $quotation);

        $full = array_column($this->entries($owner, 'quotation', (int) $quotation->getKey()), 'id');
        $this->assertCount(20, $full);

        $paged = [];
        $cursor = null;
        $pages = 0;
        do {
            $response = $this->actingAs($owner)->getJson('/timeline/quotation/'.$quotation->getKey().'?'.http_build_query(array_filter(['per_page' => 7, 'cursor' => $cursor])))->assertOk();
            $paged = [...$paged, ...array_column($response->json('entries'), 'id')];
            $cursor = $response->json('next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertSame($full, $paged, 'Pages concatenate to exactly the full ordered list.');
        $this->assertSame($paged, array_values(array_unique($paged)));
        $this->assertSame('email.sent', $this->events($owner, 'quotation', (int) $quotation->getKey())[0], 'Newest first.');
    }

    public function test_system_actor_for_recurring_creation_and_bounded_query_count(): void
    {
        $this->tick();
        [$owner, $organization, $store] = $this->quotationCatalogFixture();
        $customer = app(CustomerManager::class)->create($owner, $organization, ['type' => 'individual', 'display_name' => 'Client', 'status' => 'active']);
        $followUp = app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Manuel', 'customer_id' => $customer->getKey(), 'due_date' => '2026-11-12']);
        // A recurring occurrence is audited without an actor: shown as automatic, never as a person.
        DB::table('audit_logs')->where('auditable_type', FollowUp::class)->where('auditable_id', (string) $followUp->getKey())->update(['actor_id' => null]);
        $this->assertSame(['type' => 'system'], $this->entries($owner, 'customer', (int) $customer->getKey())[0]['actor']);

        $count = function () use ($owner, $organization, $customer): int {
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(\App\Services\Timeline\BusinessTimeline::class)->page($owner->fresh(), $organization->fresh(), 'customer', (int) $customer->getKey());
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->getKey()]);
        $small = $count();
        for ($i = 0; $i < 12; $i++) {
            $quotation = $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->getKey()]);
            app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => "Relance {$i}", 'quotation_id' => $quotation->getKey(), 'due_date' => '2026-11-12']);
        }

        $this->assertSame($small, $count(), 'Timeline queries must not grow with the number of linked documents or events.');
        $this->assertLessThanOrEqual(25, $small);
    }
}
