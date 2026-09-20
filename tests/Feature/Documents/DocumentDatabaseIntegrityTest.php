<?php

namespace Tests\Feature\Documents;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceFamily;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Tests\Support\DocumentTestCase;

class DocumentDatabaseIntegrityTest extends DocumentTestCase
{
    public function test_document_models_are_fully_guarded(): void
    {
        foreach ([Invoice::class, InvoiceFamily::class, InvoiceLine::class, DeliveryNote::class, DeliveryNoteLine::class] as $model) {
            try {
                $model::query()->create(['organization_id' => 999, 'status' => 'issued']);
                $this->fail("Expected {$model} to reject mass assignment.");
            } catch (MassAssignmentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_invoice_and_delivery_note_order_foreign_keys_reject_cross_tenant_relationships(): void
    {
        [$ownerA, , , $orderA] = $this->documentFixture(true);
        [$ownerB, , , $orderB] = $this->documentFixture(true);
        $invoiceA = $this->createInvoice($ownerA, $orderA);
        $noteA = $this->createDeliveryNote($ownerA, $orderA);
        foreach ([$invoiceA, $noteA] as $document) {
            $attack = $document->replicate();
            $attack->sales_order_id = $orderB->getKey();
            $attack->created_at = null;
            $attack->updated_at = null;
            try {
                $attack->save();
                $this->fail('Expected cross-tenant document source rejection.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_document_line_composite_foreign_keys_reject_foreign_document_and_source_line(): void
    {
        [$ownerA, , , $orderA] = $this->documentFixture(true);
        [$ownerB, , , $orderB] = $this->documentFixture(true);
        $invoiceA = $this->createInvoice($ownerA, $orderA);
        $invoiceB = $this->createInvoice($ownerB, $orderB);
        $attack = $invoiceB->lines()->firstOrFail()->replicate();
        $attack->invoice_id = $invoiceA->getKey();
        $attack->created_at = null;
        $attack->updated_at = null;
        $this->expectException(QueryException::class);
        $attack->save();
    }

    public function test_duplicate_invoice_version_is_rejected_inside_a_family(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $duplicate = $issued->replicate();
        $duplicate->created_at = null;
        $duplicate->updated_at = null;
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_invoice_family_reference_cannot_cross_the_organization_boundary(): void
    {
        [$ownerA, , , $orderA] = $this->documentFixture();
        [$ownerB, , , $orderB] = $this->documentFixture();
        $invoiceA = $this->issueInvoice($ownerA, $this->createInvoice($ownerA, $orderA));
        $invoiceB = $this->issueInvoice($ownerB, $this->createInvoice($ownerB, $orderB));

        $attack = $invoiceA->replicate();
        $attack->invoice_family_id = $invoiceB->invoice_family_id;
        $attack->version = 2;
        $attack->created_at = null;
        $attack->updated_at = null;

        $this->expectException(QueryException::class);
        $attack->save();
    }

    public function test_document_money_and_quantity_are_exposed_at_four_decimal_precision(): void
    {
        [$owner, , , $order] = $this->documentFixture(true, total: '123.4567');
        $invoice = $this->createInvoice($owner, $order);
        $note = $this->createDeliveryNote($owner, $order);
        $this->assertSame('123.4567', $invoice->total_incl_tax);
        $this->assertSame('1.0000', $invoice->lines->first()->quantity);
        $this->assertSame('1.0000', $note->lines->first()->quantity);
    }
}
