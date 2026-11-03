<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationDataReset\OrganizationDataResetException;
use App\Services\OrganizationDataReset\OrganizationDataResetExecutor;
use App\Services\OrganizationDataReset\OrganizationDataResetFailedException;
use App\Services\OrganizationDataReset\OrganizationDataResetPreviewService;
use App\Services\OrganizationDataReset\OrganizationDataResetScope;
use App\Services\OrganizationDataReset\OrganizationDataResetStalePlanException;
use App\Services\OrganizationDataReset\Selective\SelectiveResetCategoryRegistry;
use App\Services\OrganizationDataReset\Selective\SelectiveResetExecutor;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlan;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlanner;
use App\Services\OrganizationDataReset\Selective\SelectiveResetSelection;
use Database\Seeders\Development\LocalVerificationSeeder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\PlatformTestCase;

/**
 * Selective data reset on a REALISTIC relational graph (the local
 * verification seeder: POS / manual / quotation-converted orders, supplier
 * availability request, procurement, purchase order, partial receipt,
 * transfer requests, reservations).
 */
class SelectiveDataResetTest extends PlatformTestCase
{
    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocalVerificationSeeder::class);
        $this->owner = User::query()->where('email', 'owner@erp.local')->firstOrFail();
        $this->org = Organization::query()->where('name', LocalVerificationSeeder::ORGANIZATION_NAME)->firstOrFail();
        $this->activate($this->owner, $this->org);
    }

    private function selection(array $data): SelectiveResetSelection
    {
        return SelectiveResetSelection::fromArray($data, app(SelectiveResetCategoryRegistry::class));
    }

    private function plan(array $data): SelectiveResetPlan
    {
        return app(SelectiveResetPlanner::class)->plan($this->org->fresh(), $this->selection($data));
    }

    /** @return array<string,mixed> */
    private function runReset(array $data): array
    {
        $plan = $this->plan($data);
        $this->assertTrue($plan->isExecutable(), 'Plan blocked: '.json_encode($plan->blockers));

        return app(SelectiveResetExecutor::class)->execute($this->org->fresh(), $this->owner, $this->selection($data), $plan->fingerprint);
    }

    private function rows(string $table): int
    {
        return DB::table($table)->where('organization_id', $this->org->id)->count();
    }

    private function masters(): array
    {
        return array_map(fn ($t) => $this->rows($t), [
            'customers' => 'customers', 'suppliers' => 'suppliers', 'organization_contacts' => 'organization_contacts',
            'products' => 'products', 'product_variants' => 'product_variants', 'warehouses' => 'warehouses', 'stores' => 'stores',
            'organization_memberships' => 'organization_memberships', 'inventory_movements' => 'inventory_movements',
        ]);
    }

    private function onHand(): array
    {
        return DB::table('inventory_balances')->where('organization_id', $this->org->id)->orderBy('id')->pluck('on_hand', 'id')->map(fn ($v) => (string) $v)->all();
    }

    private function assertReservationsConsistent(): void
    {
        foreach (DB::table('inventory_balances')->where('organization_id', $this->org->id)->get() as $balance) {
            $active = (float) DB::table('inventory_reservations')->where('organization_id', $this->org->id)
                ->where('warehouse_id', $balance->warehouse_id)->where('product_variant_id', $balance->product_variant_id)
                ->where('status', 'active')->sum('quantity');
            $this->assertEqualsWithDelta($active, (float) $balance->reserved, 0.00001, "balance #{$balance->id}");
        }
    }

    // === 1. Root cause of the production failure ===========================

    public function test_sales_and_documents_scope_completes_when_procurement_comes_from_a_supplier_request(): void
    {
        // The procurement references the quotation's supplier availability
        // request (proc_src_qsar_fk) and its quotation line: the former fixed
        // order deleted those parents first and the database refused.
        $this->assertSame(1, DB::table('sales_order_procurements')->where('organization_id', $this->org->id)->whereNotNull('source_supplier_request_id')->count());
        $plan = app(OrganizationDataResetPreviewService::class)->preview($this->org, OrganizationDataResetScope::SalesAndDocuments);
        $this->assertSame([], $plan->blockers);

        app(OrganizationDataResetExecutor::class)->execute($this->org, $this->owner, $plan);

        foreach (['sales_orders', 'quotations', 'quotation_supplier_availability_requests', 'sales_order_procurements', 'purchase_orders'] as $table) {
            $this->assertSame(0, $this->rows($table), $table);
        }
        $this->assertSame(2, $this->rows('suppliers'), 'Suppliers are master data.');
    }

    public function test_preview_reports_a_retained_reference_instead_of_failing_at_execution(): void
    {
        $procurement = DB::table('sales_order_procurements')->where('organization_id', $this->org->id)->value('id');
        DB::table('finance_obligations')->insert([
            'organization_id' => $this->org->id, 'obligation_number' => 'OBL-1', 'category' => 'supplier', 'label' => 'Fournisseur',
            'due_date' => now()->toDateString(), 'planned_amount' => '100.0000', 'currency_code' => 'MAD',
            'sales_order_procurement_id' => $procurement, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = app(OrganizationDataResetPreviewService::class)->preview($this->org, OrganizationDataResetScope::SalesAndDocuments);

        $this->assertFalse($plan->isExecutable());
        $this->assertSame('retained_reference', $plan->blockers[0]['code']);
        $this->assertSame('finance_obligations', $plan->blockers[0]['table']);
    }

    // === 2. Purchasing cleanup keeps supplier master data =====================

    public function test_purchase_operations_are_deleted_while_suppliers_contacts_and_addresses_are_kept(): void
    {
        $supplier = DB::table('suppliers')->where('organization_id', $this->org->id)->first();
        DB::table('organization_contacts')->insert([
            'organization_id' => $this->org->id, 'supplier_id' => $supplier->id, 'full_name' => 'Commercial MIFA',
            'contact_type' => 'supplier', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierBefore = (array) $supplier;
        $masters = $this->masters();
        $onHand = $this->onHand();

        $result = $this->runReset(['categories' => ['purchase_orders', 'supplier_procurements']]);

        $this->assertSame(0, $this->rows('purchase_orders'));
        $this->assertSame(0, $this->rows('sales_order_procurements'));
        $this->assertSame(0, $this->rows('procurement_receipts'));
        $this->assertSame($supplierBefore, (array) DB::table('suppliers')->find($supplier->id), 'Supplier record (incl. address) untouched.');
        $this->assertSame(1, DB::table('organization_contacts')->where('supplier_id', $supplier->id)->count());
        $this->assertSame($masters, $this->masters());
        $this->assertSame($onHand, $this->onHand(), 'Received goods stay in stock.');
        $this->assertReservationsConsistent();
        // The order that came from the quotation stays; only its procurement is gone.
        $this->assertGreaterThan(0, $this->rows('sales_orders'));
        $this->assertSame(1, DB::table('audit_logs')->where('organization_id', $this->org->id)->where('event', 'organization.data_reset.selective_completed')->count());
        $this->assertArrayHasKey('purchase_orders', $result['deleted']);
    }

    // === 3. Sales-only cleanup: conflicts are refused, never widened =========

    public function test_sales_orders_alone_are_refused_with_references_to_the_preserved_categories(): void
    {
        $before = $this->rows('sales_orders');
        $plan = $this->plan(['categories' => ['sales_orders']]);

        $this->assertFalse($plan->isExecutable());
        $codes = collect($plan->blockers)->pluck('category')->unique()->sort()->values()->all();
        $this->assertSame(['purchase_orders', 'quotations', 'supplier_procurements', 'transfers'], $codes);
        $this->assertContains('preserved_category', array_column($plan->blockers, 'code'));
        $transfer = collect($plan->blockers)->firstWhere('table', 'transfer_requests');
        $this->assertNotEmpty($transfer['samples']);

        try {
            app(SelectiveResetExecutor::class)->execute($this->org, $this->owner, $this->selection(['categories' => ['sales_orders']]), $plan->fingerprint);
            $this->fail('A blocked plan must not execute.');
        } catch (OrganizationDataResetException) {
        }
        $this->assertSame($before, $this->rows('sales_orders'));
    }

    public function test_sales_and_linked_operations_together_release_reservations_and_keep_stock(): void
    {
        $masters = $this->masters();
        $onHand = $this->onHand();
        $this->assertGreaterThan(0, (float) DB::table('inventory_balances')->where('organization_id', $this->org->id)->sum('reserved'));

        $result = $this->runReset(['categories' => ['sales_orders', 'quotations', 'purchase_orders', 'supplier_procurements', 'transfers']]);

        $this->assertSame(0, $this->rows('sales_orders'));
        $this->assertSame(0, $this->rows('transfer_requests'));
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('organization_id', $this->org->id)->sum('reserved'));
        $this->assertSame($onHand, $this->onHand());
        $this->assertSame($masters, $this->masters());
        $this->assertReservationsConsistent();
        $this->assertGreaterThan(0, $result['released']);
    }

    // === 4. Record-level and date-range selection =============================

    public function test_individual_record_selection_deletes_only_that_document(): void
    {
        // The manual Showroom order (SM58 + XLR) has no other dependent document.
        $order = SalesOrder::query()->where('organization_id', $this->org->id)->where('source', 'manual')
            ->whereDoesntHave('transferRequests')->whereDoesntHave('procurements')->orderBy('id')->firstOrFail();
        $others = $this->rows('sales_orders') - 1;

        $plan = $this->plan(['categories' => ['sales_orders'], 'records' => ['sales_orders' => [$order->id]]]);
        $this->assertSame(['sales_orders' => 1], $plan->selected);
        $this->runReset(['categories' => ['sales_orders'], 'records' => ['sales_orders' => [$order->id]]]);

        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
        $this->assertSame($others, $this->rows('sales_orders'));
        $this->assertReservationsConsistent();
    }

    public function test_date_range_uses_the_business_date_and_filters(): void
    {
        $order = SalesOrder::query()->where('organization_id', $this->org->id)->where('source', 'manual')
            ->whereDoesntHave('transferRequests')->whereDoesntHave('procurements')->orderBy('id')->firstOrFail();
        // created today, but its BUSINESS date is in January 2025.
        DB::table('sales_orders')->where('id', $order->id)->update(['sale_date' => '2025-01-15']);

        $plan = $this->plan(['categories' => ['sales_orders'], 'date_from' => '2025-01-01', 'date_to' => '2025-01-31']);
        $this->assertSame(['sales_orders' => 1], $plan->selected);
        $this->assertSame([$order->id], $plan->delete['sales_orders']);

        $none = $this->plan(['categories' => ['sales_orders'], 'date_from' => '2025-02-01', 'date_to' => '2025-02-28']);
        $this->assertSame(0, $none->selectedTotal());
        $this->assertFalse($none->isExecutable(), 'Nothing to delete is not executable.');
    }

    // === 5. Stale preview, rollback, repetition, tenant isolation =============

    public function test_a_changed_record_makes_the_preview_stale(): void
    {
        $data = ['categories' => ['purchase_orders', 'supplier_procurements']];
        $plan = $this->plan($data);
        DB::table('purchase_orders')->where('organization_id', $this->org->id)->update(['notes' => 'modifié entre-temps']);

        $this->expectException(OrganizationDataResetStalePlanException::class);
        app(SelectiveResetExecutor::class)->execute($this->org, $this->owner, $this->selection($data), $plan->fingerprint);
    }

    public function test_a_failure_rolls_everything_back_with_a_diagnostic_reference(): void
    {
        $data = ['categories' => ['purchase_orders', 'supplier_procurements']];
        $plan = $this->plan($data);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('Injected failure after deletes'));
        $this->app->instance(AuditLogger::class, $audit);

        try {
            $this->app->make(SelectiveResetExecutor::class)->execute($this->org, $this->owner, $this->selection($data), $plan->fingerprint);
            $this->fail('Expected failure.');
        } catch (OrganizationDataResetFailedException $exception) {
            $this->assertMatchesRegularExpression('/^RST-\d{6}-[A-Z0-9]{6}$/', $exception->reference);
        }
        $this->assertSame(1, $this->rows('purchase_orders'), 'Rolled back.');
        $this->assertSame(1, $this->rows('sales_order_procurements'));
    }

    public function test_repeating_an_executed_preview_is_refused(): void
    {
        $data = ['categories' => ['purchase_orders', 'supplier_procurements']];
        $plan = $this->plan($data);
        app(SelectiveResetExecutor::class)->execute($this->org, $this->owner, $this->selection($data), $plan->fingerprint);

        $this->expectException(OrganizationDataResetException::class);
        app(SelectiveResetExecutor::class)->execute($this->org, $this->owner, $this->selection($data), $plan->fingerprint);
    }

    public function test_another_organization_is_never_touched_and_cannot_select_foreign_records(): void
    {
        $intruder = User::factory()->create();
        $other = $this->createOrganization($intruder, 'Autre organisation');
        $this->activate($intruder, $other);
        $foreignOrder = DB::table('sales_orders')->where('organization_id', $this->org->id)->value('id');

        $plan = app(SelectiveResetPlanner::class)->plan($other, $this->selection(['categories' => ['sales_orders'], 'records' => ['sales_orders' => [$foreignOrder]]]));
        $this->assertSame(0, $plan->selectedTotal());
        $this->assertFalse($plan->isExecutable());

        $this->actingAs($intruder)->getJson(route('settings.data-reset.records', ['table' => 'sales_orders']))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('sales_orders', ['id' => $foreignOrder]);
    }

    // === 6. HTTP: preview, records, confirmation ==============================

    public function test_http_preview_records_and_confirmed_execution(): void
    {
        $selection = ['categories' => ['purchase_orders', 'supplier_procurements']];

        $this->actingAs($this->owner)->getJson(route('settings.data-reset.records', ['table' => 'purchase_orders', 'search' => 'BC']))
            ->assertOk()->assertJsonStructure(['data' => [['id', 'reference', 'date', 'status', 'party']], 'meta' => ['total'], 'statuses']);

        $preview = $this->actingAs($this->owner)->postJson(route('settings.data-reset.selective.preview'), ['selection' => $selection])
            ->assertOk()->assertJsonPath('data.executable', true)->json('data');
        $this->assertGreaterThan(0, $preview['delete_total']);
        $this->assertArrayHasKey('suppliers', $preview['protected']);

        $payload = [
            'selection' => $selection,
            'fingerprint' => $preview['fingerprint'],
            'confirmation_phrase' => SelectiveResetExecutor::PHRASE,
            'organization_name' => $this->org->name,
            'confirmed_total' => $preview['delete_total'] + 1,
        ];
        $this->actingAs($this->owner)->postJson(route('settings.data-reset.selective.execute'), $payload)
            ->assertStatus(409)->assertJson(['code' => 'FRESH_AUTH_REQUIRED']);
        $this->actingAs($this->owner)->withFreshAuthentication()->postJson(route('settings.data-reset.selective.execute'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed_total');
        $this->actingAs($this->owner)->withFreshAuthentication()->postJson(route('settings.data-reset.selective.execute'), ['confirmation_phrase' => 'NON'] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation_phrase');

        $this->actingAs($this->owner)->withFreshAuthentication()->postJson(route('settings.data-reset.selective.execute'), ['confirmed_total' => $preview['delete_total']] + $payload)
            ->assertOk()->assertJsonPath('data.completed', true)->assertJsonPath('data.deleted_records', $preview['delete_total']);
        $this->assertSame(0, $this->rows('purchase_orders'));
    }

    public function test_member_without_permission_cannot_use_selective_reset(): void
    {
        $member = User::factory()->create();
        $this->addOrganizationMember($this->org, $member);
        $this->activate($member, $this->org);

        $this->actingAs($member)->getJson(route('settings.data-reset.records', ['table' => 'sales_orders']))->assertForbidden();
        $this->actingAs($member)->postJson(route('settings.data-reset.selective.preview'), ['selection' => ['categories' => ['sales_orders']]])->assertForbidden();
    }
}
