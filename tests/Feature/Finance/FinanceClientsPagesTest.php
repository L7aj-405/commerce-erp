<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 1 (UI/UX organization): the nine pages, legacy aliases, authorization, scopes and honesty of placeholders. */
class FinanceClientsPagesTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private SalesOrder $order;

    private FinancialAccount $account;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->org, $this->store, $this->order, $this->account] = $this->paymentFixture('500.0000');
        $this->activate($this->owner, $this->org, $this->store);
        $this->customer = $this->createCustomer($this->org, 'Atlas Trading', ['phone' => '0600000000']);
        $this->account->accepted_methods = ['cash', 'bank_transfer', 'card', 'cheque'];
        $this->account->save();
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->org, $user, $permissions, 'active', 'R'.Str::random(5));
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->org, $this->store);

        return $user;
    }

    /** @return array<string,string> route name => component (every Finance clients entry, in navigation order) */
    private function entries(): array
    {
        return [
            'finance.clients.overview' => 'Finance/Clients/Overview',
            'finance.clients.accounts' => 'Finance/Clients/Accounts',
            'finance.clients.invoicing' => 'Finance/Facturation',
            'finance.clients.collections' => 'Finance/Encaissements',
            'finance.clients.receivables' => 'Finance/Creances',
            'finance.clients.credit' => 'Finance/Clients/Planned',
            'finance.clients.instruments' => 'Finance/Clients/Planned',
            'finance.clients.journal' => 'Finance/Journal',
            'finance.clients.reports' => 'Finance/Clients/Reports',
        ];
    }

    public function test_all_nine_pages_render_for_an_authorized_user_with_the_expected_components(): void
    {
        $this->actingAs($this->owner);
        foreach ($this->entries() as $route => $component) {
            $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        }
        $this->get(route('finance.clients.accounts.show', $this->customer))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Finance/Clients/AccountShow'));
        $this->get(route('finance.personnel'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Finance/Clients/Planned')->where('page', 'personnel'));
    }

    public function test_legacy_destinations_remain_accessible_and_aliases_serve_the_same_data(): void
    {
        $this->actingAs($this->owner);
        foreach (['/finance', '/finance/ventes', '/finance/facturation', '/finance/encaissements', '/finance/creances', '/finance/journal', '/finance/ca-encaisse', '/payments', '/financial-accounts'] as $legacy) {
            $this->get($legacy)->assertOk();
        }
        $pairs = ['/finance/facturation' => '/finance/clients/facturation', '/finance/encaissements' => '/finance/clients/encaissements',
            '/finance/creances' => '/finance/clients/creances', '/finance/journal' => '/finance/clients/journal'];
        foreach ($pairs as $legacy => $alias) {
            $a = $this->get($legacy)->viewData('page');
            $b = $this->get($alias)->viewData('page');
            $this->assertSame($a['component'], $b['component']);
            $this->assertEquals($a['props']['period'], $b['props']['period']);
        }
    }

    public function test_every_new_route_requires_finance_view_on_the_server_including_placeholders(): void
    {
        $nobody = $this->member(['customers.view', 'payments.view', 'invoices.view', 'financial_accounts.view']);
        $this->actingAs($nobody);
        foreach (array_keys($this->entries()) as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('finance.clients.accounts.show', $this->customer))->assertForbidden();
        $this->get(route('finance.personnel'))->assertForbidden();
    }

    public function test_customer_accounts_need_both_finance_view_and_customer_permission(): void
    {
        $this->actingAs($this->member(['finance.view']));
        $this->get(route('finance.clients.accounts'))->assertForbidden();
        $this->get(route('finance.clients.accounts.show', $this->customer))->assertForbidden();
        $this->actingAs($this->member(['customers.view']));
        $this->get(route('finance.clients.accounts'))->assertForbidden();
        $this->actingAs($this->member(['finance.view', 'customers.view']));
        $this->get(route('finance.clients.accounts'))->assertOk();
        $this->get(route('finance.clients.accounts.show', $this->customer))->assertOk();
    }

    public function test_foreign_organization_customer_and_store_are_not_found(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignStore = $this->createStore($foreignOrg, $foreignOwner, 'Foreign Store');
        $foreignCustomer = $this->createCustomer($foreignOrg, 'Foreign Customer');

        $this->actingAs($this->owner);
        $this->get(route('finance.clients.accounts.show', $foreignCustomer))->assertNotFound();
        $this->get(route('finance.clients.overview', ['store_id' => $foreignStore->id]))->assertNotFound();
        $this->get(route('finance.clients.collections', ['store_id' => $foreignStore->id]))->assertNotFound();
        $accounts = $this->get(route('finance.clients.accounts', ['search' => 'Foreign']))->assertOk()->viewData('page')['props']['customers']['data'];
        $this->assertSame([], $accounts);
    }

    public function test_overview_metrics_are_the_existing_situation_values_and_unavailable_figures_are_null(): void
    {
        $this->recordPayment($this->owner, $this->order, $this->account, '120.0000', ['payment_date' => now()->toDateString()]);
        $this->actingAs($this->owner);
        $props = $this->get(route('finance.clients.overview'))->assertOk()->viewData('page')['props'];
        $situation = app(FinanceMonthlyReportService::class)->situation($this->org, FinancePeriod::current($this->org), null);

        $this->assertSame($situation['encaissements'], $props['metrics']['collections']);
        $this->assertSame($situation['ventes_nettes'], $props['metrics']['sales']);
        $this->assertSame($situation['facturation'], $props['metrics']['billing']);
        $this->assertSame($situation['creances_fin'], $props['metrics']['receivables']);
        $this->assertSame($situation['remboursements'], $props['metrics']['refunds']);
        $this->assertSame($situation['obligations_remboursement'], $props['metrics']['refund_obligations']);
        $this->assertNull($props['metrics']['unbilled_orders'], 'no organization-wide aggregate exists: never a fabricated zero');
        $this->assertSame('120.0000', $props['metrics']['collections']);
    }

    public function test_placeholder_pages_carry_no_financial_data(): void
    {
        $this->recordPayment($this->owner, $this->order, $this->account, '50.0000', ['payment_date' => now()->toDateString()]);
        $this->actingAs($this->owner);
        foreach (['finance.clients.credit' => 'credit', 'finance.clients.instruments' => 'instruments', 'finance.personnel' => 'personnel'] as $route => $key) {
            $props = $this->get(route($route))->assertOk()->viewData('page')['props'];
            $this->assertSame($key, $props['page']);
            $business = array_diff(array_keys($props), ['page', 'organization', 'errors', 'auth', 'tenant', 'flash', 'sandbox', 'locale', 'supplierInvoices', 'features', 'ziggy', 'name', 'quote']);
            $this->assertEqualsCanonicalizing([], array_values(array_filter($business, fn ($k) => in_array($k, ['rows', 'items', 'data', 'metrics', 'total', 'customers', 'payments', 'invoices', 'orders'], true))));
        }
    }

    public function test_invoicing_summary_is_additive_and_exact(): void
    {
        $this->actingAs($this->owner);
        $summary = $this->get('/finance/facturation')->viewData('page')['props']['summary'];
        $this->assertSame(['gross', 'credit_notes', 'net'], array_keys($summary));
        $this->assertSame($summary['gross'], bcadd($summary['net'], $summary['credit_notes'], 4));
        $this->assertSame($summary, $this->get(route('finance.clients.invoicing'))->viewData('page')['props']['summary']);
    }

    public function test_account_page_is_viewer_scoped_and_hides_figures_the_viewer_may_not_see(): void
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '200.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order);
        $this->recordPayment($this->owner, $order, $this->account, '80.0000', ['payment_date' => now()->toDateString()]);

        $this->actingAs($this->owner);
        $full = $this->get(route('finance.clients.accounts.show', [$this->customer, 'section' => 'payments']))->assertOk()->viewData('page')['props'];
        $this->assertSame('120.0000', $full['position']['unbilled']);
        $this->assertCount(1, $full['payments']);

        // finance.view + customers.view but NO payments/orders/invoices permission: figures stay unavailable (null), lists empty.
        $this->actingAs($this->member(['finance.view', 'customers.view']));
        $limited = $this->get(route('finance.clients.accounts.show', [$this->customer, 'section' => 'payments']))->assertOk()->viewData('page')['props'];
        $this->assertNull($limited['position']['paid']);
        $this->assertNull($limited['position']['receivable']);
        $this->assertNull($limited['position']['unbilled']);
        $this->assertSame([], $limited['payments']);
    }

    public function test_accounts_index_has_constant_query_count_and_no_per_customer_position(): void
    {
        $this->actingAs($this->owner);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('finance.clients.accounts'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $this->createCustomer($this->org, 'C1');
        $count(); // warm-up: session / membership bootstrap queries
        $before = $count();
        foreach (range(2, 15) as $i) {
            $this->createCustomer($this->org, "C{$i}");
        }
        $this->assertSame($before, $count(), 'query count must not grow with the number of customers');
        $props = $this->get(route('finance.clients.accounts'))->viewData('page')['props']['customers']['data'];
        $this->assertContains('position', array_keys($props[0]));
        $this->assertSame('0.0000', $props[0]['position']['unbilled'], 'a customer without documents has a real zero, not an unavailable figure');
    }

    public function test_new_routes_are_not_available_in_test_mode(): void
    {
        $this->actingAs($this->owner);
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        foreach (array_keys($this->entries()) as $route) {
            $this->get(route($route))->assertRedirect('/test-mode');
        }
        $this->get(route('finance.clients.accounts.show', $this->customer))->assertRedirect('/test-mode');
        $this->get(route('finance.personnel'))->assertRedirect('/test-mode');
    }
}
