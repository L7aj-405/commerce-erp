<?php

namespace App\Services\Pos;

use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SalesOrderDiscountType;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\PosDraftCheckoutCalculator;
use App\Services\SalesLineCalculator;
use App\Services\SalesOrderTotalsCalculator;
use App\Support\Decimal;

/**
 * Turns the cart-level POS terms (global discount, delivery fee) into real
 * Sales Order lines, so the order carries the exact amounts the cashier showed.
 * Applied when a cart leaves the cart stage: POS checkout, or conversion to a
 * Sales Order Draft. Idempotent (an unchanged line and the existing delivery
 * line are reused). Runs inside the caller's transaction.
 */
final class PosCartCommercialTerms
{
    /**
     * Tax treatment of the POS delivery fee.
     *
     * The fee is captured as a single tax-exclusive amount and added as a custom
     * order line taxed at this rate. It is currently 0% — the delivery fee is
     * entered and charged as-is, VAT-free (HT === TTC). Set a `tax_rate_id`-backed
     * rate here (and adjust `applyShippingLine`) to make delivery VAT-able.
     */
    private const SHIPPING_TAX_RATE_ID = null;

    public function __construct(
        private readonly SaveSalesOrderLineAction $saveLine,
        private readonly SalesLineCalculator $lineCalculator,
        private readonly SalesOrderTotalsCalculator $totals,
        private readonly PosDraftCheckoutCalculator $posSummary,
    ) {}

    public function apply(User $actor, SalesOrder $cart): void
    {
        $this->applyGlobalDiscount($cart);
        $this->applyShippingLine($actor, $cart);
    }

    private function applyGlobalDiscount(SalesOrder $order): void
    {
        $distribution = $this->posSummary->distributedLineDiscounts($order);
        if ($distribution === []) {
            return;
        }

        foreach ($distribution as $item) {
            /** @var SalesOrderLine $line */
            $line = $item['line'];
            if ($line->discount_type->value === $item['discount_type'] && Decimal::compare($line->discount_value, $item['discount_value']) === 0) {
                continue;
            }

            $calculated = $this->lineCalculator->calculate(
                $line->quantity,
                $line->unit_price_excl_tax,
                $line->tax_rate,
                SalesOrderDiscountType::Fixed,
                $item['discount_value'],
            );

            $line->discount_type = SalesOrderDiscountType::Fixed;
            foreach ($calculated as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();
        }

        $this->totals->recalculate($order->fresh());
    }

    private function applyShippingLine(User $actor, SalesOrder $order): void
    {
        $shipping = Decimal::normalize((string) ($order->pos_shipping_fee ?? '0'));
        if (Decimal::compare($shipping, '0.0000') <= 0) {
            return;
        }

        $existing = $order->lines()->where('line_type', 'custom')->where('reference', '__POS_SHIPPING__')->first();

        $this->saveLine->execute($actor, $order, [
            'line_type' => 'custom',
            'description' => 'Frais de livraison',
            'reference' => '__POS_SHIPPING__',
            'unit_label' => 'service',
            'quantity' => '1.0000',
            'unit_price_excl_tax' => $shipping,
            'tax_rate_id' => self::SHIPPING_TAX_RATE_ID,
            'discount_type' => SalesOrderDiscountType::None->value,
            'discount_value' => '0.0000',
        ], $existing);
    }
}
