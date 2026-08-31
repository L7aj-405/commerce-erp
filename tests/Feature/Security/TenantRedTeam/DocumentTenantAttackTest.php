<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

class DocumentTenantAttackTest extends DocumentTestCase
{
    public function test_known_document_ids_from_same_organization_other_store_are_hidden(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $storeA = $this->createStore($organization, $owner, 'Store A');
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $orderB = $this->createDraftOrder($owner, $organization, $storeB);
        $this->addCustomLine($owner, $orderB);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($owner, $orderB);
        $orderB = app(FulfillSalesOrderAction::class)->execute($owner, $orderB->fresh());
        $invoiceB = $this->createInvoice($owner, $orderB);
        $noteB = $this->createDeliveryNote($owner, $orderB);
        $this->activate($owner, $organization, $storeA);
        $this->actingAs($owner)->get(route('invoices.show', $invoiceB->id))->assertNotFound();
        $this->actingAs($owner)->get(route('delivery-notes.show', $noteB->id))->assertNotFound();
        $this->actingAs($owner)->post(route('sales.orders.invoices.store', $orderB->id))->assertNotFound();
        $this->actingAs($owner)->post(route('sales.orders.delivery-notes.store', $orderB->id))->assertNotFound();
    }

    public function test_known_document_ids_from_other_organization_are_hidden(): void
    {
        Mail::fake();
        [$ownerA, $organizationA, $storeA] = $this->documentFixture();
        [$ownerB, , , $orderB] = $this->documentFixture(true);
        $invoiceB = $this->createInvoice($ownerB, $orderB);
        $noteB = $this->createDeliveryNote($ownerB, $orderB);
        $this->activate($ownerA, $organizationA, $storeA);
        $this->actingAs($ownerA)->get(route('invoices.show', $invoiceB->id))->assertNotFound();
        $this->actingAs($ownerA)->get(route('delivery-notes.show', $noteB->id))->assertNotFound();
        foreach (['invoices.print', 'invoices.pdf', 'invoices.download'] as $route) {
            $this->actingAs($ownerA)->get(route($route, $invoiceB))->assertNotFound();
        }
        foreach (['delivery-notes.print', 'delivery-notes.pdf', 'delivery-notes.download'] as $route) {
            $this->actingAs($ownerA)->get(route($route, $noteB))->assertNotFound();
        }
        $this->actingAs($ownerA)->post(route('invoices.email', $invoiceB), ['email' => 'attacker@example.test'])->assertNotFound();
        $this->actingAs($ownerA)->post(route('delivery-notes.email', $noteB), ['email' => 'attacker@example.test'])->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_render_download_and_email_routes_hide_documents_outside_the_active_store(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $storeA = $this->createStore($organization, $owner, 'Store A');
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $orderB = $this->createDraftOrder($owner, $organization, $storeB);
        $this->addCustomLine($owner, $orderB);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($owner, $orderB);
        $orderB = app(FulfillSalesOrderAction::class)->execute($owner, $orderB->fresh());
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $orderB));
        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $orderB));
        $this->activate($owner, $organization, $storeA);

        foreach (['invoices.print', 'invoices.pdf', 'invoices.download'] as $route) {
            $this->actingAs($owner)->get(route($route, $invoice))->assertNotFound();
        }
        foreach (['delivery-notes.print', 'delivery-notes.pdf', 'delivery-notes.download'] as $route) {
            $this->actingAs($owner)->get(route($route, $note))->assertNotFound();
        }
        $this->actingAs($owner)->post(route('invoices.email', $invoice), ['email' => 'attacker@example.test'])->assertNotFound();
        $this->actingAs($owner)->post(route('delivery-notes.email', $note), ['email' => 'attacker@example.test'])->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_forged_authoritative_fields_are_ignored_on_document_creation(): void
    {
        [$ownerA, $organizationA, $storeA, $orderA] = $this->documentFixture(true);
        [$ownerB, $organizationB, $storeB, $orderB] = $this->documentFixture(true);
        $payload = [
            'organization_id' => $organizationB->id, 'store_id' => $storeB->id, 'sales_order_id' => $orderB->id,
            'invoice_number' => 'INV-FORGED', 'delivery_note_number' => 'DN-FORGED', 'status' => 'issued',
            'issued_by_user_id' => $ownerB->id, 'issued_at' => now(), 'total_incl_tax' => '0.0000',
            'seller_snapshot' => ['legal_name' => 'FORGED SELLER'], 'template_version' => 'attacker-template',
            'storage_path' => '../../public/forged.pdf', 'filename' => 'customer-pii.pdf', 'mail_attachment' => 'forged-bytes',
            'sales_order_line_id' => $orderB->lines()->firstOrFail()->id, 'line_ids' => [$orderB->lines()->firstOrFail()->id],
            'invoice_date' => now()->toDateString(), 'delivery_date' => now()->toDateString(),
        ];
        $this->actingAs($ownerA)->post(route('sales.orders.invoices.store', $orderA), $payload)->assertRedirect();
        $this->actingAs($ownerA)->post(route('sales.orders.delivery-notes.store', $orderA), $payload)->assertRedirect();
        $invoice = $orderA->invoices()->firstOrFail();
        $note = $orderA->deliveryNotes()->firstOrFail();
        $this->assertSame($organizationA->id, $invoice->organization_id);
        $this->assertSame($storeA->id, $invoice->store_id);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame($orderA->total_incl_tax, $invoice->total_incl_tax);
        $this->assertNotSame('FORGED SELLER', $invoice->seller_snapshot['legal_name']);
        $this->assertSame('v1', $invoice->template_version);
        $this->assertNull($note->delivery_note_number);
        $this->assertSame('draft', $note->status->value);
    }

    public function test_document_indexes_and_search_do_not_leak_other_store_rows_or_totals(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $storeA = $this->createStore($organization, $owner, 'Store A');
        $storeB = $this->createStore($organization, $owner, 'Store B');
        $orderB = $this->createDraftOrder($owner, $organization, $storeB);
        $this->addCustomLine($owner, $orderB, ['description' => 'SECRET-DOCUMENT']);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($owner, $orderB);
        $orderB = app(FulfillSalesOrderAction::class)->execute($owner, $orderB->fresh());
        $invoiceB = $this->createInvoice($owner, $orderB);
        $this->createDeliveryNote($owner, $orderB);
        $this->activate($owner, $organization, $storeA);
        $this->actingAs($owner)->get(route('invoices.index', ['search' => $invoiceB->id]))->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 0)->where('invoices.total', 0));
        $this->actingAs($owner)->get(route('delivery-notes.index', ['search' => $orderB->order_number]))->assertInertia(fn (Assert $page) => $page
            ->has('deliveryNotes.data', 0)->where('deliveryNotes.total', 0));
    }
}
