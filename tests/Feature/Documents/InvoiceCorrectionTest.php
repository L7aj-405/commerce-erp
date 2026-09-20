<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\StartInvoiceCorrectionAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

class InvoiceCorrectionTest extends DocumentTestCase
{
    public function test_future_invoice_only_corrections_are_disabled_in_http_and_domain_layers(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->assertFalse($owner->can('correct', $issued));
        $this->actingAs($owner)
            ->post(route('invoices.corrections.store', $issued), ['reason' => 'Changer le montant'])
            ->assertForbidden();

        try {
            app(StartInvoiceCorrectionAction::class)->execute($owner, $issued, 'Changer le montant');
            $this->fail('Invoice-only correction must be rejected by the domain action.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        $this->assertSame(InvoiceStatus::Issued, $issued->fresh()->status);
        $this->assertSame(0, Invoice::query()->where('corrected_invoice_id', $issued->id)->count());
    }

    public function test_invoice_correction_endpoint_preserves_tenant_hiding(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $outsider = User::factory()->create();
        $otherOrganization = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrganization, $outsider);
        $this->activate($outsider, $otherOrganization, $otherStore);

        $this->actingAs($outsider)
            ->post(route('invoices.corrections.store', $issued), ['reason' => 'attaque'])
            ->assertNotFound();

        $this->assertSame(InvoiceStatus::Issued, $issued->fresh()->status);
    }

    public function test_issued_invoice_remains_immutable_through_draft_metadata_endpoint(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $before = $issued->only(['invoice_number', 'customer_name', 'total_incl_tax', 'status']);

        $this->actingAs($owner)->patch(route('invoices.update', $issued), [
            'invoice_date' => '2026-06-01',
            'customer_name' => 'Modification interdite',
            'notes' => 'Modification interdite',
        ])->assertForbidden();

        $this->assertSame($before, $issued->fresh()->only(array_keys($before)));
    }
}
