<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
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

    public function test_settings_can_start_invoice_numbering_at_a_configured_annual_number_without_draft_consumption(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();

        $this->actingAs($owner)->put(route('document-profile.update'), [
            'invoice_numbering_year' => 2026,
            'invoice_next_number' => 21,
        ])->assertRedirect();

        $draft = $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-21']);

        $this->assertNull($draft->invoice_number);
        $this->assertSame(21, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));

        $issued = $this->issueInvoice($owner, $draft);

        $this->assertSame('21/2026', $issued->invoice_number);
        $this->assertSame(22, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));
    }

    public function test_next_number_setting_cannot_move_behind_already_allocated_canonical_numbers(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        app(InvoiceNumberGenerator::class)->configureNextNumber($organization, 2026, 21);
        $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-21']));

        $this->actingAs($owner)->put(route('document-profile.update'), [
            'invoice_numbering_year' => 2026,
            'invoice_next_number' => 21,
        ])->assertSessionHasErrors('invoice_next_number');

        $this->actingAs($owner)->put(route('document-profile.update'), [
            'invoice_numbering_year' => 2026,
            'invoice_next_number' => 20,
        ])->assertSessionHasErrors('invoice_next_number');

        $this->assertSame(22, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));
    }

    public function test_configured_start_is_isolated_by_organization_and_calendar_year(): void
    {
        [$firstOwner, $firstOrganization, $firstStore, $firstOrder] = $this->documentFixture();
        [$secondOwner, $secondOrganization, $secondStore, $secondOrder] = $this->documentFixture();

        $generator = app(InvoiceNumberGenerator::class);
        $generator->configureNextNumber($firstOrganization, 2026, 21);
        $generator->configureNextNumber($firstOrganization, 2027, 145);
        $generator->configureNextNumber($secondOrganization, 2026, 300);

        $first2026 = $this->issueInvoice($firstOwner, $this->createInvoice($firstOwner, $firstOrder, ['invoice_date' => '2026-09-21']));
        $first2027 = $this->issueInvoice($firstOwner, $this->createInvoice(
            $firstOwner,
            $this->confirmedOrder($firstOwner, $firstOrganization, $firstStore),
            ['invoice_date' => '2027-01-10'],
        ));
        $second2026 = $this->issueInvoice($secondOwner, $this->createInvoice($secondOwner, $secondOrder, ['invoice_date' => '2026-09-21']));

        $this->assertSame('21/2026', $first2026->invoice_number);
        $this->assertSame('145/2027', $first2027->invoice_number);
        $this->assertSame('300/2026', $second2026->invoice_number);
    }

    public function test_replacement_invoice_versions_reuse_canonical_number_without_consuming_the_next_sequence(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        app(InvoiceNumberGenerator::class)->configureNextNumber($organization, 2026, 21);

        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-09-21']));
        $this->assertSame('21/2026', $original->invoice_number);
        $this->assertSame(22, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));

        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order->fresh(), 'Correction commerciale');
        $correctedOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $replacement = $this->issueInvoice($owner, $this->createInvoice($owner, $correctedOrder, ['invoice_date' => '2026-09-22']));

        $this->assertSame(2, $replacement->version);
        $this->assertSame('21/2026', $replacement->invoice_number);
        $this->assertSame(22, (int) DB::table('invoice_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));

        $nextInvoice = $this->issueInvoice($owner, $this->createInvoice(
            $owner,
            $this->confirmedOrder($owner, $organization, $store),
            ['invoice_date' => '2026-09-23'],
        ));

        $this->assertSame('22/2026', $nextInvoice->invoice_number);
    }

    private function confirmedOrder(User $owner, Organization $organization, Store $store): SalesOrder
    {
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $order, ['description' => 'Service', 'unit_price_excl_tax' => '100.0000']);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }
}
