<?php

namespace Tests\Feature\Pos;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\CancelInvoiceDraftAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\FinancialAccount;
use App\Models\InventoryMovement;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DocumentSnapshotVerifier;
use App\Services\Pos\PosOrderCompletionEligibility;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class PosOrderAddendumTest extends PosTestCase
{
    public function test_fresh_setup_fulfilled_pos_order_exposes_completion_and_return_actions(): void
    {
        [$owner, , , , , $order] = $this->completedSale();

        $this->actingAs($owner)->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/Orders/Show')
                ->where('completionEligibility.allowed', true)
                ->where('completionEligibility.code', 'allowed')
                ->where('can.completePos', true)
                ->where('can.createReturn', true));
    }

    public function test_issued_invoice_does_not_hide_pos_completion(): void
    {
        [$owner, , , , , $order] = $this->completedSale();
        app(IssueInvoiceAction::class)->execute(
            $owner,
            app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order),
        );

        $this->actingAs($owner)->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('completionEligibility.allowed', true)
                ->where('can.completePos', true));
    }

    public function test_zero_global_discount_is_not_treated_as_a_historical_discount(): void
    {
        [$owner, , , , , $order] = $this->completedSale();
        $order->pos_global_discount_type = 'percentage';
        $order->pos_global_discount_value = '0.0000';
        $order->save();

        $result = app(PosOrderCompletionEligibility::class)->evaluate($owner, $order->fresh());

        $this->assertTrue($result['allowed']);
        $this->assertSame('allowed', $result['code']);
    }

    public function test_fulfilled_pos_order_appends_and_fulfils_only_the_new_quantity(): void
    {
        [$owner, $organization, $store, $warehouse, $variantA, $order] = $this->completedSale();
        $variantB = $this->createProduct($organization, 'Product B', 'B-1', ['default_sale_price' => '500.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variantB, '4.0000');
        $originalLine = $order->lines()->firstOrFail()->only(['id', 'quantity', 'unit_price_excl_tax', 'total_incl_tax']);
        $originalFulfilledAt = $order->fulfilled_at;
        $originalPayment = $order->paymentAllocations()->firstOrFail()->payment->only(['id', 'amount', 'status']);
        $originalMovement = InventoryMovement::query()->firstOrFail()->only(['id', 'quantity', 'quantity_before', 'quantity_after', 'reference_id']);
        $originalMovements = InventoryMovement::query()->count();

        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $variantB->id, 'quantity' => '1.0000']],
            // Forged tenant/commercial fields are not part of the request contract.
            'organization_id' => 999999,
            'store_id' => 999999,
            'unit_price_excl_tax' => '0.0100',
        ])->assertRedirect(route('sales.orders.show', $order));

        $order->refresh();
        $this->assertSame($originalLine, $order->lines()->whereKey($originalLine['id'])->firstOrFail()->only(array_keys($originalLine)));
        $this->assertTrue($originalFulfilledAt->equalTo($order->fulfilled_at));
        $this->assertSame('1500.0000', $order->total_incl_tax);
        $this->assertSame('partially_paid', $order->payment_status->value);
        $this->assertSame($originalPayment, $order->paymentAllocations()->firstOrFail()->payment->only(array_keys($originalPayment)));
        $this->assertSame($originalMovement, InventoryMovement::query()->whereKey($originalMovement['id'])->firstOrFail()->only(array_keys($originalMovement)));
        $this->assertDatabaseHas('sales_order_addenda', ['sales_order_id' => $order->id, 'sequence' => 1, 'added_total' => 500]);
        $this->assertDatabaseHas('sales_order_lines', ['sales_order_id' => $order->id, 'product_variant_id' => $variantB->id, 'quantity' => 1]);
        $this->assertSame($originalMovements + 1, InventoryMovement::query()->count());
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variantB->id, 'on_hand' => 3, 'reserved' => 0]);
        $this->assertSame('500.0000', app(SalesOrderPaymentCalculator::class)->remainingAmount($order));
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'sales_order.items_added']);
        $this->assertSame($store->id, $order->store_id);
    }

    public function test_same_product_is_a_new_line_and_duplicate_submission_is_idempotent(): void
    {
        [$owner, $organization, , $warehouse, $variant, $order] = $this->completedSale('4.0000');
        $operationId = (string) Str::uuid();
        $payload = ['client_operation_id' => $operationId, 'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.0000']]];
        $movementCount = InventoryMovement::query()->count();

        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => $operationId,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => '2.0000']],
        ])->assertSessionHasErrors('client_operation_id');

        $this->assertSame(2, $order->lines()->where('product_variant_id', $variant->id)->count());
        $this->assertDatabaseCount('sales_order_addenda', 1);
        $this->assertSame($movementCount + 1, InventoryMovement::query()->count());
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 2]);
        $this->assertSame(1, $order->lines()->whereNotNull('sales_order_addendum_id')->count());
        $this->assertDatabaseHas('sales_order_addenda', ['organization_id' => $organization->id, 'client_operation_id' => $operationId]);
    }

    public function test_insufficient_local_stock_blocks_the_entire_addendum(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->completedSale();
        $variant = $this->createProduct($organization, 'Low stock', 'LOW-1', ['default_sale_price' => '50.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '1.0000');
        $beforeTotal = $order->total_incl_tax;

        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => '2.0000']],
        ])->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('sales_order_addenda', 0);
        $this->assertDatabaseMissing('sales_order_lines', ['sales_order_id' => $order->id, 'product_variant_id' => $variant->id]);
        $this->assertSame($beforeTotal, $order->fresh()->total_incl_tax);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 1, 'reserved' => 0]);
    }

    public function test_remote_or_procurement_stock_is_not_used_by_the_local_only_completion_flow(): void
    {
        [$owner, $organization, , $localWarehouse, , $order] = $this->completedSale();
        $remoteWarehouse = $this->createWarehouse($organization, 'Remote');
        $variant = $this->createProduct($organization, 'Remote only', 'REMOTE', ['default_sale_price' => '75.0000'])->variants->first();
        $this->openStock($owner, $organization, $remoteWarehouse, $variant, '10.0000');

        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.0000']],
        ])->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('sales_order_addenda', 0);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $remoteWarehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 10]);
        $this->assertDatabaseMissing('inventory_movements', ['warehouse_id' => $localWarehouse->id, 'product_variant_id' => $variant->id]);
    }

    public function test_tenant_store_rbac_cancelled_and_unsafe_document_guards_are_enforced(): void
    {
        [$owner, $organization, $store, $warehouse, , $order] = $this->completedSale();
        $outsider = User::factory()->create();
        $otherOrganization = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrganization, $outsider);
        $otherWarehouse = $this->createWarehouse($otherOrganization);
        $foreignVariant = $this->createProduct($otherOrganization, 'Foreign', 'FOREIGN', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $foreignVariant->id, 'quantity' => '1.0000']],
        ])->assertNotFound();

        $secondStore = $this->createStore($organization, $owner, 'Other Store');
        $this->activate($owner, $organization, $secondStore);
        $this->actingAs($owner)->get("/sales/orders/{$order->id}/completion")->assertNotFound();

        $this->activate($outsider, $otherOrganization, $otherStore);
        $this->actingAs($outsider)->get("/sales/orders/{$order->id}/completion")->assertNotFound();

        $employee = User::factory()->create();
        $this->addOrganizationMember($organization, $employee, ['sales_orders.view'], roleName: 'Viewer');
        $this->addStoreMember($store, $employee);
        $this->activate($employee, $organization, $store);
        $this->actingAs($employee)->get(route('sales.orders.completion.create', $order))->assertForbidden();

        $this->activate($owner, $organization, $store);
        $order->status = SalesOrderStatus::Cancelled;
        $order->save();
        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $foreignVariant->id, 'quantity' => '1.0000']],
        ])->assertSessionHasErrors('order');

        $this->assertDatabaseCount('sales_order_addenda', 0);
        $this->assertSame($otherOrganization->id, $otherWarehouse->organization_id);
        $this->assertSame($warehouse->id, $order->pos_warehouse_id);
    }

    public function test_existing_payment_is_untouched_and_a_new_payment_settles_the_added_balance(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->completedSale();
        $variant = $this->createProduct($organization, 'Product B', 'PAY-B', ['default_sale_price' => '500.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '2.0000');
        $originalPaymentId = $order->paymentAllocations()->firstOrFail()->payment_id;

        $this->add($owner, $order, $variant->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('500.0000', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));

        $account = FinancialAccount::query()->where('organization_id', $organization->id)->where('code', 'POS-CASH')->firstOrFail();
        app(RecordSalesOrderPaymentsAction::class)->execute($owner, $order->fresh(), [[
            'method' => 'cash', 'financial_account_id' => $account->id, 'amount' => '500.0000',
            'payment_date' => now()->toDateString(), 'reference' => 'COMPLEMENT',
        ]], (string) Str::uuid());

        $this->assertDatabaseHas('payments', ['id' => $originalPaymentId, 'amount' => 1000, 'status' => 'posted']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->assertSame('0.0000', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));
    }

    public function test_no_issued_invoice_produces_one_v1_with_all_current_lines(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->completedSale();
        $variant = $this->createProduct($organization, 'Product B', 'INV-B', ['default_sale_price' => '500.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '2.0000');
        $this->add($owner, $order, $variant->id);

        $invoice = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->assertSame(1, $invoice->version);
        $this->assertNull($invoice->corrected_invoice_id);
        $this->assertSame(2, $invoice->lines()->count());
        $this->assertNotNull($invoice->sales_order_addendum_id);
    }

    public function test_issued_v1_stays_immutable_and_v2_v3_reuse_family_and_sequence(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->completedSale();
        $invoiceV1 = app(IssueInvoiceAction::class)->execute(
            $owner,
            app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order),
        )->fresh();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $this->actingAs($owner)->post(route('invoices.stamp', $invoiceV1))->assertRedirect();
        $this->assertNotNull($invoiceV1->fresh()->stampApposition);
        $v1Snapshot = $invoiceV1->only(['invoice_number', 'invoice_family_id', 'version', 'total_incl_tax', 'status']);
        $sequenceAfterV1 = (int) DB::table('invoice_sequences')->where('organization_id', $organization->id)
            ->where('year', (int) $invoiceV1->invoice_date->format('Y'))->value('next_number');

        $variantB = $this->createProduct($organization, 'Product B', 'VER-B', ['default_sale_price' => '500.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variantB, '2.0000');
        $this->add($owner, $order, $variantB->id);
        app(DocumentSnapshotVerifier::class)->verifyInvoice($invoiceV1->fresh());

        $invoiceV2 = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame(2, $invoiceV2->version);
        $this->assertSame($invoiceV1->invoice_family_id, $invoiceV2->invoice_family_id);
        $this->assertSame($invoiceV1->invoice_number, $invoiceV2->invoice_number);
        $this->assertSame(InvoiceStatus::Issued, $invoiceV1->fresh()->status);
        $this->assertSame(InvoiceStatus::Draft, $invoiceV2->status);
        $this->assertNull($invoiceV2->stampApposition);
        $this->assertSame($sequenceAfterV1, (int) DB::table('invoice_sequences')->where('organization_id', $organization->id)
            ->where('year', (int) $invoiceV1->invoice_date->format('Y'))->value('next_number'));

        $invoiceV2 = app(IssueInvoiceAction::class)->execute($owner, $invoiceV2)->fresh();
        $this->assertSame(InvoiceStatus::Superseded, $invoiceV1->fresh()->status);
        $this->assertSame(InvoiceStatus::Issued, $invoiceV2->status);
        $this->assertSame($v1Snapshot['total_incl_tax'], $invoiceV1->fresh()->total_incl_tax);

        $variantC = $this->createProduct($organization, 'Product C', 'VER-C', ['default_sale_price' => '250.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variantC, '2.0000');
        $this->add($owner, $order, $variantC->id);
        app(DocumentSnapshotVerifier::class)->verifyInvoice($invoiceV1->fresh());
        app(DocumentSnapshotVerifier::class)->verifyInvoice($invoiceV2->fresh());
        $invoiceV3 = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame(3, $invoiceV3->version);
        $this->assertSame($invoiceV1->invoice_family_id, $invoiceV3->invoice_family_id);
        $this->assertSame($invoiceV1->invoice_number, $invoiceV3->invoice_number);
        $this->assertSame(3, $invoiceV3->lines()->count());
    }

    public function test_draft_invoice_and_global_discount_block_completion_without_mutation(): void
    {
        [$owner, $organization, , $warehouse, , $order] = $this->completedSale();
        $variant = $this->createProduct($organization, 'Blocked', 'BLOCK', ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '2.0000');
        $draft = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order);

        $draftResult = app(PosOrderCompletionEligibility::class)->evaluate($owner, $order->fresh());
        $this->assertFalse($draftResult['allowed']);
        $this->assertSame('draft_invoice_exists', $draftResult['code']);

        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(), 'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.0000']],
        ])->assertSessionHasErrors('order');
        $this->assertDatabaseCount('sales_order_addenda', 0);

        app(CancelInvoiceDraftAction::class)->execute($owner, $draft, 'Test de la remise globale');
        $order->pos_global_discount_type = 'fixed';
        $order->pos_global_discount_value = '10.0000';
        $order->save();
        $discountResult = app(PosOrderCompletionEligibility::class)->evaluate($owner, $order->fresh());
        $this->assertFalse($discountResult['allowed']);
        $this->assertSame('historical_global_discount', $discountResult['code']);
        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(), 'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.0000']],
        ])->assertSessionHasErrors('order');
        $this->assertDatabaseCount('sales_order_addenda', 0);
    }

    /** @return array{User, mixed, mixed, mixed, mixed, SalesOrder} */
    private function completedSale(string $stock = '10.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Product A', 'A-1', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($variant)]))->assertRedirect();

        return [$owner, $organization, $store, $warehouse, $variant, SalesOrder::query()->firstOrFail()->fresh(['lines', 'paymentAllocations.payment'])];
    }

    private function add(User $owner, SalesOrder $order, int $variantId): void
    {
        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $variantId, 'quantity' => '1.0000']],
        ])->assertRedirect();
    }
}
