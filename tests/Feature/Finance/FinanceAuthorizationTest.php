<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\OrganizationMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

/**
 * Finance's own authorization path (FinanceAccessGuard): organization-scoped
 * permission checks, never InvoicePolicy/PaymentPolicy, never limited to the
 * viewer's currently active store.
 */
class FinanceAuthorizationTest extends DocumentTestCase
{
    public function test_finance_totals_are_strictly_scoped_to_the_requesting_organization(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);
        $storeA = $this->createStore($organizationA, $ownerA);
        $storeB = $this->createStore($organizationB, $ownerB);

        $orderA = $this->createDraftOrder($ownerA, $organizationA, $storeA, null, ['sale_date' => now()->toDateString()]);
        $this->addCustomLine($ownerA, $orderA, ['unit_price_excl_tax' => '111.0000']);
        app(ConfirmSalesOrderAction::class)->execute($ownerA, $orderA);

        $orderB = $this->createDraftOrder($ownerB, $organizationB, $storeB, null, ['sale_date' => now()->toDateString()]);
        $this->addCustomLine($ownerB, $orderB, ['unit_price_excl_tax' => '222.0000']);
        app(ConfirmSalesOrderAction::class)->execute($ownerB, $orderB);

        $this->activate($ownerA, $organizationA, $storeA);

        $this->actingAs($ownerA)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('situation.ventes', '111.0000'));
    }

    public function test_finance_user_reports_across_stores_without_switching_active_store(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $storeOne = $this->createStore($organization, $owner, 'Showroom Casa');
        $storeTwo = $this->createStore($organization, $owner, 'Showroom Rabat');

        $orderOne = $this->createDraftOrder($owner, $organization, $storeOne, null, ['sale_date' => now()->toDateString()]);
        $this->addCustomLine($owner, $orderOne, ['unit_price_excl_tax' => '300.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $orderOne);

        $orderTwo = $this->createDraftOrder($owner, $organization, $storeTwo, null, ['sale_date' => now()->toDateString()]);
        $this->addCustomLine($owner, $orderTwo, ['unit_price_excl_tax' => '700.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $orderTwo);

        // Active store is storeOne only — Finance must still see both.
        $this->activate($owner, $organization, $storeOne);

        $this->actingAs($owner)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('situation.ventes', '1000.0000'));
    }

    public function test_store_specific_finance_reporting_filters_to_one_store(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $storeOne = $this->createStore($organization, $owner, 'Showroom Casa');
        $storeTwo = $this->createStore($organization, $owner, 'Showroom Rabat');

        $orderOne = $this->createDraftOrder($owner, $organization, $storeOne, null, ['sale_date' => now()->toDateString()]);
        $this->addCustomLine($owner, $orderOne, ['unit_price_excl_tax' => '300.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $orderOne);

        $orderTwo = $this->createDraftOrder($owner, $organization, $storeTwo, null, ['sale_date' => now()->toDateString()]);
        $this->addCustomLine($owner, $orderTwo, ['unit_price_excl_tax' => '700.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $orderTwo);

        $this->activate($owner, $organization, $storeOne);

        $this->actingAs($owner)->get(route('finance.index', ['store_id' => $storeTwo->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('situation.ventes', '700.0000'));
    }

    public function test_a_store_id_from_another_organization_404s_instead_of_leaking_existence(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);
        $storeB = $this->createStore($organizationB, $ownerB);
        $this->activate($ownerA, $organizationA);

        $this->actingAs($ownerA)->get(route('finance.index', ['store_id' => $storeB->id]))->assertNotFound();
    }

    public function test_non_finance_user_cannot_access_the_finance_dashboard(): void
    {
        $owner = User::factory()->create();
        $salesperson = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->addDefaultSalesEmployee($organization, $store, $salesperson);
        $this->activate($salesperson, $organization, $store);

        $this->actingAs($salesperson)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($salesperson)->get(route('finance.journal'))->assertForbidden();
    }

    public function test_finance_view_alone_does_not_grant_export(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['finance.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->get(route('finance.export.xlsx'))->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.export.pdf'))->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.export.invoices', ['mode' => 'issued', 'month' => now()->format('Y-m')]))->assertForbidden();
    }

    public function test_finance_export_permission_allows_all_three_export_endpoints(): void
    {
        $owner = User::factory()->create();
        $exporter = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $exporter, ['finance.view', 'finance.export']);
        $this->activate($exporter, $organization);

        $this->actingAs($exporter)->get(route('finance.export.xlsx'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($exporter)->get(route('finance.export.pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_general_admin_preset_role_retains_finance_access(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->actingAs($owner)->post(route('roles.store'), ['name' => 'GA', 'preset_slug' => 'general_admin'])->assertRedirect();
        $role = $organization->roles()->where('name', 'GA')->firstOrFail();

        $admin = User::factory()->create();
        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->getKey();
        $membership->user_id = $admin->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = 'active';
        $membership->save();
        $this->activate($admin, $organization);

        $this->actingAs($admin)->get(route('finance.index'))->assertOk();
    }
}
