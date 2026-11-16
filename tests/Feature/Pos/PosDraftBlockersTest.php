<?php

namespace Tests\Feature\Pos;

use App\Models\SalesOrder;
use App\Services\Pos\PosDraftLifecycle;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\DocumentTestCase;

/**
 * An order with ANY consequential history (invoice, payment, delivery, fulfillment, return,
 * exchange, refund, procurement, ...) is never deletable by the POS draft cleanup.
 */
class PosDraftBlockersTest extends DocumentTestCase
{
    private const RELATIONS = ['paymentAllocations', 'paymentRefunds', 'expectedCollections', 'commissionEntries', 'customerReturns', 'customerExchanges', 'invoices', 'revisions', 'addenda', 'deliveryNotes', 'transferRequests', 'procurements', 'fulfillments'];

    /** Every history relation, one at a time, must block (covers fulfillment / returns / procurement fixtures that are too heavy to build). */
    public function test_each_history_relation_alone_blocks_the_discard(): void
    {
        $expected = [
            'paymentAllocations' => 'payments',
            'paymentRefunds' => 'refunds',
            'expectedCollections' => 'finance_expectations',
            'commissionEntries' => 'commissions',
            'customerReturns' => 'returns',
            'customerExchanges' => 'exchanges',
            'invoices' => 'invoices',
            'revisions' => 'revisions',
            'addenda' => 'addenda',
            'deliveryNotes' => 'delivery_notes',
            'transferRequests' => 'transfers',
            'procurements' => 'procurements',
            'fulfillments' => 'fulfillments',
        ];
        $lifecycle = app(PosDraftLifecycle::class);

        foreach ($expected as $relation => $code) {
            $order = $this->orderWithHistoryOnly($relation, array_keys($expected));
            $this->assertSame([$code], $lifecycle->blockers($order), "Relation {$relation} must block with code {$code}.");
        }

        $clean = $this->orderWithHistoryOnly(null, array_keys($expected));
        $this->assertSame([], $lifecycle->blockers($clean), 'A draft without history is not blocked.');
    }

    public function test_a_supplier_procurement_derived_from_the_cart_blocks(): void
    {
        $order = $this->orderWithHistoryOnly(null, self::RELATIONS, derivedProcurement: true);

        $this->assertSame(['procurements'], app(PosDraftLifecycle::class)->blockers($order));
    }

    /** Real rows: invoice + payment + delivery note on an order that is then (re)labelled as an old POS draft. */
    public function test_cleanup_never_deletes_an_old_pos_order_that_has_real_invoice_payment_and_delivery_history(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '100.0000');
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '40.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->createDeliveryNote($owner, $order);

        $blockers = app(PosDraftLifecycle::class)->blockers($order->fresh());
        $this->assertContains('payments', $blockers);
        $this->assertContains('invoices', $blockers);
        $this->assertContains('delivery_notes', $blockers);

        // Worst case: the row looks like an abandoned POS draft. The cleanup must still refuse it.
        DB::table('sales_orders')->where('id', $order->id)->update([
            'source' => 'pos', 'status' => 'draft', 'pos_converted_at' => null,
            'pos_last_activity_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
        ]);

        Artisan::call('pos:cleanup-drafts', ['--execute' => true]);
        $this->assertStringContainsString('Blocked', Artisan::output());
        $this->assertNotNull(SalesOrder::query()->find($order->id));
        $result = app(PosDraftLifecycle::class)->discard(null, SalesOrder::query()->findOrFail($order->id), PosDraftLifecycle::REASON_EXPIRED);
        $this->assertSame(PosDraftLifecycle::OUTCOME_BLOCKED, $result['outcome']);
        $manual = app(PosDraftLifecycle::class)->discard($owner, SalesOrder::query()->findOrFail($order->id), PosDraftLifecycle::REASON_MANUAL, true);
        $this->assertSame(PosDraftLifecycle::OUTCOME_BLOCKED, $manual['outcome']);
        $this->assertNotNull(SalesOrder::query()->find($order->id));
    }

    /**
     * @param  list<string>  $all
     */
    private function orderWithHistoryOnly(?string $present, array $all, bool $derivedProcurement = false): SalesOrder
    {
        $order = Mockery::mock(SalesOrder::class)->makePartial();
        foreach ($all as $relation) {
            $order->shouldReceive($relation)->andReturn($this->relation($relation === $present));
        }
        $order->shouldReceive('supplierAvailabilityRequests')->andReturn($this->relation($derivedProcurement));
        $order->shouldReceive('lines')->andReturn((new SalesOrder)->lines());

        return $order;
    }

    private function relation(bool $exists): HasMany
    {
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('whereHas')->andReturnSelf();
        $relation->shouldReceive('exists')->andReturn($exists);

        return $relation;
    }
}
