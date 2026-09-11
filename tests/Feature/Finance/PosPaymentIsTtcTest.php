<?php

namespace Tests\Feature\Finance;

use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\SalesOrderPaymentCalculator;
use Tests\Support\PosTestCase;

class PosPaymentIsTtcTest extends PosTestCase
{
    public function test_split_payment_settles_against_the_ttc_total_not_the_ht_total(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $cash = $this->createPosAccount($organization, 'cash', 'POS-CASH', 'POS Cash');
        $card = $this->createPosAccount($organization, 'card_clearing', 'POS-TPE', 'POS TPE');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'TTC Product', 'TTC-1', [
            'default_sale_price' => '750.0000', // HT 750 + 20% = 900 TTC
            'tax_rate_id' => $tax->getKey(),
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '1.0000']);

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posDraftCheckoutPayload($draft, [
            $this->posPayment($cash, '500.0000', ['cash_received' => '500.0000']),
            $this->posPayment($card, '400.0000', ['method' => 'card', 'cash_received' => null, 'reference' => 'TPE-9']),
        ]))->assertRedirect(route('pos.index'));

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('750.0000', $order->subtotal_excl_tax);
        $this->assertSame('150.0000', $order->tax_total);
        $this->assertSame('900.0000', $order->total_incl_tax);
        $this->assertSame('paid', $order->payment_status->value);

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['method' => 'cash', 'amount' => 500]);
        $this->assertDatabaseHas('payments', ['method' => 'card', 'amount' => 400, 'reference' => 'TPE-9']);
        // Remaining is measured against TTC, so a fully split TTC payment closes the balance.
        $this->assertSame('0.0000', app(SalesOrderPaymentCalculator::class)->remainingAmount($order->fresh()));
    }
}
