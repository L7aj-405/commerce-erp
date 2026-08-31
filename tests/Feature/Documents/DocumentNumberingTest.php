<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Services\DeliveryNoteNumberGenerator;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\DocumentTestCase;

class DocumentNumberingTest extends DocumentTestCase
{
    public function test_invoice_and_delivery_note_sequences_are_monotonic_and_independent(): void
    {
        [$owner, $organization, $store, $first] = $this->documentFixture(true);
        $second = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $second, ['description' => 'Second service']);
        $second = app(ConfirmSalesOrderAction::class)->execute($owner, $second);
        $second = app(FulfillSalesOrderAction::class)->execute($owner, $second->fresh());

        $this->assertSame('INV-000001', $this->issueInvoice($owner, $this->createInvoice($owner, $first))->invoice_number);
        $this->assertSame('DN-000001', $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $first))->delivery_note_number);
        $this->assertSame('INV-000002', $this->issueInvoice($owner, $this->createInvoice($owner, $second))->invoice_number);
        $this->assertSame('DN-000002', $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $second))->delivery_note_number);
    }

    public function test_sequence_generators_refuse_allocation_outside_a_transaction(): void
    {
        [$owner, $organization] = $this->documentFixture();

        $connection = DB::connection();
        $connection->rollBack();

        try {
            foreach ([app(InvoiceNumberGenerator::class), app(DeliveryNoteNumberGenerator::class)] as $generator) {
                try {
                    $generator->next($organization);
                    $this->fail('Expected transaction-only sequence allocation.');
                } catch (LogicException) {
                    $this->assertDatabaseCount($generator instanceof InvoiceNumberGenerator ? 'invoice_sequences' : 'delivery_note_sequences', 0);
                }
            }
        } finally {
            // Restore the transaction expected by RefreshDatabase teardown.
            $connection->beginTransaction();
        }
    }

    public function test_sequence_allocation_is_rolled_back_with_its_outer_transaction(): void
    {
        [$owner, $organization] = $this->documentFixture();

        try {
            DB::transaction(function () use ($organization) {
                $this->assertSame('INV-000001', app(InvoiceNumberGenerator::class)->next($organization));
                $this->assertSame('DN-000001', app(DeliveryNoteNumberGenerator::class)->next($organization));
                $this->assertDatabaseCount('invoice_sequences', 1);
                $this->assertDatabaseCount('delivery_note_sequences', 1);

                throw new RuntimeException('Force issuance rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force issuance rollback.', $exception->getMessage());
        }

        $this->assertDatabaseCount('invoice_sequences', 0);
        $this->assertDatabaseCount('delivery_note_sequences', 0);
    }
}
