<?php

namespace Tests\Feature\Sandbox;

use App\Http\Controllers\PosController;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QuotationTestCase;

/**
 * Production and Test Mode records are never mixed by the POS draft cleanup: the scheduled
 * command only ever sees the Production environment, and refuses to run inside a Test context.
 */
class SandboxPosDraftCleanupTest extends QuotationTestCase
{
    public function test_cleanup_removes_production_drafts_but_never_touches_test_mode_drafts(): void
    {
        [$owner, $org, $store] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->actingAs($owner)->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, (string) Str::uuid());

        $production = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $test = $this->postJson('/pos/drafts', ['warehouse_id' => $warehouse->id])->assertOk()->json('active_sale.id');
        $this->assertNotNull(DB::table('sales_orders')->where('id', $test)->value('sandbox_id'));
        $this->assertNull(DB::table('sales_orders')->where('id', $production)->value('sandbox_id'));

        // Both carts are old and empty: only the Production one is eligible.
        DB::table('sales_orders')->whereIn('id', [$production, $test])->update(['pos_last_activity_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        // The CLI runs without any Test Mode selection (fresh context = Production).
        app()->forgetInstance(SandboxContext::class);
        $this->assertSame(0, Artisan::call('pos:cleanup-drafts', ['--execute' => true]));

        $this->assertNull(DB::table('sales_orders')->where('id', $production)->first(), 'The Production draft is cleaned.');
        $this->assertNotNull(DB::table('sales_orders')->where('id', $test)->first(), 'The Test Mode draft is never touched.');
    }
}
