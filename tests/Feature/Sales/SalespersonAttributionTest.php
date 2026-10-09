<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\ReassignSalesOrderSalespersonAction;
use App\Actions\Sales\UpdateSalesOrderAction;
use App\Enums\SalesOrderSource;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\SalesMarginReportService;
use App\Services\PermissionProvisioner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosTestCase;

class SalespersonAttributionTest extends PosTestCase
{
    public function test_permission_provisioning_updates_system_roles_but_not_custom_roles(): void
    {
        [$owner, $organization] = $this->base();
        $custom = $this->createRole($organization, [], 'Existing Custom');

        app(PermissionProvisioner::class)->provisionOrganization($organization);

        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $sales = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $this->assertTrue($admin->permissions()->where('key', 'sales_orders.assign_salesperson')->exists());
        $this->assertTrue($admin->permissions()->where('key', 'sales_orders.reassign_salesperson')->exists());
        $this->assertFalse($sales->permissions()->where('key', 'sales_orders.assign_salesperson')->exists());
        $this->assertFalse($custom->permissions()->whereIn('key', [
            'sales_orders.assign_salesperson', 'sales_orders.reassign_salesperson',
        ])->exists());
        $this->assertTrue($owner->hasPermission($organization, 'sales_orders.reassign_salesperson'));
    }

    public function test_manual_order_defaults_to_actor_and_manager_can_assign_an_eligible_member(): void
    {
        [$owner, $organization, $store] = $this->base();
        $salesperson = User::factory()->create(['name' => 'Commercial A']);
        $this->addDefaultSalesEmployee($organization, $store, $salesperson);
        $this->activate($owner, $organization, $store);

        $own = $this->createDraftOrder($owner, $organization, $store);
        $this->assertSame($owner->id, $own->salesperson_id);

        $assigned = app(CreateSalesOrderAction::class)->execute($owner, $organization, $store, [
            'customer_id' => null,
            'salesperson_id' => $salesperson->id,
            'sale_date' => '2026-08-24',
            'currency_code' => 'MAD',
        ]);
        $this->assertSame($salesperson->id, $assigned->salesperson_id);
        $this->assertSame('Commercial A', $assigned->salesperson_name_snapshot);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.salesperson_assigned', 'auditable_id' => (string) $assigned->id]);
    }

    public function test_normal_salesperson_cannot_assign_another_user_and_cross_organization_ids_are_rejected(): void
    {
        [$owner, $organization, $store] = $this->base();
        $salesA = User::factory()->create();
        $salesB = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $salesA);
        $this->addDefaultSalesEmployee($organization, $store, $salesB);
        $order = $this->createDraftOrder($salesA, $organization, $store);

