<?php

namespace Tests\Feature\Returns;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Models\CreditNote;
use App\Models\InventoryBalance;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PosTestCase;

/** Phase 1 Step 7 (F-05 / S-06): credit-note totals and stock quantities cannot go negative in the database. */
class StockAndCreditNoteCheckConstraintsTest extends PosTestCase
{
    public function test_stock_balance_quantities_cannot_be_negative_but_zero_is_allowed(): void
    {
        [, , $balance] = $this->soldOrder();

        $this->assertRejected(fn () => DB::table('inventory_balances')->where('id', $balance->id)->update(['on_hand' => '-0.0001']));
        $this->assertRejected(fn () => DB::table('inventory_balances')->where('id', $balance->id)->update(['reserved' => '-1.0000']));

        DB::table('inventory_balances')->where('id', $balance->id)->update(['on_hand' => '0.0000', 'reserved' => '0.0000']);
        $this->assertSame('0.0000', (string) $balance->fresh()->on_hand);
    }

    public function test_credit_note_total_cannot_be_negative_but_zero_is_allowed(): void
    {
        [$owner, $order] = $this->soldOrder();
        app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
        $return = app(CreateCustomerReturnAction::class)->execute($owner, $order->fresh(), [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => '1']], 'Retour client', 'restock', (string) Str::uuid());
        $note = CreditNote::query()->where('customer_return_id', $return->id)->sole();

        $this->assertRejected(fn () => DB::table('credit_notes')->where('id', $note->id)->update(['total_incl_tax' => '-1.0000']));

        DB::table('credit_notes')->where('id', $note->id)->update(['total_incl_tax' => '0.0000']);
        $this->assertSame('0.0000', (string) $note->fresh()->total_incl_tax);
    }

    /** @return array{User, SalesOrder, InventoryBalance} */
    private function soldOrder(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $settings = $organization->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $organization->settings = $settings;
        $organization->save();
        $variant = $this->createProduct($organization, 'Check Product', 'CHK-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '2.0000');
        $payload = $this->posPayload($warehouse, [$this->posCatalogLine($variant, ['quantity' => '2.0000'])]);
        $this->actingAs($owner)->post(route('pos.sales.store'), $payload)->assertRedirect();
        $order = SalesOrder::query()->where('organization_id', $organization->id)->sole();
        $balance = InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->firstOrFail();

        return [$owner, $order, $balance];
    }

    private function assertRejected(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Expected the database CHECK constraint to reject the write.');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/chk_|check constraint/i', $e->getMessage());
        }
    }
}
