<?php

namespace Tests\Feature\Documents;

use App\Models\InventoryMovement;
use Tests\Support\DocumentTestCase;

class DocumentPdfTest extends DocumentTestCase
{
    public function test_issued_invoice_and_delivery_note_have_inline_and_downloadable_pdfs(): void
    {
        [$owner, , , $order] = $this->documentFixture(true);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order));
        $movementCount = InventoryMovement::query()->count();
        $invoiceTotal = $invoice->total_incl_tax;
        $invoiceLineCount = $invoice->lines()->count();

        foreach ([
            [route('invoices.pdf', $invoice), 'inline', 'Facture-'.$invoice->invoice_number.'.pdf'],
            [route('invoices.download', $invoice), 'attachment', 'Facture-'.$invoice->invoice_number.'.pdf'],
            [route('delivery-notes.pdf', $note), 'inline', 'Bon-de-Livraison-'.$note->delivery_note_number.'.pdf'],
            [route('delivery-notes.download', $note), 'attachment', 'Bon-de-Livraison-'.$note->delivery_note_number.'.pdf'],
        ] as [$url, $disposition, $filename]) {
            $response = $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith($disposition.'; filename="'.$filename.'"', $response->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }
        $this->assertSame('issued', $invoice->fresh()->status->value);
        $this->assertSame($invoiceTotal, $invoice->fresh()->total_incl_tax);
        $this->assertSame($invoiceLineCount, $invoice->lines()->count());
        $this->assertSame('issued', $note->fresh()->status->value);
        $this->assertSame($movementCount, InventoryMovement::query()->count());
    }

    public function test_draft_can_be_print_previewed_but_has_no_official_pdf(): void
    {
        [$owner, , , $order] = $this->documentFixture(true);
        $invoice = $this->createInvoice($owner, $order);
        $note = $this->createDeliveryNote($owner, $order);

        $this->actingAs($owner)->get(route('invoices.print', $invoice))->assertOk()->assertSee('BROUILLON');
        $this->actingAs($owner)->getJson(route('invoices.pdf', $invoice))->assertUnprocessable();
        $this->actingAs($owner)->get(route('delivery-notes.print', $note))->assertOk()->assertSee('BROUILLON');
        $this->actingAs($owner)->getJson(route('delivery-notes.pdf', $note))->assertUnprocessable();
        $this->assertSame('draft', $invoice->fresh()->status->value);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame('draft', $note->fresh()->status->value);
        $this->assertNull($note->delivery_note_number);
    }
}
