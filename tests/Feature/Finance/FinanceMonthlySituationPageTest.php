<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Models\User;
use App\Services\PermissionProvisioner;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

class FinanceMonthlySituationPageTest extends PaymentTestCase
{
    public function test_page_shows_selected_month_and_prior_overdue_carry_forward_with_correct_kpis(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $action = app(ManageFinanceObligationAction::class);
        $action->create($owner, $organization, $this->data(['label' => 'Retard septembre', 'due_date' => '2026-09-30', 'planned_amount' => '3000.0000']));
        $action->create($owner, $organization, $this->data(['label' => 'Loyer octobre', 'due_date' => '2026-10-15', 'planned_amount' => '10000.0000', 'store_id' => $store->id]));

        $this->travelTo('2026-10-17');
        $this->actingAs($owner)->get(route('finance.monthly-situation.index', ['month' => '2026-10']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Finance/MonthlySituation')
                ->where('kpis.planned', '10000.0000')
                ->where('kpis.remaining', '10000.0000')
                ->where('kpis.overdue', '3000.0000')
                ->where('kpis.count', 1)
                ->has('obligations.data', 2)
                ->where('obligations.data.0.carry_forward', true)
                ->where('obligations.data.0.status', 'overdue'));
    }

    public function test_store_category_status_and_search_filters_are_applied_server_side(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $one = $this->createStore($organization, $owner, 'Casa');
        $two = $this->createStore($organization, $owner, 'Rabat');
        $this->activate($owner, $organization, $one);
        $action = app(ManageFinanceObligationAction::class);
        $action->create($owner, $organization, $this->data(['store_id' => $one->id, 'category' => 'cnss', 'label' => 'CNSS Casa', 'reference' => 'REF-CASA']));
        $action->create($owner, $organization, $this->data(['store_id' => $two->id, 'category' => 'rent', 'label' => 'Loyer Rabat']));

        $this->actingAs($owner)->get(route('finance.monthly-situation.index', ['month' => '2026-10', 'store_id' => $one->id, 'category' => 'cnss', 'status' => 'upcoming', 'search' => 'REF-CASA']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('obligations.data', 1)->where('obligations.data.0.label', 'CNSS Casa'));
    }

    public function test_permissions_are_separate_and_cross_tenant_store_filter_is_hidden(): void
    {
        $ownerA = User::factory()->create(); $ownerB = User::factory()->create(); $viewer = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA); $organizationB = $this->createOrganization($ownerB);
        $foreignStore = $this->createStore($organizationB, $ownerB);
        $this->addOrganizationMember($organizationA, $viewer, ['finance.monthly_situation.view']);
        $this->activate($viewer, $organizationA);

        $this->actingAs($viewer)->get(route('finance.monthly-situation.index'))->assertOk();
        $this->actingAs($viewer)->post(route('finance.monthly-situation.obligations.store'), $this->data())->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.monthly-situation.index', ['store_id' => $foreignStore->id]))->assertNotFound();
    }

    public function test_system_admin_and_finance_preset_receive_permissions_but_custom_roles_are_unchanged(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $this->assertEqualsCanonicalizing([
            'finance.monthly_situation.view', 'finance.monthly_situation.manage', 'finance.outgoing_payments.manage',
        ], $admin->permissions()->whereIn('key', [
            'finance.monthly_situation.view', 'finance.monthly_situation.manage', 'finance.outgoing_payments.manage',
        ])->pluck('key')->all());

        $custom = $this->createRole($organization, ['finance.view'], 'Legacy custom');
        app(PermissionProvisioner::class)->provisionAllOrganizations();
        $this->assertSame(['finance.view'], $custom->permissions()->pluck('key')->sort()->values()->all());
        $this->assertContains('finance.monthly_situation.view', config('platform.presets.finance.permissions'));
        $this->assertContains('finance.monthly_situation.manage', config('platform.presets.finance.permissions'));
        $this->assertContains('finance.outgoing_payments.manage', config('platform.presets.finance.permissions'));
        $this->assertContains('finance.monthly_situation.export', config('platform.presets.finance.permissions'));
    }

    public function test_recording_outgoing_money_requires_fresh_authentication_after_authorization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $account = $this->createFinancialAccount($organization, ['type' => 'bank', 'code' => 'BANK', 'accepted_methods' => ['bank_transfer']]);
        $obligation = app(ManageFinanceObligationAction::class)->create($owner, $organization, $this->data(['planned_amount' => '1000.0000']));
        $payload = [
            'store_id' => null, 'financial_account_id' => $account->id, 'supplier_id' => null, 'beneficiary_name' => 'Bailleur',
            'payment_method' => 'bank_transfer', 'amount' => '1000.0000', 'currency_code' => 'MAD', 'payment_date' => '2026-10-20',
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => $obligation->id, 'amount' => '1000.0000']],
        ];

        $this->actingAs($owner)->postJson(route('finance.monthly-situation.payments.store'), $payload)
            ->assertStatus(409)
            ->assertJson(['code' => 'FRESH_AUTH_REQUIRED', 'level' => 'L1']);
        $this->assertDatabaseCount('finance_outgoing_payments', 0);

        $this->postJson(route('security.confirm.password'), ['password' => 'password'])
            ->assertOk()
            ->assertJson(['confirmed' => true, 'level' => 'L1']);

        $this->post(route('finance.monthly-situation.payments.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('finance_outgoing_payments', 1);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['store_id' => null, 'category' => 'rent', 'label' => 'Loyer', 'supplier_id' => null, 'beneficiary_name' => 'Bailleur', 'reference' => null, 'due_date' => '2026-10-25', 'planned_amount' => '1000.0000', 'currency_code' => 'MAD', 'planned_payment_method' => null, 'preferred_financial_account_id' => null, 'notes' => null], $overrides);
    }
}
