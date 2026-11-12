<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Documents\SendInvoiceEmailAction;
use App\Actions\Documents\StampInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\CreditNote;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DeliveryNoteNumberGenerator;
use App\Services\InvoiceDocumentRenderer;
use App\Services\InvoiceNumberGenerator;
use App\Services\Sandbox\SandboxInventoryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

class SandboxDocumentsTest extends QuotationTestCase
{
    public function test_test_documents_use_their_own_numbering_and_never_touch_production_sequences(): void
    {
        [$owner, $org, $store, $production, $customer] = $this->documentFixture();
        $this->actingAs($owner);
        $year = now()->year;
        $this->assertSame("1/{$year}", $this->issueInvoice($owner, $this->createInvoice($owner, $production))->invoice_number);
        $this->assertSame('DN-000001', $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $production))->delivery_note_number);
        $productionState = $this->productionNumberingState($org->id);
        $settings = app(InvoiceNumberGenerator::class)->settings($org, $year);
        $stockState = [SandboxInventoryGuard::bypass(fn () => DB::table('inventory_movements')->count()), SandboxInventoryGuard::bypass(fn () => DB::table('inventory_reservations')->count()), SandboxInventoryGuard::bypass(fn () => DB::table('payments')->count())];

        $this->enterTest($owner);
        $testOrders = [];
        foreach ([1, 2, 3] as $n) {
            $order = $this->sandboxDraftOrder($owner, $org, $store, $customer);
            $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect();
            $invoice = Invoice::query()->where('sales_order_id', $order->id)->firstOrFail();
            $this->assertSame('draft', $invoice->status->value);
            $this->post("/invoices/{$invoice->id}/issue")->assertRedirect();
            $invoice->refresh();
            $this->assertSame('issued', $invoice->status->value);
            $this->assertSame('TEST-FAC-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), $invoice->invoice_number);
            $this->assertNotNull($invoice->sandbox_id);
            $family = DB::table('invoice_families')->where('id', $invoice->invoice_family_id)->first();
            $this->assertSame($invoice->sandbox_id, (int) $family->sandbox_id);
            $this->assertSame($invoice->invoice_number, $family->canonical_invoice_number);
            $testOrders[] = $order;
        }
        $this->post("/sales/orders/{$testOrders[0]->id}/delivery-notes")->assertRedirect();
        $note = DeliveryNote::query()->where('sales_order_id', $testOrders[0]->id)->firstOrFail();
        $this->post("/delivery-notes/{$note->id}/issue")->assertRedirect();
        $this->assertSame('TEST-BL-000001', $note->fresh()->delivery_note_number);
        $this->assertSame('draft', $testOrders[0]->fresh()->status->value);
        $this->assertSame('unfulfilled', $testOrders[0]->fresh()->fulfillment_status->value);

        // Official generators refuse to run in Test Mode, even inside a transaction.
        foreach ([fn () => app(InvoiceNumberGenerator::class)->next($org, $year), fn () => app(DeliveryNoteNumberGenerator::class)->next($org)] as $official) {
            try {
                DB::transaction($official);
                $this->fail('Official numbering allocated in Test Mode.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Test Mode', $e->getMessage());
            }
        }

        $this->assertEquals($productionState, $this->productionNumberingState($org->id));
        $this->assertSame($settings, app(InvoiceNumberGenerator::class)->settings($org, $year));
        $this->assertSame($stockState, [SandboxInventoryGuard::bypass(fn () => DB::table('inventory_movements')->count()), SandboxInventoryGuard::bypass(fn () => DB::table('inventory_reservations')->count()), SandboxInventoryGuard::bypass(fn () => DB::table('payments')->count())]);
        $this->assertSame(['credit_note' => null, 'delivery_note' => 2, 'invoice' => 4], [
            'credit_note' => DB::table('sandbox_document_sequences')->where('document_type', 'credit_note')->value('next_number'),
            'delivery_note' => (int) DB::table('sandbox_document_sequences')->where('document_type', 'delivery_note')->value('next_number'),
            'invoice' => (int) DB::table('sandbox_document_sequences')->where('document_type', 'invoice')->value('next_number'),
        ]);

        // Back in Production the next official numbers continue exactly where they were.
        $this->leaveTest();
        $next = $this->confirmedOrder($owner, $org, $store, $customer);
        $this->assertSame("2/{$year}", $this->issueInvoice($owner, $this->createInvoice($owner, $next))->invoice_number);
        $this->assertSame('DN-000002', $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $next))->delivery_note_number);
    }

    public function test_documents_are_mutually_hidden_links_are_rejected_and_tenant_store_scope_holds(): void
    {
        [$owner, $org, $store, $production, $customer] = $this->documentFixture();
        $this->actingAs($owner);
        $prodInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $production));
        $prodNote = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $production));
        $prodCredit = $this->insertCreditNote($org->id, $store->id, $production, $prodInvoice, null);

        $this->enterTest($owner);
        $sandboxId = (int) DB::table('sandboxes')->where('organization_id', $org->id)->value('id');
        $order = $this->sandboxDraftOrder($owner, $org, $store, $customer);
        $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect();
        $testInvoice = Invoice::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->post("/sales/orders/{$order->id}/delivery-notes")->assertRedirect();
        $testNote = DeliveryNote::query()->where('sales_order_id', $order->id)->firstOrFail();
        $testCredit = $this->insertCreditNote($org->id, $store->id, $order, $testInvoice, $sandboxId);

        // Test session: Production documents do not exist.
        $this->get("/invoices/{$prodInvoice->id}")->assertNotFound();
        $this->get("/invoices/{$prodInvoice->id}/pdf")->assertNotFound();
        $this->get("/delivery-notes/{$prodNote->id}")->assertNotFound();
        $this->get("/credit-notes/{$prodCredit}")->assertNotFound();
        $this->get("/invoices/{$testInvoice->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('isTest', true)->where('sharing', null));
        $this->get('/invoices')->assertOk()->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1)->where('invoices.data.0.id', $testInvoice->id));
        $this->get('/delivery-notes')->assertOk()->assertInertia(fn (Assert $p) => $p->has('deliveryNotes.data', 1)->where('deliveryNotes.data.0.id', $testNote->id));
        $this->assertNull(Invoice::find($prodInvoice->id));
        $this->assertNull(DeliveryNote::find($prodNote->id));
        $this->assertNull(CreditNote::find($prodCredit));
        $this->assertNotNull(CreditNote::find($testCredit));
        $this->assertSame(0, InvoiceLine::query()->where('invoice_id', $prodInvoice->id)->count());

        // Cross-environment links are refused at the model and action boundary.
        $this->assertHttpStatus(404, fn () => app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, SalesOrder::query()->withoutGlobalScopes()->findOrFail($production->id)));
        $this->post("/sales/orders/{$production->id}/invoices")->assertNotFound();
        $this->post("/sales/orders/{$production->id}/delivery-notes")->assertNotFound();
        $this->assertHttpStatus(404, function () use ($testInvoice, $production) {
            $forged = $testInvoice->replicate(['invoice_number']);
            $forged->sales_order_id = $production->id;
            $forged->version = 9;
            $forged->save();
        });
        $this->assertHttpStatus(404, function () use ($testInvoice, $prodInvoice) {
            $forged = $testInvoice->replicate(['invoice_number']);
            $forged->invoice_family_id = $prodInvoice->invoice_family_id;
            $forged->version = 9;
            $forged->save();
        });
        $this->assertHttpStatus(404, function () use ($testNote, $production) {
            $forged = $testNote->replicate();
            $forged->sales_order_id = $production->id;
            $forged->save();
        });

        // Store isolation still applies inside Test Mode.
        $otherStore = $this->createStore($org, $owner, 'Other store');
        $this->activate($owner, $org, $otherStore);
        $this->get("/invoices/{$testInvoice->id}")->assertNotFound();
        $this->get("/delivery-notes/{$testNote->id}")->assertNotFound();
        $this->activate($owner, $org, $store);

        // Production session: Test documents do not exist, and cannot be acted on.
        $this->leaveTest();
        $this->get("/invoices/{$testInvoice->id}")->assertNotFound();
        $this->get("/invoices/{$testInvoice->id}/pdf")->assertNotFound();
        $this->get("/delivery-notes/{$testNote->id}")->assertNotFound();
        $this->get("/credit-notes/{$testCredit}")->assertNotFound();
        $this->get('/invoices')->assertOk()->assertInertia(fn (Assert $p) => $p->has('invoices.data', 1)->where('invoices.data.0.id', $prodInvoice->id));
        $this->get('/delivery-notes')->assertOk()->assertInertia(fn (Assert $p) => $p->has('deliveryNotes.data', 1)->where('deliveryNotes.data.0.id', $prodNote->id));
        $this->assertNull(Invoice::find($testInvoice->id));
        $this->assertNull(CreditNote::find($testCredit));
        $this->assertHttpStatus(404, fn () => app(IssueInvoiceAction::class)->execute($owner, Invoice::query()->withoutGlobalScopes()->findOrFail($testInvoice->id)));
        $this->assertSame('draft', DB::table('invoices')->where('id', $testInvoice->id)->value('status'));

        // Another tenant never sees either environment's documents.
        $stranger = User::factory()->create();
        $otherOrg = $this->createOrganization($stranger);
        $this->createStore($otherOrg, $stranger);
        $this->actingAs($stranger);
        $this->get("/invoices/{$prodInvoice->id}")->assertNotFound();
        $this->get("/invoices/{$testInvoice->id}")->assertNotFound();
    }

    public function test_test_pdf_is_visibly_marked_and_production_render_is_unchanged(): void
    {
        [$owner, $org, $store, $production, $customer] = $this->documentFixture();
        $this->actingAs($owner);
        $prodInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $production));
        $prodNote = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $production));
        $renderer = app(InvoiceDocumentRenderer::class);
        $productionHtmlBefore = $renderer->html($prodInvoice->fresh());
        $productionPayload = $renderer->payload($prodInvoice->fresh());

        $this->enterTest($owner);
        $order = $this->sandboxDraftOrder($owner, $org, $store, $customer);
        $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect();
        $invoice = Invoice::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->get("/invoices/{$invoice->id}/print")->assertOk()->assertSee('DOCUMENT DE TEST')->assertSee('NON COMPTABLE')->assertSee('non attribuée (brouillon)');
        $this->post("/invoices/{$invoice->id}/issue")->assertRedirect();
        $this->get("/invoices/{$invoice->id}/print")->assertOk()
            ->assertSee('DOCUMENT DE TEST')->assertSee('NON COMPTABLE')->assertSee('SANS VALEUR LÉGALE NI FISCALE')
            ->assertSee('TEST-FAC-000001')->assertSee('(TEST)');
        $pdf = $this->get("/invoices/{$invoice->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('TEST-FAC-000001', (string) $pdf->headers->get('Content-Disposition'));
        $this->post("/sales/orders/{$order->id}/delivery-notes")->assertRedirect();
        $note = DeliveryNote::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->post("/delivery-notes/{$note->id}/issue")->assertRedirect();
        $this->get("/delivery-notes/{$note->id}/print")->assertOk()->assertSee('DOCUMENT DE TEST')->assertSee('TEST-BL-000001');
        $this->get("/delivery-notes/{$note->id}/pdf")->assertOk();

        $this->leaveTest();
        $this->assertArrayNotHasKey('sandbox', $productionPayload);
        $this->assertSame($productionHtmlBefore, $renderer->html($prodInvoice->fresh()));
        foreach ([$this->get("/invoices/{$prodInvoice->id}/print"), $this->get("/delivery-notes/{$prodNote->id}/print")] as $response) {
            $response->assertOk()->assertDontSee('DOCUMENT DE TEST')->assertDontSee('NON COMPTABLE')->assertDontSee('(TEST)');
        }
    }

    public function test_unsupported_document_finance_and_stock_transitions_stay_blocked(): void
    {
        [$owner, $org, $store, , $customer] = $this->documentFixture();
        $this->actingAs($owner);
        $this->enterTest($owner);
        $order = $this->sandboxDraftOrder($owner, $org, $store, $customer);
        $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect();
        $invoice = Invoice::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->post("/invoices/{$invoice->id}/issue")->assertRedirect();
        $this->post("/sales/orders/{$order->id}/delivery-notes")->assertRedirect();
        $note = DeliveryNote::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->post("/delivery-notes/{$note->id}/issue")->assertRedirect();
        $invoice->refresh();
        $state = [SandboxInventoryGuard::bypass(fn () => DB::table('inventory_movements')->count()), SandboxInventoryGuard::bypass(fn () => DB::table('payments')->count()), DB::table('document_email_deliveries')->count()];

        foreach (["/invoices/{$invoice->id}/email", "/invoices/{$invoice->id}/stamp", "/invoices/{$invoice->id}/corrections",
            "/delivery-notes/{$note->id}/email", "/sales/orders/{$order->id}/out-of-stock-articles",
            "/sales/orders/{$order->id}/fulfillments/{$order->id}/share", "/sales/orders/{$order->id}/fulfillments/{$order->id}/attachments"] as $blocked) {
            $this->postJson($blocked, [])->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        }
        $this->getJson("/invoices/{$invoice->id}/shared-pdf")->assertForbidden();
        $this->getJson('/finance/export/invoices')->assertForbidden();
        $this->assertFalse($owner->can('email', $invoice));
        $this->assertFalse($owner->can('stamp', $invoice));
        $this->assertFalse($owner->can('email', $note));
        $this->assertHttpStatus(403, fn () => app(SendInvoiceEmailAction::class)->execute($owner, $invoice, ['to' => ['client@example.test']]));
        $this->assertHttpStatus(403, fn () => app(StampInvoiceAction::class)->execute($owner, $invoice));
        // Phase 8: Test Credit Notes exist only for Test returns; a status outside the lifecycle is still refused.
        $this->assertHttpStatus(403, function () use ($org, $store, $order, $invoice) {
            $note = new CreditNote;
            foreach (['organization_id' => $org->id, 'store_id' => $store->id, 'customer_return_id' => 1, 'invoice_id' => $invoice->id,
                'sales_order_id' => $order->id, 'status' => 'bogus', 'credit_note_date' => now()->toDateString(), 'currency_code' => 'MAD',
                'subtotal_excl_tax' => '0', 'discount_total' => '0', 'tax_total' => '0', 'total_incl_tax' => '0', 'reason' => 'x',
                'seller_snapshot' => [], 'customer_snapshot' => []] as $key => $value) {
                $note->{$key} = $value;
            }
            $note->save();
        });
        $this->assertSame($state, [SandboxInventoryGuard::bypass(fn () => DB::table('inventory_movements')->count()), SandboxInventoryGuard::bypass(fn () => DB::table('payments')->count()), DB::table('document_email_deliveries')->count()]);
        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertSame(0, DB::table('credit_notes')->count());
    }

    public function test_rollback_refuses_to_drop_document_isolation_while_test_documents_exist(): void
    {
        [$owner, $org, $store, , $customer] = $this->documentFixture();
        $this->actingAs($owner);
        $this->enterTest($owner);
        $order = $this->sandboxDraftOrder($owner, $org, $store, $customer);
        $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect();
        $migration = require database_path('migrations/2026_11_21_000003_isolate_sandbox_documents.php');
        try {
            $migration->down();
            $this->fail('Rollback dropped document isolation with Test documents present.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Test documents exist', $e->getMessage());
        }
        foreach (['invoices', 'invoice_families', 'delivery_notes', 'credit_notes'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'sandbox_id'), $table);
        }
        $this->assertTrue(Schema::hasTable('sandbox_document_sequences'));
        $this->assertSame(1, DB::table('invoices')->whereNotNull('sandbox_id')->count());
    }

    private function enterTest(User $owner): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk(); // resolves the Test context for direct action calls
    }

    private function leaveTest(): void
    {
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk(); // resolves the Production context again
    }

    private function sandboxDraftOrder(User $owner, $org, $store, $customer): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $org, $store, $customer);
        $this->addCustomLine($owner, $order, ['description' => 'Test service', 'unit_price_excl_tax' => '50.0000']);
        $order = $order->fresh();
        $this->assertNotNull($order->sandbox_id);
        $this->assertStringStartsWith('TEST-', $order->order_number);

        return $order;
    }

    private function confirmedOrder(User $owner, $org, $store, $customer): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $org, $store, $customer);
        $this->addCustomLine($owner, $order, ['description' => 'Next service']);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }

    /** @return array<string, mixed> */
    private function productionNumberingState(int $organizationId): array
    {
        return [
            'invoice_sequences' => DB::table('invoice_sequences')->where('organization_id', $organizationId)->orderBy('year')->get(['year', 'next_number'])->toArray(),
            'delivery_note_sequences' => DB::table('delivery_note_sequences')->where('organization_id', $organizationId)->get(['next_number'])->toArray(),
            'credit_note_sequences' => DB::table('credit_note_sequences')->where('organization_id', $organizationId)->get(['year', 'next_number'])->toArray(),
            'production_families' => DB::table('invoice_families')->where('organization_id', $organizationId)->whereNull('sandbox_id')->orderBy('id')->get(['id', 'canonical_invoice_number'])->toArray(),
        ];
    }

    /** Minimal raw credit note (+ its return) so isolation is testable without the stock-affecting return flow. */
    private function insertCreditNote(int $organizationId, int $storeId, SalesOrder $order, Invoice $invoice, ?int $sandboxId): int
    {
        $warehouse = $this->createWarehouse($order->organization, 'CN-'.Str::random(4), 'CN'.Str::random(4));
        $returnId = DB::table('customer_returns')->insertGetId([
            'organization_id' => $organizationId, 'store_id' => $storeId, 'sales_order_id' => $order->id, 'warehouse_id' => $warehouse->id,
            'return_number' => 'R-'.Str::random(8), 'client_operation_id' => (string) Str::uuid(), 'status' => 'received', 'reason' => 'x',
            'currency_code' => 'MAD', 'policy_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('credit_notes')->insertGetId([
            'organization_id' => $organizationId, 'store_id' => $storeId, 'customer_return_id' => $returnId, 'invoice_id' => $invoice->id,
            'sales_order_id' => $order->id, 'credit_note_number' => $sandboxId ? 'TEST-AV-'.Str::random(6) : 'AV-'.Str::random(6), 'status' => 'issued',
            'credit_note_date' => now()->toDateString(), 'currency_code' => 'MAD', 'subtotal_excl_tax' => 1, 'discount_total' => 0, 'tax_total' => 0,
            'total_incl_tax' => 1, 'reason' => 'x', 'seller_snapshot' => '{}', 'customer_snapshot' => '{}', 'sandbox_id' => $sandboxId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
