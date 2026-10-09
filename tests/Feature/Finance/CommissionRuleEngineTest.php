<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\CommissionRuleSet;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\User;
use App\Services\Commissions\CommissionRuleManager;
use App\Services\Commissions\CommissionRuleResolver;
use App\Services\Commissions\CommissionSimulationService;
use App\Services\PermissionProvisioner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PosTestCase;

class CommissionRuleEngineTest extends PosTestCase
{
    public function test_boundaries_negative_margin_and_null_margin_resolve_deterministically(): void
    {
        [$owner, $organization] = $this->tenant();
        $set = $this->draft($owner, $organization, 'Barème', '2026-01-01');
        $resolver = app(CommissionRuleResolver::class);

        $this->assertSame('0.0000', $resolver->resolve($organization, '2026-01-01', '-12.5000', $set)['commission_rate']);
        $this->assertSame('5.0000', $resolver->resolve($organization, '2026-01-01', '10.0000', $set)['commission_rate']);
        $this->assertSame('8.0000', $resolver->resolve($organization, '2026-01-01', '20.0000', $set)['commission_rate']);
        $this->assertSame('12.0000', $resolver->resolve($organization, '2026-01-01', '30.0000', $set)['commission_rate']);
        $this->assertSame('not_evaluable', $resolver->resolve($organization, '2026-01-01', null, $set)['status']);
    }

    #[DataProvider('invalidTiers')]
    public function test_invalid_tier_topologies_are_rejected(array $tiers): void
    {
        [$owner, $organization] = $this->tenant();
        $this->expectException(ValidationException::class);
        app(CommissionRuleManager::class)->create($owner, $organization, ['name' => 'Invalid'], $tiers);
    }

    public static function invalidTiers(): array
    {
        return [
            'gap' => [[
                ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
                ['min_margin_rate' => '11', 'max_margin_rate' => null, 'commission_rate' => '5'],
            ]],
            'overlap' => [[
                ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
                ['min_margin_rate' => '9', 'max_margin_rate' => null, 'commission_rate' => '5'],
            ]],
            'inverted' => [[
                ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
                ['min_margin_rate' => '10', 'max_margin_rate' => '5', 'commission_rate' => '5'],
                ['min_margin_rate' => '5', 'max_margin_rate' => null, 'commission_rate' => '8'],
            ]],
            'bad commission' => [[['min_margin_rate' => null, 'max_margin_rate' => null, 'commission_rate' => '101']]],
        ];
    }

    public function test_activation_closes_previous_version_and_sale_date_selects_historical_rule(): void
    {
        [$owner, $organization] = $this->tenant();
        $manager = app(CommissionRuleManager::class);
        $v1 = $this->draft($owner, $organization, 'V1', '2026-01-01');
        $manager->activate($owner, $v1);
        $v2 = $this->draft($owner, $organization, 'V2', '2026-07-01', '15');
        $manager->activate($owner, $v2);

        $this->assertSame('2026-06-30', $v1->fresh()->effective_until->toDateString());
        $resolver = app(CommissionRuleResolver::class);
        $this->assertSame($v1->id, $resolver->resolve($organization, '2026-06-30', '40')['rule_set']['id']);
        $this->assertSame($v2->id, $resolver->resolve($organization, '2026-07-01', '40')['rule_set']['id']);

        $older = $this->draft($owner, $organization, 'Overlap', '2026-05-01');
        $this->expectException(ValidationException::class);
        $manager->activate($owner, $older);
    }

    public function test_simulation_uses_snapshots_and_return_reversal_uses_original_salesperson_and_rule_basis(): void
    {
        [$owner, $organization, $store] = $this->tenant(true);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Commission product', null, ['purchase_price' => '40', 'default_sale_price' => '100'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10');
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-08-10']);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $source = $order->lines()->sole();
        $set = $this->draft($owner, $organization, 'Simulation', '2026-01-01');

