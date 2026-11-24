<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\ManageCustomerAdvanceAction;
use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 3: the Avances register (derived statuses, history, scope, authorization, export) and the relocated pages' legacy URLs. */
class FinanceClientsPhase3AdvancesTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    private FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->customer = $this->createCustomer($this->org, 'Atlas');
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $total): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    private function advance(string $amount): Payment
    {
        return app(RecordCustomerSettlementAction::class)->execute($this->owner, $this->customer, [
            'financial_account_id' => $this->account->id, 'method' => 'bank_transfer', 'amount' => $amount, 'payment_date' => now()->toDateString(), 'allocations' => [],
        ], (string) Str::uuid());
    }

    /** @return array<int, array<string, mixed>> register rows keyed by payment id */
    private function register(array $query = []): array
    {
        $rows = $this->get(route('finance.clients.advances', $query))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Clients/Advances'))->viewData('page')['props']['advances']['data'];

        return collect($rows)->keyBy('id')->all();
    }

    public function test_register_derives_available_partial_allocated_and_reversed_from_real_records(): void
    {
        $available = $this->advance('100.0000');
        $partial = $this->advance('200.0000');
        $full = $this->advance('300.0000');
        $order = $this->order('1000.0000');
        $actions = app(ManageCustomerAdvanceAction::class);
        $actions->allocate($this->owner, $partial, [['sales_order_id' => $order->id, 'amount' => '50.0000']]);
        $actions->allocate($this->owner, $full, [['sales_order_id' => $order->id, 'amount' => '300.0000']]);
        $paymentsBefore = DB::table('payments')->count();

        $rows = $this->register();
        $this->assertSame('available', $rows[$available->id]['state']);
        $this->assertSame('100.0000', $rows[$available->id]['remaining']);
        $this->assertSame('partial', $rows[$partial->id]['state']);
        $this->assertSame(['200.0000', '50.0000', '150.0000'], [$rows[$partial->id]['amount'], $rows[$partial->id]['allocated'], $rows[$partial->id]['remaining']]);
        $this->assertSame('allocated', $rows[$full->id]['state']);
        $this->assertSame('0.0000', $rows[$full->id]['remaining']);
        $this->assertSame($order->order_number, $rows[$full->id]['allocations'][0]['order_number']);
        $this->assertNotEmpty($rows[$full->id]['allocations'][0]['effective_date']);
        $this->assertSame($paymentsBefore, DB::table('payments')->count(), 'viewing the register never creates a payment');

        // Filter by derived status keeps pagination totals honest.
        $this->assertSame([$available->id], array_keys($this->register(['status' => 'available'])));
        $this->assertEqualsCanonicalizing([$partial->id, $full->id], array_merge(array_keys($this->register(['status' => 'partial'])), array_keys($this->register(['status' => 'allocated']))));

        // Releasing an allocation puts the amount back and keeps its history.
        $actions->deallocate($this->owner, $full->fresh(), $order, 'Erreur');
        $after = $this->register();
        $this->assertSame('available', $after[$full->id]['state']);
        $this->assertCount(1, $after[$full->id]['releases']);
        $this->assertSame('Erreur', $after[$full->id]['releases'][0]['reason']);

        // Posted advance carrying no pending cheque: an instrument in portfolio is never listed as an advance.
        $this->assertCount(3, $after);
    }

    public function test_register_filters_search_dates_customer_and_is_organization_scoped(): void
    {
        $mine = $this->advance('100.0000');
        $other = $this->createCustomer($this->org, 'Zenith');
        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner, 'Foreign');
        $foreignStore = $this->createStore($foreignOrg, $foreignOwner, 'Foreign Store');
        $foreignCustomer = $this->createCustomer($foreignOrg, 'Foreign Customer');
        $foreignAccount = $this->createFinancialAccount($foreignOrg, ['type' => 'bank', 'accepted_methods' => ['bank_transfer']]);
        $this->activate($foreignOwner, $foreignOrg, $foreignStore);
        $this->actingAs($foreignOwner);
        app(RecordCustomerSettlementAction::class)->execute($foreignOwner, $foreignCustomer, ['financial_account_id' => $foreignAccount->id, 'method' => 'bank_transfer', 'amount' => '999.0000',
            'payment_date' => now()->toDateString(), 'allocations' => []], (string) Str::uuid());
        $this->actingAs($this->owner);

        $this->assertSame([$mine->id], array_keys($this->register()));
        $this->assertSame([], $this->register(['customer_id' => $other->id]));
        $this->assertSame([$mine->id], array_keys($this->register(['q' => 'Atl'])));
        $this->assertSame([], $this->register(['q' => 'Foreign']));
        $this->assertSame([], $this->register(['from' => now()->addDay()->toDateString()]));
        $this->assertSame([$mine->id], array_keys($this->register(['from' => now()->toDateString(), 'to' => now()->toDateString()])));
        $this->get(route('finance.clients.advances', ['status' => 'bogus']))->assertSessionHasErrors('status');
    }

    public function test_register_requires_finance_view_and_payment_visibility_and_export_requires_export_permission(): void
    {
        $this->advance('100.0000');
        $member = fn (array $permissions) => tap(User::factory()->create(), function (User $u) use ($permissions) {
            $this->addOrganizationMember($this->org, $u, $permissions, 'active', 'R'.Str::random(5));
            $this->addStoreMember($this->store, $u);
            $this->activate($u, $this->org, $this->store);
        });

        $this->actingAs($member(['payments.view']))->get(route('finance.clients.advances'))->assertForbidden();
        $this->actingAs($member(['finance.view']))->get(route('finance.clients.advances'))->assertForbidden();
        $viewer = $member(['finance.view', 'payments.view']);
        $this->actingAs($viewer)->get(route('finance.clients.advances'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('can.export', false)->where('can.allocate', false));
        $this->actingAs($viewer)->get(route('finance.clients.advances.export', 'csv'))->assertForbidden();

        $exporter = $member(['finance.view', 'payments.view', 'finance.export']);
        $csv = $this->actingAs($exporter)->get(route('finance.clients.advances.export', 'csv'));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Atlas', $body);
        $this->assertStringContainsString('100.0000', $body);
        $this->actingAs($exporter)->get(route('finance.clients.advances.export', 'pdf'))->assertNotFound();
    }

    public function test_relocated_pages_keep_their_urls_and_the_advances_page_is_reachable(): void
    {
        foreach (['finance.clients.instruments', 'finance.clients.credit', 'finance.clients.journal', 'finance.clients.reports', 'finance.clients.advances'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['/finance/journal', '/finance/clients/journal', '/finance/clients/credit', '/finance/clients/instruments'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
