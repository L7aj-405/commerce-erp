<?php

namespace Tests\Feature\Finance;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentTestCase;

/** Finance clients Phase 3: the credit risk register is filtered and paginated server-side over ALL followed customers (no 500 cap). */
class FinanceClientsPhase3CreditRegisterTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->actingAs($this->owner);
        // 520 followed customers; alphabetically the LAST ones (beyond a 500 cap) are the blocked / watched ones.
        for ($i = 1; $i <= 520; $i++) {
            $this->ids[$i] = $this->createCustomer($this->org, sprintf('Client %04d', $i))->id;
        }
        $rows = [];
        foreach ($this->ids as $i => $id) {
            $rows[] = ['organization_id' => $this->org->id, 'customer_id' => $id, 'credit_enabled' => true, 'credit_limit' => '1000.0000', 'policy' => 'warn',
                'financial_status' => $i === 519 ? 'blocked' : ($i === 520 ? 'watch' : 'normal'), 'overdue_tolerance_days' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('customer_financial_terms')->insert($chunk);
        }
    }

    private function props(array $query = []): array
    {
        return $this->get(route('finance.clients.credit', $query))->assertOk()->viewData('page')['props'];
    }

    public function test_customers_beyond_the_first_500_are_listed_filtered_and_paginated(): void
    {
        $all = $this->props();
        $this->assertSame(520, $all['meta']['total']);
        $this->assertSame(11, $all['meta']['pages']);
        $this->assertCount(50, $all['rows']);
        $this->assertSame(520, $all['counts']['limits']);
        $this->assertSame(1, $all['counts']['blocked']);
        $this->assertSame(1, $all['counts']['watch']);

        $blocked = $this->props(['filter' => 'blocked']);
        $this->assertSame(['Client 0519'], collect($blocked['rows'])->pluck('customer')->all());
        $this->assertSame(['Client 0520'], collect($this->props(['filter' => 'watch'])['rows'])->pluck('customer')->all());

        $last = $this->props(['page' => 11]);
        $this->assertSame(11, $last['meta']['page']);
        $this->assertSame(['Client 0501', 'Client 0520'], [collect($last['rows'])->first()['customer'], collect($last['rows'])->last()['customer']]);
        $this->assertCount(20, $last['rows']);
    }

    public function test_legacy_section_bookmarks_select_their_population_server_side(): void
    {
        $this->assertSame(['Client 0519'], collect($this->props(['section' => 'blocked'])['rows'])->pluck('customer')->all());
        $this->assertSame('blocked', $this->props(['section' => 'blocked'])['filter']);
        $this->assertSame('all', $this->props(['filter' => 'bogus'])['filter']);
    }

    public function test_page_counts_use_exact_integer_boundaries(): void
    {
        // 520 rows, 50 per page: partial final page (11 pages).
        $this->assertSame(11, $this->props()['meta']['pages']);
        // An empty population still has exactly one (empty) page.
        $this->assertSame(0, $this->props(['filter' => 'overruns'])['meta']['total']);
        $this->assertSame(1, $this->props(['filter' => 'overruns'])['meta']['pages']);
        $this->assertSame([], $this->props(['filter' => 'overruns'])['rows']);
        // Exactly 50 rows -> exactly 1 page; 51 rows -> 2 pages.
        DB::table('customer_financial_terms')->where('organization_id', $this->org->id)->whereNotIn('customer_id', array_slice($this->ids, 0, 50))->delete();
        $this->assertSame(1, $this->props()['meta']['pages']);
        $this->assertCount(50, $this->props()['rows']);
        DB::table('customer_financial_terms')->insert(['organization_id' => $this->org->id, 'customer_id' => $this->ids[51], 'credit_enabled' => true, 'credit_limit' => '1000.0000', 'policy' => 'warn',
            'financial_status' => 'normal', 'overdue_tolerance_days' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(2, $this->props()['meta']['pages']);
        $this->assertCount(1, $this->props(['page' => 2])['rows']);
        // A page beyond the last one is clamped to the last page.
        $this->assertSame(2, $this->props(['page' => 99])['meta']['page']);
    }
}
