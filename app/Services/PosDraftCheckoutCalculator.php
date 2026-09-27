<?php

namespace App\Services;

use App\Enums\SalesOrderDiscountType;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative POS checkout financials.
 *
 * The draft's persisted line snapshots (HT / discount / tax) are the base. On top
 * of them the POS applies an optional order-level global discount (allocated
 * across taxable lines BEFORE tax) and an optional shipping fee. `breakdown()`
 * simulates exactly what `CreatePosSaleAction` will persist at confirmation, so
 * the preview total and the final charged total always agree.
 */
class PosDraftCheckoutCalculator
{
    public function __construct(private readonly SalesLineCalculator $lineCalculator) {}

    /** @return array<string, mixed> */
    public function summary(SalesOrder $order): array
    {
        $breakdown = $this->breakdown($order);

        return [
            'merchandise_total' => $order->total_incl_tax,
            'subtotal_excl_tax' => $breakdown['gross_excl_tax'],
            'line_discount_total' => $breakdown['item_discount_total'],
            'global_discount_type' => (string) ($order->pos_global_discount_type ?? 'none'),
            'global_discount_value' => Decimal::normalize((string) ($order->pos_global_discount_value ?? '0')),
            'global_discount_amount' => $breakdown['global_discount_amount'],
            'net_excl_tax' => $breakdown['net_excl_tax'],
            'tax_total' => $breakdown['tax_total'],
            'shipping_fee' => $breakdown['shipping_fee'],
            'total' => $breakdown['total_incl_tax'],
        ];
    }

    /**
     * The exact financial result the confirmation step will persist.
     *
     * @return array{gross_excl_tax:string, item_discount_total:string, global_discount_amount:string, net_excl_tax:string, tax_total:string, shipping_fee:string, total_incl_tax:string}
     */
    public function breakdown(SalesOrder $order): array
    {
        $order->loadMissing('lines');

        $gross = '0.0000';
        $itemDiscount = '0.0000';
        foreach ($order->lines as $line) {
            $gross = Decimal::add($gross, $line->subtotal_excl_tax);
            $itemDiscount = Decimal::add($itemDiscount, $line->discount_amount);
        }

        $globalDiscount = $this->globalDiscountAmount($order);

        $net = '0.0000';
        $tax = '0.0000';
        foreach ($this->distributedLineDiscounts($order) as $item) {
            /** @var SalesOrderLine $line */
            $line = $item['line'];
            $calculated = $this->lineCalculator->calculate(
                $line->quantity,
                $line->unit_price_excl_tax,
                $line->tax_rate,
                SalesOrderDiscountType::from($item['discount_type']),
                $item['discount_value'],
            );
            $net = Decimal::add($net, $calculated['taxable_amount']);
            $tax = Decimal::add($tax, $calculated['tax_amount']);
        }

        $shipping = Decimal::normalize((string) ($order->pos_shipping_fee ?? '0'));

        return [
            'gross_excl_tax' => $gross,
            'item_discount_total' => $itemDiscount,
            'global_discount_amount' => $globalDiscount,
            'net_excl_tax' => $net,
            'tax_total' => $tax,
            'shipping_fee' => $shipping,
            'total_incl_tax' => Decimal::add(Decimal::add($net, $tax), $shipping),
        ];
    }