        try {
            app(UpdateSalesOrderAction::class)->execute($salesA, $order, [
                'customer_id' => null, 'salesperson_id' => $salesB->id,
                'sale_date' => '2026-08-24', 'currency_code' => 'MAD',
            ]);
            $this->fail('A normal salesperson must not assign another user.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner);
        $this->activate($owner, $organization, $store);
        $this->expectException(ValidationException::class);
        app(UpdateSalesOrderAction::class)->execute($owner, $order->fresh(), [
            'customer_id' => null, 'salesperson_id' => $foreignOwner->id,
            'sale_date' => '2026-08-24', 'currency_code' => 'MAD',
        ]);
    }

    public function test_pos_checkout_attributes_the_sale_to_authenticated_user_and_ignores_forged_input(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockBase();
        $other = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $other);
        $this->activate($owner, $organization, $store);

        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant)]);
        $payload['salesperson_id'] = $other->id;
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();

        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame($owner->id, $order->salesperson_id);
        $this->assertSame($owner->name, $order->salesperson_name_snapshot);
    }

    public function test_confirmation_freezes_name_and_catalog_or_membership_changes_do_not_erase_attribution(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockBase();
        $salesperson = User::factory()->create(['name' => 'Nom historique']);
        $this->addDefaultSalesEmployee($organization, $store, $salesperson);
        $this->activate($owner, $organization, $store);
        $order = app(CreateSalesOrderAction::class)->execute($owner, $organization, $store, [
            'salesperson_id' => $salesperson->id, 'sale_date' => '2026-08-24', 'currency_code' => 'MAD',
        ]);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        $salesperson->name = 'Nouveau nom';
        $salesperson->save();
        $salesperson->organizationMemberships()->where('organization_id', $organization->id)->update(['status' => 'inactive']);

        $this->assertSame($salesperson->id, $order->fresh()->salesperson_id);
        $this->assertSame('Nom historique', $order->fresh()->salesperson_name_snapshot);
    }

    public function test_confirmed_reassignment_is_privileged_explicit_and_audited(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockBase();
        $newSalesperson = User::factory()->create(['name' => 'Nouvelle commerciale']);
        $this->addDefaultSalesEmployee($organization, $store, $newSalesperson);
        $this->activate($owner, $organization, $store);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order);

        $updated = app(ReassignSalesOrderSalespersonAction::class)->execute(
            $owner, $order, $newSalesperson->id, 'Correction du vendeur responsable',
        );
        $this->assertSame($newSalesperson->id, $updated->salesperson_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.salesperson_reassigned', 'auditable_id' => (string) $order->id]);

        $this->activate($newSalesperson, $organization, $store);
        $this->expectException(HttpException::class);
        app(ReassignSalesOrderSalespersonAction::class)->execute($newSalesperson, $updated, $owner->id, 'Tentative');
    }

    public function test_legacy_orders_remain_unattributed_and_reporting_has_filtered_and_unattributed_buckets(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockBase('40', '100');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        $service = app(SalesMarginReportService::class);
        $filtered = $service->forPeriod($organization, '2026-08-01', '2026-08-31', $store, $owner->id);
        $this->assertSame('200.0000', $filtered['net_revenue_excl_tax']);
        $this->assertSame('120.0000', $filtered['gross_margin_amount']);

        $order->salesperson_id = null;
        $order->salesperson_name_snapshot = null;
        $order->save();
        $variant->purchase_price = '99.0000';
        $variant->save();

        $legacy = $service->forPeriod($organization, '2026-08-01', '2026-08-31', $store, null, true);
        $this->assertSame('120.0000', $legacy['gross_margin_amount']);
        $bucket = collect($service->bySalesperson($organization, '2026-08-01', '2026-08-31', $store))->sole();
        $this->assertNull($bucket['salesperson_id']);
        $this->assertSame('120.0000', $bucket['gross_margin_amount']);
    }

    public function test_received_return_reverses_margin_against_original_salesperson_bucket(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->stockBase('40', '100');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $source = $order->lines()->sole();

        $return = new CustomerReturn;
        $return->organization_id = $organization->id;
        $return->store_id = $store->id;
        $return->sales_order_id = $order->id;
        $return->warehouse_id = $warehouse->id;
        $return->return_number = 'RET-MARGIN-1';
        $return->client_operation_id = (string) Str::uuid();
        $return->status = 'received';
        $return->disposition = 'restock';
        $return->reason = 'Test';
        $return->currency_code = 'MAD';
        $return->subtotal_excl_tax = '100';
        $return->discount_total = '0';
        $return->tax_total = '0';
        $return->total_incl_tax = '100';
        $return->policy_snapshot = [];
        $return->received_at = '2026-08-25 12:00:00';
        $return->save();

        $line = new CustomerReturnLine;
        $line->organization_id = $organization->id;
        $line->customer_return_id = $return->id;
        $line->sales_order_line_id = $source->id;
        $line->product_variant_id = $variant->id;
        $line->position = 1;
        foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'unit_price_excl_tax', 'unit_price_incl_tax', 'tax_name', 'tax_rate'] as $field) {
            $line->{$field} = $source->{$field};
        }
        $line->quantity = '1';
        $line->subtotal_excl_tax = '100';
        $line->discount_amount = '0';
        $line->taxable_amount = '100';
        $line->tax_amount = '0';
        $line->total_incl_tax = '100';
        $line->save();

        $bucket = collect(app(SalesMarginReportService::class)->bySalesperson(
            $organization, '2026-08-01', '2026-08-31', $store,
        ))->sole();
        $this->assertSame($owner->id, $bucket['salesperson_id']);
        $this->assertSame('100.0000', $bucket['net_revenue_excl_tax']);
        $this->assertSame('40.0000', $bucket['cost_total']);
        $this->assertSame('60.0000', $bucket['gross_margin_amount']);
    }

    private function base(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store];
    }

    private function stockBase(string $cost = '50', string $price = '100'): array
    {
        [$owner, $organization, $store] = $this->base();
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Attributed Product', null, [
            'purchase_price' => $cost,
            'default_sale_price' => $price,
        ])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '20');

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
