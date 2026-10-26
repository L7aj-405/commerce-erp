<?php

namespace Tests\Feature\Sales;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Mcp\Sales\CustomerReadService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/** W5.1: the customer profile only ever serializes, counts and sums documents the viewer may see. */
class CustomerStoreIsolationTest extends QuotationTestCase
{
    private const ALL = ['customers.view', 'quotations.view', 'sales_orders.view', 'invoices.view', 'payments.view', 'delivery_notes.view', 'follow_ups.view', 'follow_ups.manage_all'];

    /** @return array{Organization, Store, Store, Customer, array<string, array{order: SalesOrder, invoice: Invoice, quotation: Quotation}>} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $storeA = $this->createStore($organization, $owner, 'Store A');
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $customer = $this->createCustomer($organization, 'ACME');
        $account = $this->createFinancialAccount($organization);
        $documents = [];
        foreach (['A' => [$storeA, '100.0000'], 'B' => [$storeB, '300.0000']] as $key => [$store, $price]) {
            $this->activate($owner, $organization, $store);
            $order = $this->createDraftOrder($owner, $organization, $store, $customer);
            $this->addCustomLine($owner, $order, ['description' => "Prestation {$key}", 'unit_price_excl_tax' => $price]);
            $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
            $this->recordPayment($owner, $order, $account, (string) $order->total_incl_tax);
            $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order->fresh()));
            $quotation = $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->getKey()]);
            $documents[$key] = ['order' => $order->fresh(), 'invoice' => $invoice, 'quotation' => $quotation];
        }
        app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Relance magasin B', 'sales_order_id' => $documents['B']['order']->getKey(), 'due_date' => '2026-11-12']);
        app(SaveFollowUpAction::class)->create($owner, $organization, ['title' => 'Relance client', 'customer_id' => $customer->getKey(), 'due_date' => '2026-11-12']);

        return [$organization, $storeA, $storeB, $customer, $documents];
    }

    /** @param list<Store> $stores */
    private function viewer(Organization $organization, array $stores, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, $permissions);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }
        $this->activate($user, $organization, $stores[0]);

        return $user;
    }

    private function page(User $user, Customer $customer): TestResponse
    {
        return $this->actingAs($user)->get('/sales/customers/'.$customer->getKey())->assertOk();
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    public function test_store_a_only_user_never_receives_store_b_documents_counts_or_amounts(): void
    {
        [$organization, $storeA, , $customer, $docs] = $this->fixture();
        $viewer = $this->viewer($organization, [$storeA], self::ALL);
        $response = $this->page($viewer, $customer);
        $props = $this->props($response);

        $this->assertSame([(int) $docs['A']['order']->getKey()], array_column($props['orders'], 'id'));
        $this->assertSame([(int) $docs['A']['invoice']->getKey()], array_column($props['invoices'], 'id'));
        $this->assertSame([(int) $docs['A']['quotation']->getKey()], array_column($props['quotations'], 'id'));
        $this->assertSame([1, 1, 1, 1], [$props['ordersCount'], $props['invoicesCount'], $props['quotationsCount'], $props['paymentsCount']]);

        $orderA = Decimal::normalize((string) $docs['A']['order']->total_incl_tax);
        $this->assertSame([
            'sales_total' => $orderA, 'invoiced_total' => Decimal::normalize((string) $docs['A']['invoice']->total_incl_tax),
            'paid_total' => $orderA, 'outstanding' => '0.0000', 'last_activity' => $props['summary']['last_activity'],
        ], $props['summary']);

        // Nothing about Store B is serialized at all.
        $payload = json_encode($props);
        foreach ([$docs['B']['order']->order_number, $docs['B']['invoice']->invoice_number, 'Relance magasin B'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $payload);
        }
        $this->assertSame(['Relance client'], array_column($props['followUps']['items'], 'title'));
    }

    public function test_multi_store_user_sees_both_stores_and_opens_other_store_documents_through_the_safe_opener(): void
    {
        [$organization, $storeA, $storeB, $customer, $docs] = $this->fixture();
        $both = $this->viewer($organization, [$storeA, $storeB], self::ALL);
        $props = $this->props($this->page($both, $customer));
        $this->assertSame(2, $props['ordersCount']);
        $this->assertSame(2, $props['invoicesCount']);
        $this->assertEqualsCanonicalizing(['Relance client', 'Relance magasin B'], array_column($props['followUps']['items'], 'title'));
        $this->assertSame(Decimal::add((string) $docs['A']['order']->total_incl_tax, (string) $docs['B']['order']->total_incl_tax), $props['summary']['sales_total']);

        $orderB = $docs['B']['order'];
        $this->actingAs($both)->post('/documents/open/sales_order/'.$orderB->getKey())->assertRedirect(route('sales.orders.show', $orderB->getKey()));
        $this->assertSame((int) $storeB->getKey(), (int) $both->fresh()->active_store_id);

        $aOnly = $this->viewer($organization, [$storeA], self::ALL);
        $this->actingAs($aOnly)->post('/documents/open/invoice/'.$docs['B']['invoice']->getKey())->assertSessionHasErrors('invoice');
        $this->actingAs($aOnly)->get('/sales/orders/'.$orderB->getKey())->assertNotFound();
        $this->assertSame((int) $storeA->getKey(), (int) $aOnly->fresh()->active_store_id);

        // An inactive store is no longer an accessible store.
        DB::table('stores')->where('id', $storeB->getKey())->update(['status' => 'inactive']);
        $this->activate($both, $organization, $storeA);
        $this->assertSame(1, $this->props($this->page($both->fresh(), $customer))['ordersCount']);
    }

    public function test_permissions_gate_lists_and_financial_figures(): void
    {
        [$organization, $storeA, , $customer] = $this->fixture();

        $noFinance = $this->viewer($organization, [$storeA], ['customers.view', 'sales_orders.view', 'invoices.view']);
        $props = $this->props($this->page($noFinance, $customer));
        $this->assertSame(1, $props['ordersCount']);
        $this->assertSame([[], 0], [$props['payments'], $props['paymentsCount']]);
        $this->assertNull($props['summary']['paid_total']);
        $this->assertNull($props['summary']['outstanding'], 'Outstanding derives from payments: hidden without payments.view.');
        $this->assertNotNull($props['summary']['invoiced_total']);

        $customersOnly = $this->viewer($organization, [$storeA], ['customers.view']);
        $props = $this->props($this->page($customersOnly, $customer));
        $this->assertSame([0, 0, 0, 0, 0], [$props['ordersCount'], $props['invoicesCount'], $props['quotationsCount'], $props['paymentsCount'], $props['deliveryNotesCount']]);
        $this->assertSame(['sales_total' => null, 'invoiced_total' => null, 'paid_total' => null, 'outstanding' => null, 'last_activity' => null], $props['summary']);
    }

    public function test_other_organizations_never_see_the_customer_and_mcp_balance_uses_the_same_scope(): void
    {
        [$organization, $storeA, , $customer, $docs] = $this->fixture();
        $stranger = User::factory()->create();
        $other = $this->createOrganization($stranger, 'Other');
        $this->activate($stranger, $other, $this->createStore($other, $stranger, 'Other store'));
        $this->actingAs($stranger)->get('/sales/customers/'.$customer->getKey())->assertNotFound();

        $aOnly = $this->viewer($organization, [$storeA], self::ALL);
        $balance = app(CustomerReadService::class)->balance($organization, (int) $customer->getKey(), $aOnly);
        $this->assertSame(Decimal::normalize((string) $docs['A']['order']->total_incl_tax), $balance['sales_total']);
    }

    public function test_customer_page_and_customer_timeline_expose_the_same_document_universe(): void
    {
        [$organization, $storeA, $storeB, $customer] = $this->fixture();
        foreach ([[$storeA], [$storeA, $storeB]] as $stores) {
            $viewer = $this->viewer($organization, $stores, self::ALL);
            $props = $this->props($this->page($viewer, $customer));
            $page = [
                ...array_map(fn ($id) => "quotation:{$id}", array_column($props['quotations'], 'id')),
                ...array_map(fn ($id) => "sales_order:{$id}", array_column($props['orders'], 'id')),
                ...array_map(fn ($id) => "invoice:{$id}", array_column($props['invoices'], 'id')),
            ];
            $entries = $this->actingAs($viewer)->getJson('/timeline/customer/'.$customer->getKey().'?per_page=50')->assertOk()->json('entries');
            $timeline = collect($entries)->pluck('subject')->filter(fn ($subject) => in_array($subject['type'] ?? null, ['quotation', 'sales_order', 'invoice'], true))
                ->map(fn ($subject) => $subject['type'].':'.$subject['id'])->unique()->values()->all();

            $this->assertEqualsCanonicalizing($page, $timeline, 'Stores: '.count($stores));
        }
    }
}
