<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Http\Controllers\PosController;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\Finance\FinancePeriod;
use App\Services\Sandbox\SandboxInventoryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QuotationTestCase;

/**
 * Sprint 0 x Test Mode: Production finance guards never read or block the
 * Sandbox, and the Sandbox never touches Production fiscal records.
 */
class SandboxFiscalIntegrationTest extends QuotationTestCase
{
    public function test_a_closed_production_month_does_not_block_test_mode_and_test_mode_never_touches_production_books(): void
    {
        [$owner, $org, , $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $variant, '5.0000');
        $cash = $this->createFinancialAccount($org, ['name' => 'Caisse', 'code' => 'CASH', 'type' => 'cash']);
        $this->actingAs($owner)->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());
        $this->withFreshAuthentication(level: 1);

        // Close the CURRENT Production month (the date a Test checkout is booked on).
        $month = now()->format('Y-m');
        DB::table('finance_month_reviews')->insert(['organization_id' => $org->id, 'period' => $month.'-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $reviews = DB::table('finance_month_reviews')->get()->map(fn ($r) => (array) $r)->all();
        $production = SandboxInventoryGuard::bypass(fn () => [
            'payments' => DB::table('payments')->count(), 'sequences' => DB::table('payment_sequences')->get()->map(fn ($r) => (array) $r)->all(),
        ]);

        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();
        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->postJson("/pos/drafts/{$cart}/lines", ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '1', 'discount_type' => 'none', 'discount_value' => '0'])->assertOk();
        $total = (string) SalesOrder::query()->findOrFail($cart)->total_incl_tax;
        $this->post('/pos/sales', ['client_operation_id' => (string) Str::uuid(), 'order_id' => $cart,
            'payments' => [['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => $total, 'cash_received' => $total, 'reference' => null]]])
            ->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();

        // The Test payment posted into the Production-closed month, in the Test ledger only.
        $this->assertSame('sandbox_payments', Payment::query()->sole()->getTable());
        $this->post('/test-mode/leave')->assertRedirect();

        $after = SandboxInventoryGuard::bypass(fn () => [
            'payments' => DB::table('payments')->count(), 'sequences' => DB::table('payment_sequences')->get()->map(fn ($r) => (array) $r)->all(),
        ]);
        $this->assertEquals($production, $after, 'Production payments and numbering are untouched by Test Mode.');
        $this->assertEquals($reviews, DB::table('finance_month_reviews')->get()->map(fn ($r) => (array) $r)->all(), 'Production month reviews are untouched.');
        $this->assertSame('closed', DB::table('finance_month_reviews')->where('organization_id', $org->id)->value('status'));
    }
}
