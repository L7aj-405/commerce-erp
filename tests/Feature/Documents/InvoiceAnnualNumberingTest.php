<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentTestCase;

class InvoiceAnnualNumberingTest extends DocumentTestCase
{
    public function test_official_numbers_are_n_slash_year_and_restart_each_calendar_year(): void
    {
        [$owner, $organization, $store, $firstOrder] = $this->documentFixture();

        $first = $this->issueInvoice($owner, $this->createInvoice($owner, $firstOrder, ['invoice_date' => '2026-03-01']));
        $this->assertSame('1/2026', $first->invoice_number);

        $second = $this->issueInvoice($owner, $this->createInvoice(
            $owner,
            $this->confirmedOrder($owner, $organization, $store),
            ['invoice_date' => '2026-11-20'],
        ));
        $this->assertSame('2/2026', $second->invoice_number);

        $nextYear = $this->issueInvoice($owner, $this->createInvoice(
            $owner,
            $this->confirmedOrder($owner, $organization, $store),
            ['invoice_date' => '2027-01-04'],
        ));
        $this->assertSame('1/2027', $nextYear->invoice_number);

        // 2026 counter is untouched by the 2027 allocation.
        $another2026 = $this->issueInvoice($owner, $this->createInvoice(
            $owner,
            $this->confirmedOrder($owner, $organization, $store),
            ['invoice_date' => '2026-12-31'],
        ));
        $this->assertSame('3/2026', $another2026->invoice_number);
    }

    public function test_generator_serialises_consecutive_allocations_within_a_year(): void
    {
        [$owner, $organization] = $this->documentFixture();

        DB::transaction(function () use ($organization) {
            $generator = app(InvoiceNumberGenerator::class);
            $this->assertSame('1/2026', $generator->next($organization, 2026));
            $this->assertSame('2/2026', $generator->next($organization, 2026));
            $this->assertSame('1/2030', $generator->next($organization, 2030));
        });

        $this->assertSame(3, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)->where('year', 2026)->value('next_number'));
    }

    private function confirmedOrder(User $owner, Organization $organization, Store $store): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $order, ['description' => 'Service', 'unit_price_excl_tax' => '100.0000']);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }
}
