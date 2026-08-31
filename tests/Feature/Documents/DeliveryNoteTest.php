<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CancelDeliveryNoteDraftAction;
use App\Actions\Documents\UpdateDeliveryNoteDraftAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\InventoryBalance;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

class DeliveryNoteTest extends DocumentTestCase
{
    public function test_full_note_from_fulfilled_order_snapshots_quantities_without_inventory_mutation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization)->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $order = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $before = InventoryBalance::query()->firstOrFail()->only(['on_hand', 'reserved', 'available']);
        $note = $this->createDeliveryNote($owner, $order, ['delivery_date' => '2026-06-03']);
        $this->assertSame('2.0000', $note->lines->first()->quantity);
        $this->assertSame($before, InventoryBalance::query()->firstOrFail()->only(array_keys($before)));
        $this->issueDeliveryNote($owner, $note);
        $this->assertSame($before, InventoryBalance::query()->firstOrFail()->only(array_keys($before)));
        $product = $variant->product;
        $product->name = 'Renamed after delivery';
        $product->save();
        $variant->label = 'Changed variant';
        $variant->save();
        $this->assertSame('Product', $note->lines()->firstOrFail()->product_name);
        $this->assertNotSame('Changed variant', $note->lines()->firstOrFail()->variant_name);
    }

    public function test_unfulfilled_order_is_rejected(): void
    {
        [$owner, , , $order] = $this->documentFixture(false);
        $this->expectException(ValidationException::class);
        $this->createDeliveryNote($owner, $order);
    }

    public function test_number_is_assigned_on_issue_and_issued_note_is_immutable(): void
    {
        [$owner, , , $order] = $this->documentFixture(true);
        $draft = $this->createDeliveryNote($owner, $order);
        $this->assertNull($draft->delivery_note_number);
        $issued = $this->issueDeliveryNote($owner, $draft);
        $this->assertSame('DN-000001', $issued->delivery_note_number);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery_note.issued', 'auditable_id' => $issued->id]);
        $this->expectException(ValidationException::class);
        app(UpdateDeliveryNoteDraftAction::class)->execute($owner, $issued, [
            'delivery_date' => now()->toDateString(), 'recipient_name' => 'Attack', 'recipient_company' => null,
            'recipient_phone' => null, 'delivery_address' => null, 'notes' => null,
        ]);
    }

    public function test_duplicate_full_delivery_document_is_rejected(): void
    {
        [$owner, , , $order] = $this->documentFixture(true);
        $this->createDeliveryNote($owner, $order);
        $this->expectException(ValidationException::class);
        $this->createDeliveryNote($owner, $order);
    }

    public function test_issue_rejects_tampered_delivery_quantities_without_allocating_a_number(): void
    {
        [$owner, , , $order] = $this->documentFixture(true);
        $draft = $this->createDeliveryNote($owner, $order);
        $line = $draft->lines()->firstOrFail();
        $line->quantity = '999.0000';
        $line->save();
        try {
            $this->issueDeliveryNote($owner, $draft);
            $this->fail('Expected over-delivery snapshot rejection.');
        } catch (ValidationException) {
            $this->assertNull($draft->fresh()->delivery_note_number);
            $this->assertDatabaseCount('delivery_note_sequences', 0);
        }
    }

    public function test_recipient_snapshot_and_delivery_date_remain_independent_and_preserved(): void
    {
        [$owner, , , $order, $customer] = $this->documentFixture(true);
        $note = $this->createDeliveryNote($owner, $order, ['delivery_date' => '2026-06-04']);
        $note = app(UpdateDeliveryNoteDraftAction::class)->execute($owner, $note, [
            'delivery_date' => '2026-06-04', 'recipient_name' => 'Warehouse Recipient', 'recipient_company' => 'Receiver SARL',
            'recipient_phone' => '0622222222', 'delivery_address' => 'Dock 4', 'notes' => null,
        ]);
        $issued = $this->issueDeliveryNote($owner, $note);
        $customer->display_name = 'Changed';
        $customer->save();
        $this->assertSame('2026-06-04', $issued->delivery_date->toDateString());
        $this->assertSame('2026-05-29', $order->sale_date->toDateString());
        $this->assertSame('Warehouse Recipient', $issued->fresh()->recipient_name);
        $this->assertSame('Dock 4', $issued->fresh()->delivery_address);
    }

    public function test_draft_delivery_note_can_be_cancelled_but_issued_note_cannot(): void
    {
        [$owner, , , $order] = $this->documentFixture(true);
        $cancelled = app(CancelDeliveryNoteDraftAction::class)->execute($owner, $this->createDeliveryNote($owner, $order), 'Wrong recipient');
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery_note.draft_cancelled', 'auditable_id' => $cancelled->id]);
        $replacement = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order));
        $this->expectException(ValidationException::class);
        app(CancelDeliveryNoteDraftAction::class)->execute($owner, $replacement, 'Attack');
    }
}