        $return = new CustomerReturn;
        $return->organization_id = $organization->id; $return->store_id = $store->id; $return->sales_order_id = $order->id; $return->warehouse_id = $warehouse->id;
        $return->return_number = 'RET-COM-1'; $return->client_operation_id = (string) Str::uuid(); $return->status = 'received'; $return->disposition = 'restock';
        $return->reason = 'Test'; $return->currency_code = 'MAD'; $return->subtotal_excl_tax = '100'; $return->discount_total = '0'; $return->tax_total = '0'; $return->total_incl_tax = '100';
        $return->policy_snapshot = []; $return->received_at = '2026-08-20 10:00:00'; $return->save();
        $line = new CustomerReturnLine;
        $line->organization_id = $organization->id; $line->customer_return_id = $return->id; $line->sales_order_line_id = $source->id; $line->product_variant_id = $variant->id; $line->position = 1;
        foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'unit_price_excl_tax', 'unit_price_incl_tax', 'tax_name', 'tax_rate'] as $field) $line->{$field} = $source->{$field};
        $line->quantity = '1'; $line->subtotal_excl_tax = '100'; $line->discount_amount = '0'; $line->taxable_amount = '100'; $line->tax_amount = '0'; $line->total_incl_tax = '100'; $line->save();

        $variant->purchase_price = '99'; $variant->save();
        $result = app(CommissionSimulationService::class)->simulate($organization, $set, '2026-08-01', '2026-08-31', $store);
        $this->assertSame('100.0000', $result['covered_revenue']);
        $this->assertSame('60.0000', $result['gross_margin']);
        $this->assertSame('7.2000', $result['estimated_commission']);
        $this->assertSame($owner->id, $result['by_salesperson'][0]['salesperson_id']);
        $this->assertSame(2, $result['eligible_line_count']);
    }

    public function test_permissions_fresh_auth_and_tenant_isolation_are_enforced(): void
    {
        [$ownerA, $organizationA, $storeA] = $this->tenant(true);
        [$ownerB, $organizationB] = $this->tenant();
        $setA = $this->draft($ownerA, $organizationA, 'A', '2026-01-01');
        $sales = User::factory()->create();
        $this->addDefaultSalesEmployee($organizationA, $storeA, $sales);

        $this->actingAs($sales)->get(route('finance.commissions.rules.index'))->assertForbidden();
        $this->activate($ownerB, $organizationB);
        $this->actingAs($ownerB)->put(route('finance.commissions.rules.update', $setA), [])->assertNotFound();

        $this->activate($ownerA, $organizationA, $storeA);
        $this->actingAs($ownerA)->post(route('finance.commissions.rules.activate', $setA))->assertRedirect(route('security.confirm'));
        $this->withFreshAuthentication(1)->actingAs($ownerA)->post(route('finance.commissions.rules.activate', $setA))->assertRedirect();
    }

    public function test_provisioning_updates_system_roles_and_finance_preset_but_not_existing_custom_roles(): void
    {
        [$owner, $organization] = $this->tenant();
        $custom = $this->createRole($organization, [], 'Existing Custom');
        app(PermissionProvisioner::class)->provisionOrganization($organization);

        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $sales = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $this->assertTrue($admin->permissions()->where('key', 'commissions.rules.activate')->exists());
        $this->assertFalse($sales->permissions()->where('key', 'commissions.rules.view')->exists());
        $this->assertFalse($custom->permissions()->where('key', 'commissions.rules.view')->exists());
        $this->assertContains('commissions.simulate', config('platform.presets.finance.permissions'));
        $this->assertNotContains('commissions.rules.activate', config('platform.presets.finance.permissions'));
        $this->assertTrue($owner->hasPermission($organization, 'commissions.rules.activate'));
    }

    private function draft(User $owner, $organization, string $name, ?string $from = null, string $topRate = '12'): CommissionRuleSet
    {
        return app(CommissionRuleManager::class)->create($owner, $organization, ['name' => $name, 'effective_from' => $from], [
            ['min_margin_rate' => null, 'max_margin_rate' => '10', 'commission_rate' => '0'],
            ['min_margin_rate' => '10', 'max_margin_rate' => '20', 'commission_rate' => '5'],
            ['min_margin_rate' => '20', 'max_margin_rate' => '30', 'commission_rate' => '8'],
            ['min_margin_rate' => '30', 'max_margin_rate' => null, 'commission_rate' => $topRate],
        ]);
    }

    private function tenant(bool $store = false): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $createdStore = $store ? $this->createStore($organization, $owner) : null;
        $this->activate($owner, $organization, $createdStore);
        return $store ? [$owner, $organization, $createdStore] : [$owner, $organization];
    }
}