    /** @return array<int, array{line: SalesOrderLine, discount_type: string, discount_value: string}> */
    public function distributedLineDiscounts(SalesOrder $order): array
    {
        $order->loadMissing('lines');
        $lines = $order->lines->values();
        if ($lines->isEmpty()) {
            return [];
        }

        $globalDiscount = $this->globalDiscountAmount($order);
        if (Decimal::compare($globalDiscount, '0.0000') === 0) {
            return $lines->map(fn (SalesOrderLine $line) => [
                'line' => $line,
                'discount_type' => $line->discount_type->value,
                'discount_value' => $line->discount_value,
            ])->all();
        }

        $remaining = $globalDiscount;
        $base = $this->netExclTaxBase($order);
        if (Decimal::compare($base, '0.0000') <= 0) {
            throw ValidationException::withMessages([
                'discount' => 'La remise globale nécessite au moins une ligne positive dans le panier.',
            ]);
        }

        $result = [];
        $lastIndex = $lines->count() - 1;

        foreach ($lines as $index => $line) {
            $maxAdditional = Decimal::subtract($line->subtotal_excl_tax, $line->discount_amount);
            // Exact decimal proportional share — $base is already verified > 0
            // above, so no float zero-guard is needed here.
            $allocated = $index === $lastIndex
                ? $remaining
                : $this->min(
                    $maxAdditional,
                    Decimal::multiply(Decimal::divide($line->taxable_amount, $base, 4), $globalDiscount),
                );

            if ($index !== $lastIndex && Decimal::compare($allocated, $remaining) > 0) {
                $allocated = $remaining;
            }

            $newDiscount = Decimal::add($line->discount_amount, $allocated);
            if (Decimal::compare($newDiscount, $line->subtotal_excl_tax) > 0) {
                $newDiscount = $line->subtotal_excl_tax;
            }

            $remaining = Decimal::subtract($remaining, Decimal::subtract($newDiscount, $line->discount_amount));

            $result[] = [
                'line' => $line,
                'discount_type' => SalesOrderDiscountType::Fixed->value,
                'discount_value' => $newDiscount,
            ];
        }

        // Reconciliation pass: each line's own subtotal is a hard ceiling on
        // how much of it can be discounted, and the proportional pass above
        // can round the earlier lines' shares down just enough that the last
        // line's ceiling can't absorb 100% of what's left (worst case: the
        // discount equals the full subtotal). Total capacity across every
        // line always equals `$base`, which callers cap the discount at
        // (see globalDiscountAmount()/bounded()), so walking backward and
        // topping up whichever line still has headroom always fully absorbs
        // the leftover — the allocations must sum to exactly $globalDiscount,
        // never 99.9999 / 100.0001.
        for ($i = count($result) - 1; $i >= 0 && Decimal::compare($remaining, '0.0000') > 0; $i--) {
            $headroom = Decimal::subtract($result[$i]['line']->subtotal_excl_tax, $result[$i]['discount_value']);
            if (Decimal::compare($headroom, '0.0000') <= 0) {
                continue;
            }
            $topUp = $this->min($headroom, $remaining);
            $result[$i]['discount_value'] = Decimal::add($result[$i]['discount_value'], $topUp);
            $remaining = Decimal::subtract($remaining, $topUp);
        }

        return $result;
    }

    /**
     * The order-level global discount, expressed as an absolute HT amount.
     * A percentage is taken off the net HT base (line subtotals minus per-line
     * discounts) so it behaves like the fixed amount and never over-discounts tax.
     */
    private function globalDiscountAmount(SalesOrder $order): string
    {
        $type = SalesOrderDiscountType::tryFrom((string) ($order->pos_global_discount_type ?? 'none')) ?? SalesOrderDiscountType::None;
        $value = Decimal::normalize((string) ($order->pos_global_discount_value ?? '0'));
        $base = $this->netExclTaxBase($order);

        return match ($type) {
            SalesOrderDiscountType::None => '0.0000',
            SalesOrderDiscountType::Fixed => $this->bounded($value, $base),
            SalesOrderDiscountType::Percentage => $this->bounded(Decimal::percentage($base, $value), $base),
        };
    }

    private function netExclTaxBase(SalesOrder $order): string
    {
        $order->loadMissing('lines');

        return $order->lines->reduce(
            fn (string $carry, SalesOrderLine $line) => Decimal::add($carry, $line->taxable_amount),
            '0.0000',
        );
    }

    private function bounded(string $value, string $max): string
    {
        if (Decimal::compare($value, '0.0000') < 0) {
            return '0.0000';
        }

        return Decimal::compare($value, $max) > 0 ? $max : $value;
    }

    private function min(string $left, string $right): string
    {
        return Decimal::compare($left, $right) <= 0 ? $left : $right;
    }
}
