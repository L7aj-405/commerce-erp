<?php

namespace Tests\Feature\Pos;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

/**
 * POS-W1 — the member's default warehouse is only the POS's initial selection.
 */
class PosDefaultWarehouseTest extends PosTestCase
{
    public function test_each_salesperson_opens_pos_on_their_own_default_warehouse(): void
    {
        [$owner, $organization, $mainStore, $spStore, $central, $main, $sp] = $this->showrooms();
        $asma = User::factory()->create();
        $youssef = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $mainStore, $asma);
        $this->addDefaultSalesEmployee($organization, $spStore, $youssef);
        $this->setDefault($organization, $asma, $main);
        $this->setDefault($organization, $youssef, $sp);

        $this->actingAs($asma)->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $main->id)
            ->where('memberDefaultWarehouseId', $main->id)
            ->has('warehouses', 3));

        $this->actingAs($youssef)->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $sp->id)
            ->where('memberDefaultWarehouseId', $sp->id)
            ->has('warehouses', 3));
    }

    public function test_owner_default_warehouse_is_used(): void
    {
        [$owner, $organization, $mainStore, , , , $sp] = $this->showrooms();
        $this->activate($owner, $organization, $mainStore);
        $this->setDefault($organization, $owner, $sp);

        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $sp->id));
    }

    public function test_member_without_default_keeps_the_existing_first_active_warehouse_fallback(): void
    {
        [$owner, $organization, $mainStore, , $central] = $this->showrooms();
        $this->activate($owner, $organization, $mainStore);

        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $central->id)
            ->where('memberDefaultWarehouseId', null));
    }

    public function test_inactive_default_warehouse_is_ignored_without_error(): void
    {
        [$owner, $organization, $mainStore, , $central, $main] = $this->showrooms();
        $this->activate($owner, $organization, $mainStore);
        $this->setDefault($organization, $owner, $main);
        $main->status = 'inactive';
        $main->save();

        $this->actingAs($owner)->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $central->id)
            ->where('memberDefaultWarehouseId', null)
            ->has('warehouses', 2));
    }

    public function test_active_draft_warehouse_is_not_overridden_by_the_default(): void
    {
        [$owner, $organization, $mainStore, , , $main, $sp] = $this->showrooms();
        $this->setDefault($organization, $owner, $main);
        $this->createPosDraft($owner, $organization, $mainStore, $sp);

        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $sp->id)
            ->where('memberDefaultWarehouseId', $main->id)
            ->where('activeSale.warehouse.id', $sp->id));
    }

    public function test_user_can_still_manually_sell_from_another_warehouse(): void
    {
        [$owner, $organization, $mainStore, , , $main, $sp] = $this->showrooms();
        $asma = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $mainStore, $asma);
        $this->setDefault($organization, $asma, $main);

        $this->actingAs($asma)->postJson(route('pos.drafts.store'), ['warehouse_id' => $sp->id])
            ->assertOk()
            ->assertJsonPath('active_sale.warehouse.id', $sp->id);

        // The explicit choice persists through the active draft on the next visit.
        $this->actingAs($asma)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $sp->id)
            ->where('activeSale.warehouse.id', $sp->id));
    }

    public function test_hold_and_start_new_sale_without_explicit_warehouse_uses_member_default(): void
    {
        [$owner, $organization, $mainStore, , , $main, $sp] = $this->showrooms();
        $this->setDefault($organization, $owner, $sp);
        $draft = $this->createPosDraft($owner, $organization, $mainStore, $main);

        $this->actingAs($owner)->postJson(route('pos.drafts.hold', $draft), ['start_new_sale' => true])
            ->assertOk()
            ->assertJsonPath('active_sale.warehouse.id', $sp->id);
    }

    public function test_default_from_another_organization_never_initializes_pos(): void
    {
        [$owner, $organizationA, $storeA, , $centralA] = $this->showrooms();
        $organizationB = $this->createOrganization($owner, 'Organization B');
        $warehouseB = $this->createWarehouse($organizationB, 'AAA B Warehouse', 'B-MAIN');
        $this->setDefault($organizationB, $owner, $warehouseB);
        $this->activate($owner, $organizationA, $storeA);

        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('defaultWarehouseId', $centralA->id)
            ->where('memberDefaultWarehouseId', null));
    }

    public function test_database_rejects_a_cross_organization_default_warehouse(): void
    {
        [$owner, $organizationA] = $this->showrooms();
        $organizationB = $this->createOrganization($owner, 'Organization B');
        $warehouseB = $this->createWarehouse($organizationB, 'B Warehouse', 'B-MAIN');

        $this->expectException(QueryException::class);
        $this->setDefault($organizationA, $owner, $warehouseB);
    }

    public function test_checkout_still_deducts_stock_from_the_selected_warehouse(): void
    {
        [$owner, $organization, $mainStore, , , $main, $sp] = $this->showrooms();
        $variant = $this->createProduct($organization, 'Lamp', 'LAMP-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($owner, $organization, $main, $variant, '5.0000');
        $this->openStock($owner, $organization, $sp, $variant, '5.0000');
        $this->activate($owner, $organization, $mainStore);
        $this->setDefault($organization, $owner, $main);

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($sp, [$this->posCatalogLine($variant)]))
            ->assertRedirect();

        $this->assertSame('5.0000', (string) $main->balances()->where('product_variant_id', $variant->id)->value('on_hand'));
        $this->assertSame('4.0000', (string) $sp->balances()->where('product_variant_id', $variant->id)->value('on_hand'));
        $this->assertSame(1, SalesOrder::query()->where('organization_id', $organization->id)->where('status', '!=', 'draft')->count());
    }

    /** @return array{User, Organization, Store, Store, Warehouse, Warehouse, Warehouse} */
    private function showrooms(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $mainStore = $this->createStore($organization, $owner, 'Showroom MAIN');
        $spStore = $this->createStore($organization, $owner, 'Showroom SP');
        // "Central" sorts first by name, so it is the legacy fallback.
        $central = $this->createWarehouse($organization, 'Central Warehouse', 'CENTRAL');
        $main = $this->createWarehouse($organization, 'Showroom MAIN', 'MAIN');
        $sp = $this->createWarehouse($organization, 'Showroom SP', 'SP');

        return [$owner, $organization, $mainStore, $spStore, $central, $main, $sp];
    }

    private function setDefault(Organization $organization, User $user, ?Warehouse $warehouse): void
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $membership->default_warehouse_id = $warehouse?->id;
        $membership->save();
    }
}
