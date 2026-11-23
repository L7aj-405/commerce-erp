<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Models\User;
use App\Services\Finance\FinanceMonthlySituationService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceTreasuryForecastService;
use App\Services\PermissionProvisioner;
use App\Support\Decimal;
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

    /** FIN-001: an unsettled obligation due before a future month but not yet overdue must be listed, as the forecast counts it. */
    public function test_future_month_lists_not_yet_overdue_prior_obligations_matching_the_forecast(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $action = app(ManageFinanceObligationAction::class);
        foreach ([['2026-11-22', 'Overdue'], ['2026-11-30', 'Not yet due, before month'], ['2026-12-23', 'In month']] as [$due, $label]) {
            $action->create($owner, $organization, $this->data(['label' => $label, 'due_date' => $due, 'planned_amount' => '120.0000']));
        }

        $this->travelTo('2026-11-23');
        $this->actingAs($owner)->get(route('finance.monthly-situation.index', ['month' => '2026-12']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('obligations.data', 3)
            ->where('obligations.data.0.label', 'Overdue')
            ->where('obligations.data.0.carry_forward', true)
            ->where('obligations.data.0.status', 'overdue')
            ->where('obligations.data.0.remaining_amount', '120.0000')
            ->where('obligations.data.1.label', 'Not yet due, before month')
            ->where('obligations.data.1.carry_forward', true)
            ->where('obligations.data.1.status', 'upcoming')
            ->where('obligations.data.1.remaining_amount', '120.0000')
            ->where('obligations.data.2.label', 'In month')
            ->where('obligations.data.2.carry_forward', false)
            ->where('obligations.data.2.status', 'upcoming')
            ->where('obligations.data.2.remaining_amount', '120.0000')
            ->where('treasury.remaining_outflows', '360.0000')
            ->where('kpis.overdue', '120.0000')
            ->where('kpis.remaining', '120.0000'));
    }

    /** FIN-001: the export and the supplier summary share the list scope, so all three obligations reconcile to the forecast. */
    public function test_export_and_supplier_summary_include_all_three_obligations_and_reconcile_with_the_forecast(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $action = app(ManageFinanceObligationAction::class);
        foreach ([['2026-11-22', 'Overdue'], ['2026-11-30', 'Not yet due, before month'], ['2026-12-23', 'In month']] as [$due, $label]) {
            $action->create($owner, $organization, $this->data(['label' => $label, 'due_date' => $due, 'planned_amount' => '120.0000']));
        }
        // A cancelled obligation due before the month must stay out of every view.
        $cancelled = $action->create($owner, $organization, $this->data(['label' => 'Cancelled', 'due_date' => '2026-11-28', 'planned_amount' => '500.0000']));
        $action->cancel($owner, $cancelled, 'FIN-001 regression');

        $this->travelTo('2026-11-23');
        $service = app(FinanceMonthlySituationService::class);
        $period = FinancePeriod::fromMonth('2026-12');

        $export = $service->exportRows($organization, $period, null, [])->all();
        $this->assertSame(['Overdue', 'Not yet due, before month', 'In month'], array_column($export, 'label'));
        $this->assertSame(['120.0000', '120.0000', '120.0000'], array_column($export, 'remaining'));

        $summary = $service->supplierSummary($organization, $period, null);
        $this->assertSame('360.0000', array_reduce($summary, fn (string $sum, array $row) => Decimal::add($sum, $row['remaining']), '0.0000'));
        $this->assertSame('120.0000', array_reduce($summary, fn (string $sum, array $row) => Decimal::add($sum, $row['overdue']), '0.0000'));

        $forecast = app(FinanceTreasuryForecastService::class)->forecast($organization, $period, null);
        $this->assertSame('360.0000', $forecast['remaining_outflows']);
    }

    public function test_store_category_status_and_search_filters_are_applied_server_side(): void
    {
        // "upcoming" is relative to today: pinned before the default 2026-10-25 due date.
        $this->travelTo('2026-10-17');
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
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $viewer = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);
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
