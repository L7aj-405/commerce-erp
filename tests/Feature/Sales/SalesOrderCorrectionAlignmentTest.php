<?php

namespace Tests\Feature\Sales;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Documents\StampInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Enums\InventoryReservationStatus;
use App\Enums\InvoiceStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\SalesOrderRevision;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\InvoiceDocumentRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

class SalesOrderCorrectionAlignmentTest extends DocumentTestCase
{
    public function test_normal_confirmed_order_stays_read_only_and_rejects_crafted_line_mutations(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $line = $order->lines()->firstOrFail();

        $this->actingAs($owner)->get(route('sales.orders.edit', $order))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->component('Sales/Orders/Edit')
            ->where('isEditable', false)
            ->where('correction', null));

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'custom', 'description' => 'Injection', 'quantity' => '1',
            'price_input_mode' => 'ht', 'unit_price' => '1', 'discount_type' => 'none',
        ])->assertSessionHasErrors('order');
        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$order, $line->id]), [
            'line_type' => 'custom', 'description' => 'Injection', 'quantity' => '99',
            'price_input_mode' => 'ht', 'unit_price' => '1', 'discount_type' => 'none',
        ])->assertSessionHasErrors('order');
        $this->actingAs($owner)->delete(route('sales.orders.lines.destroy', [$order, $line->id]))
            ->assertSessionHasErrors('order');

        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->assertSame(1, $order->fresh()->lines()->count());
    }

    public function test_invoice_only_links_to_order_and_sales_order_owns_the_correction_entry_flow(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->component('Documents/Invoices/Show')
            ->where('can.viewOrder', true)
            ->missing('can.correctOrder')
            ->missing('can.continueOrderCorrection')
            ->missing('orderCorrectionInProgress'));

        $this->actingAs($owner)->get(route('sales.orders.show', $order))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->component('Sales/Orders/Show')
            ->where('can.correct', true)
            ->where('activeCorrection', null)
            ->where('pendingReplacementInvoice', false));

        $this->actingAs($owner)->post(route('sales.orders.corrections.store', $order), [
            'reason' => '',
        ])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('sales_order_revisions', 0);

        $this->actingAs($owner)->post(route('sales.orders.corrections.store', $order), [
            'reason' => 'Ajout d’un article demandé par le client',
        ])->assertRedirect(route('sales.orders.edit', $order));
        $this->assertDatabaseHas('sales_order_revisions', [
            'sales_order_id' => $order->id,
            'reason' => 'Ajout d’un article demandé par le client',
            'status' => 'in_progress',
        ]);

        $this->actingAs($owner)->get(route('sales.orders.edit', $order))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->component('Sales/Orders/Edit')
            ->where('order.status', 'draft')
            ->where('isEditable', true)
            ->where('correction.reason', 'Ajout d’un article demandé par le client')
            ->where('correction.revision_number', 1)
            ->where('can.update', true)
            ->where('can.confirm', true));

        $this->actingAs($owner)->get(route('sales.orders.show', $order))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('can.correct', false)
            ->where('activeCorrection.reason', 'Ajout d’un article demandé par le client')
            ->where('activeCorrection.revision_number', 1));

        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('can.viewOrder', true)
            ->missing('can.correctOrder')
            ->missing('orderCorrectionInProgress'));
    }

    public function test_active_correction_reuses_http_line_editor_for_add_remove_quantity_price_and_discount(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variantA = $this->createProduct($organization, 'Produit A', 'A-1', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $variantB = $this->createProduct($organization, 'Produit B', 'B-1', ['default_sale_price' => '50.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variantA, '10.0000');
        $this->openStock($owner, $organization, $warehouse, $variantB, '10.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variantA, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $invoiceBefore = $invoice->only(['invoice_number', 'subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax']);
        $invoiceLineBefore = $invoice->lines()->firstOrFail()->only(['product_variant_id', 'quantity', 'unit_price_excl_tax', 'discount_amount', 'total_incl_tax']);

        $this->actingAs($owner)->post(route('sales.orders.corrections.store', $order), ['reason' => 'Ajouter Produit B'])
            ->assertRedirect(route('sales.orders.edit', $order));
        $movementsBeforeEditing = InventoryMovement::query()->where('organization_id', $organization->id)->count();

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'catalog', 'product_variant_id' => $variantB->id,
            'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0.0000',
        ])->assertRedirect();
        $lineB = $order->fresh()->lines()->where('product_variant_id', $variantB->id)->firstOrFail();

        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$order, $lineB->id]), [
            'line_type' => 'catalog', 'product_variant_id' => $variantB->id,
            'quantity' => '2.0000', 'unit_price_excl_tax' => '60.0000',
            'discount_type' => 'percentage', 'discount_value' => '10.0000',
        ])->assertRedirect();
        $lineB->refresh();
        $this->assertSame('2.0000', $lineB->quantity);
        $this->assertSame('60.0000', $lineB->unit_price_excl_tax);
        $this->assertSame('percentage', $lineB->discount_type->value);
        $this->assertSame('10.0000', $lineB->discount_value);

        $this->actingAs($owner)->delete(route('sales.orders.lines.destroy', [$order, $lineB->id]))->assertRedirect();
        $this->assertDatabaseMissing('sales_order_lines', ['id' => $lineB->id]);

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'catalog', 'product_variant_id' => $variantB->id,
            'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0.0000',
        ])->assertRedirect();

        $this->assertSame($movementsBeforeEditing, InventoryMovement::query()->where('organization_id', $organization->id)->count());
        $this->assertSame($invoiceBefore, $invoice->fresh()->only(array_keys($invoiceBefore)));
        $this->assertSame($invoiceLineBefore, $invoice->fresh()->lines()->firstOrFail()->only(array_keys($invoiceLineBefore)));

        $this->actingAs($owner)->post(route('sales.orders.confirm', $order))->assertRedirect(route('sales.orders.show', $order));
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->assertSame('completed', $order->fresh()->currentRevision->status);
        $this->assertSame($movementsBeforeEditing, InventoryMovement::query()->where('organization_id', $organization->id)->count());
    }

    public function test_unfulfilled_order_correction_snapshots_history_and_reconciles_reservations_without_movements(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Caméra', 'CAM-REV', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $firstReservationId = $line->fresh()->allocations()->firstOrFail()->inventory_reservation_id;
        $movementsBefore = InventoryMovement::query()->where('organization_id', $organization->id)->count();

        $revision = app(StartSalesOrderCorrectionAction::class)->execute($owner, $order, 'Le client demande une unité supplémentaire.');

        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertSame('in_progress', $revision->status);
        $this->assertSame('2.0000', data_get($revision->before_snapshot, 'lines.0.quantity'));
        $this->assertSame(InventoryReservationStatus::Released, InventoryReservation::query()->findOrFail($firstReservationId)->status);
        $this->assertSame($movementsBefore, InventoryMovement::query()->where('organization_id', $organization->id)->count());

        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'catalog',
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '3.0000',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ], $line->fresh());
        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $newReservation = $confirmed->lines()->firstOrFail()->allocations()->firstOrFail()->inventoryReservation;

        $revision->refresh();
        $this->assertSame('completed', $revision->status);
        $this->assertSame('3.0000', data_get($revision->after_snapshot, 'lines.0.quantity'));
        $this->assertSame(InventoryReservationStatus::Active, $newReservation->status);
        $this->assertSame('3.0000', $newReservation->quantity);
        $this->assertSame($movementsBefore, InventoryMovement::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.correction_started', 'auditable_id' => $order->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales_order.correction_completed', 'auditable_id' => $order->id]);
    }

    public function test_corrected_commercial_state_versions_keep_one_number_without_consuming_the_annual_sequence(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '100.0000');
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        app(StampInvoiceAction::class)->execute($owner, $original);
        $originalNumber = $original->invoice_number;
        [$originalSequence, $invoiceYear] = array_map('intval', explode('/', $originalNumber));
        $this->assertSame(1, $original->version);
        $originalLine = $original->lines()->firstOrFail()->only(['description', 'quantity', 'unit_price_excl_tax', 'total_incl_tax']);

        $revision = app(StartSalesOrderCorrectionAction::class)->execute($owner, $order->fresh(), 'Quantité corrigée');
        $line = $order->fresh()->lines()->firstOrFail();
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'custom', 'description' => 'Consulting', 'reference' => null, 'unit_label' => 'hour',
            'quantity' => '2.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => null,
            'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $line);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();

        $this->assertSame(InvoiceStatus::Issued, $original->fresh()->status);
        $this->assertSame($originalLine, $original->fresh()->lines()->firstOrFail()->only(array_keys($originalLine)));
        $this->actingAs($owner)->get(route('sales.orders.show', $order))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('pendingReplacementInvoice', true)
            ->where('activeCorrection', null));

        $replacement = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order);
        $this->assertSame($revision->id, $replacement->sales_order_revision_id);
        $this->assertSame($original->id, $replacement->corrected_invoice_id);
        $this->assertSame(InvoiceStatus::Draft, $replacement->status);
        $this->assertSame($originalNumber, $replacement->invoice_number);
        $this->assertSame(2, $replacement->version);
        $this->assertSame('2.0000', $replacement->lines()->firstOrFail()->quantity);
        $this->assertSame($order->total_incl_tax, $replacement->total_incl_tax);
        $this->assertSame(InvoiceStatus::Issued, $original->fresh()->status);
        $this->assertNull($replacement->stampApposition);
        $this->actingAs($owner)->get(route('sales.orders.show', $order))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('pendingReplacementInvoice', false));
        $issuedReplacement = app(IssueInvoiceAction::class)->execute($owner, $replacement)->fresh();

        $this->assertSame($originalNumber, $issuedReplacement->invoice_number);
        $this->assertSame(2, $issuedReplacement->version);
        $this->assertSame($originalSequence + 1, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)->where('year', $invoiceYear)->value('next_number'));
        $this->assertSame(InvoiceStatus::Issued, $issuedReplacement->status);
        $this->assertSame(InvoiceStatus::Superseded, $original->fresh()->status);
        $this->assertSame($originalNumber, $original->fresh()->invoice_number);
        $this->assertSame($originalLine, $original->fresh()->lines()->firstOrFail()->only(array_keys($originalLine)));
        $this->assertNotNull($original->fresh()->stampApposition);
        $this->assertNull($issuedReplacement->stampApposition);
        $originalHtml = app(InvoiceDocumentRenderer::class)->html($original->fresh());
        $replacementHtml = app(InvoiceDocumentRenderer::class)->html($issuedReplacement);
        $this->assertStringContainsString('100,00', $originalHtml);
        $this->assertStringContainsString('Version 1', $originalHtml);
        $this->assertStringContainsString('Version 2', $replacementHtml);
        $originalPdf = app(DocumentPdfService::class)->invoice($original->fresh());
        $replacementPdf = app(DocumentPdfService::class)->invoice($issuedReplacement);
        $this->assertStringEndsWith('-V1.pdf', $originalPdf['filename']);
        $this->assertStringEndsWith('-V2.pdf', $replacementPdf['filename']);
        $this->assertNotSame($originalPdf['filename'], $replacementPdf['filename']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.correction_issued', 'auditable_id' => $issuedReplacement->id]);

        $unrelated = $this->createDraftOrder($owner, $organization, $order->store);
        $this->addCustomLine($owner, $unrelated, ['description' => 'Nouvelle vente', 'unit_price_excl_tax' => '50.0000']);
        $unrelated = app(ConfirmSalesOrderAction::class)->execute($owner, $unrelated)->fresh();
        $unrelatedInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $unrelated));
        $this->assertSame(($originalSequence + 1).'/'.$invoiceYear, $unrelatedInvoice->invoice_number);
        $this->assertSame(1, $unrelatedInvoice->version);

        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order->fresh(), 'Troisième version');
        $line = $order->fresh()->lines()->firstOrFail();
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'custom', 'description' => 'Consulting', 'reference' => null, 'unit_label' => 'hour',
            'quantity' => '3.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => null,
            'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $line);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $thirdVersion = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->assertSame($originalNumber, $thirdVersion->invoice_number);
        $this->assertSame(3, $thirdVersion->version);
        $this->assertSame(InvoiceStatus::Superseded, $issuedReplacement->fresh()->status);
        $this->assertSame($originalSequence + 2, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)->where('year', $invoiceYear)->value('next_number'));
    }

    public function test_posted_payments_and_allocations_are_preserved_and_remaining_is_recalculated_from_the_order(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '100.0000');
        $account = $this->createFinancialAccount($organization);
        $payment = $this->recordPayment($owner, $order, $account, '50.0000');
        $paymentBefore = [
            'amount' => $payment->amount,
            'payment_date' => $payment->payment_date->toDateString(),
            'status' => $payment->status->value,
            'method' => $payment->method->value,
        ];
        $allocationsBefore = $payment->allocations()->get()->map->only(['id', 'sales_order_id', 'amount'])->all();

        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order->fresh(), 'Prestation doublée');
        $line = $order->fresh()->lines()->firstOrFail();
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'custom', 'description' => 'Consulting', 'reference' => null, 'unit_label' => 'hour',
            'quantity' => '2.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => null,
            'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $line);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();

        $payment->refresh();
        $this->assertSame($paymentBefore, [
            'amount' => $payment->amount,
            'payment_date' => $payment->payment_date->toDateString(),
            'status' => $payment->status->value,
            'method' => $payment->method->value,
        ]);
        $this->assertSame($allocationsBefore, $payment->fresh()->allocations()->get()->map->only(['id', 'sales_order_id', 'amount'])->all());
        $this->assertSame('partially_paid', $order->payment_status->value);
        $this->assertSame('200.0000', $order->total_incl_tax);
    }

    public function test_correction_below_posted_payments_is_blocked_for_future_credit_note_workflow(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '100.0000');
        $payment = $this->recordPayment($owner, $order, $this->createFinancialAccount($organization), '100.0000');
        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order->fresh(), 'Réduction demandée');
        $line = $order->fresh()->lines()->firstOrFail();
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'custom', 'description' => 'Consulting', 'reference' => null, 'unit_label' => 'hour',
            'quantity' => '1.0000', 'unit_price_excl_tax' => '80.0000', 'tax_rate_id' => null,
            'discount_type' => 'none', 'discount_value' => '0.0000',
        ], $line);

        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('A correction below posted collections must require an avoir/refund workflow.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame('100.0000', $payment->fresh()->amount);
        $this->assertSame('posted', $payment->fresh()->status->value);
        $this->assertSame('in_progress', SalesOrderRevision::query()->where('sales_order_id', $order->id)->firstOrFail()->status);
    }

    public function test_fulfilled_orders_are_blocked_without_reversing_inventory(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order)->fresh();
        $movementsBefore = InventoryMovement::query()->count();

        try {
            app(StartSalesOrderCorrectionAction::class)->execute($owner, $fulfilled, 'Modification après livraison');
            $this->fail('Fulfilled orders require a dedicated return/credit workflow.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame('fulfilled', $fulfilled->fresh()->fulfillment_status->value);
        $this->actingAs($owner)->get(route('sales.orders.show', $fulfilled))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('can.correct', false));
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertDatabaseCount('sales_order_revisions', 0);
    }

    public function test_correction_route_enforces_rbac_and_tenant_hiding(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $clerk = User::factory()->create();
        $this->addOrganizationMember($organization, $clerk, ['invoices.view', 'sales_orders.view', 'sales_orders.update'], roleName: 'Clerk');
        $this->addStoreMember($store, $clerk);
        $this->activate($clerk, $organization, $store);
        $this->actingAs($clerk)
            ->post(route('sales.orders.corrections.store', $order), ['reason' => 'sans droit'])
            ->assertForbidden();
        $this->actingAs($clerk)->get(route('invoices.show', $invoice))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('can.viewOrder', true)
            ->missing('can.correctOrder')
            ->missing('can.continueOrderCorrection'));
        $this->actingAs($clerk)->get(route('sales.orders.show', $order))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('can.correct', false));

        $outsider = User::factory()->create();
        $otherOrganization = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrganization, $outsider);
        $this->activate($outsider, $otherOrganization, $otherStore);
        $this->actingAs($outsider)
            ->post(route('sales.orders.corrections.store', $order), ['reason' => 'attaque'])
            ->assertNotFound();

        $this->activate($owner, $organization, $store);
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->assertDatabaseCount('sales_order_revisions', 0);
    }

    public function test_an_existing_draft_invoice_must_be_cancelled_before_correction(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);

        try {
            app(StartSalesOrderCorrectionAction::class)->execute($owner, $order, 'Correction avec brouillon existant');
            $this->fail('A stale draft Invoice must block commercial correction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
        $this->assertDatabaseCount('sales_order_revisions', 0);
    }
}
