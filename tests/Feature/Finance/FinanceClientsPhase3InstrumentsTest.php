<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Customers\ManageCustomerInstrumentAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 3: instrument sub-status filtering is server-side, before pagination; totals and exports follow the filtered dataset. */
class FinanceClientsPhase3InstrumentsTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private FinancialAccount $account;

    private Customer $customer;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->account = $this->createFinancialAccount($this->org, ['type' => 'bank', 'accepted_methods' => ['cash', 'bank_transfer', 'card', 'cheque']]);
        $this->customer = $this->createCustomer($this->org, 'Atlas');
        $this->actingAs($this->owner);
        // 30 instruments in portfolio; the two OLDEST (lowest ids, listed last, so beyond page 1 of an unfiltered list) are rejected / cleared.
        for ($i = 1; $i <= 30; $i++) {
            $this->ids[] = app(ManageCustomerInstrumentAction::class)->receive($this->owner, $this->customer, [
                'type' => 'cheque', 'instrument_number' => sprintf('CHQ-%03d', $i), 'issuing_bank' => 'BMCE', 'amount' => '100.0000', 'received_date' => now()->toDateString(),
                'financial_account_id' => $this->account->id, 'maturity_date' => now()->addDays(5)->toDateString(),
            ], (string) Str::uuid());
        }
        DB::table('customer_payment_instruments')->where('id', $this->ids[0])->update(['state' => 'rejected']);
        DB::table('customer_payment_instruments')->where('id', $this->ids[1])->update(['state' => 'cleared']);
    }

    private function props(array $query): array
    {
        return $this->get(route('finance.clients.instruments', $query))->assertOk()->viewData('page')['props'];
    }

    private function numbers(array $props): array
    {
        return collect($props['instruments']['data'])->map(fn ($r) => ((object) $r)->instrument_number)->all();
    }

    public function test_sub_status_filter_applies_to_the_whole_dataset_before_pagination(): void
    {
        $portfolio = $this->props(['section' => 'portfolio']);
        $this->assertSame(28, $portfolio['instruments']['total']);
        $this->assertCount(25, $portfolio['instruments']['data']);

        // Matching rows that would be beyond page 1 of an unfiltered list are returned on page 1 of the filtered one.
        $this->assertSame(['CHQ-001'], $this->numbers($this->props(['section' => 'rejected'])));
        $this->assertSame(['CHQ-002'], $this->numbers($this->props(['section' => 'cashed'])));
        $this->assertCount(3, $this->props(['section' => 'portfolio', 'page' => 2])['instruments']['data']);
        $this->assertSame(['CHQ-030'], $this->numbers($this->props(['section' => 'portfolio', 'search' => 'CHQ-030'])));
    }

    public function test_totals_follow_the_filtered_dataset(): void
    {
        $rejected = collect($this->props(['section' => 'rejected'])['totals']);
        $this->assertSame(['rejected'], $rejected->pluck('state')->unique()->values()->all());
        $this->assertSame('100.0000', $rejected->first()['total']);
        $portfolio = collect($this->props(['section' => 'portfolio'])['totals']);
        $this->assertSame(28, $portfolio->sum('count'));
        $this->assertSame('2800.0000', number_format($portfolio->sum(fn ($t) => (float) $t['total']), 4, '.', ''));
    }

    public function test_export_contains_the_complete_filtered_dataset_with_search_and_dates(): void
    {
        $lines = fn (array $q) => array_values(array_filter(explode("\n", trim($this->get(route('finance.clients.instruments.export', ['format' => 'csv'] + $q))->assertOk()->streamedContent()))));

        $this->assertCount(1 + 28, $lines(['section' => 'portfolio']), 'all 28 portfolio instruments, not only the 25 of page 1');
        $this->assertCount(1 + 1, $lines(['section' => 'rejected']));
        $this->assertCount(1 + 1, $lines(['section' => 'portfolio', 'search' => 'CHQ-017']));
        $this->assertCount(1, $lines(['section' => 'portfolio', 'from' => now()->addDay()->toDateString()]), 'date filter excludes everything (header only)');
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance_clients.instruments_exported']);
    }

    public function test_instrument_export_needs_finance_export_and_sections_are_validated(): void
    {
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->org, $viewer, ['finance.view'], 'active', 'I'.Str::random(4));
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->org, $this->store);
        $this->actingAs($viewer)->get(route('finance.clients.instruments.export', 'csv'))->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.clients.instruments', ['section' => 'bogus']))->assertSessionHasErrors('section');
        $this->actingAs($this->owner)->get(route('finance.clients.instruments.export', 'pdf'))->assertNotFound();
    }
}
