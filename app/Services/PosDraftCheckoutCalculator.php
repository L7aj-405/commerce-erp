<?php

namespace App\Services;

use App\Enums\SalesOrderDiscountType;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class PosDraftCheckoutCalculator
{
    /** @return array<string, mixed> */
    public function summary(SalesOrder $order): array
    {
        $order->loadMissing('lines');

        $lineDiscount = '0.0000';
        foreach ($order->lines as $line) {
            $lineDiscount = Decimal::add($lineDiscount, $line->discount_amount);
        }

        $baseTotal = $order->total_incl_tax;
        $globalDiscount = $this->globalDiscountAmount($order, $baseTotal);
        $shippingFee = Decimal::normalize((string) ($order->pos_shipping_fee ?? '0'));
        $grandTotal = Decimal::add(Decimal::subtract($baseTotal, $globalDiscount), $shippingFee);

        return [
            'merchandise_total' => $baseTotal,
            'line_discount_total' => $lineDiscount,
            'global_discount_type' => (string) ($order->pos_global_discount_type ?? 'none'),
            'global_discount_value' => Decimal::normalize((string) ($order->pos_global_discount_value ?? '0')),
            'global_discount_amount' => $globalDiscount,
            'shipping_fee' => $shippingFee,
            'total' => $grandTotal,
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

        $baseTotal = $order->total_incl_tax;
        $globalDiscount = $this->globalDiscountAmount($order, $baseTotal);
        if (Decimal::compare($globalDiscount, '0.0000') === 0) {
            return $lines->map(fn (SalesOrderLine $line) => [
                'line' => $line,
                'discount_type' => $line->discount_type->value,
                'discount_value' => $line->discount_value,
            ])->all();
        }

        $remaining = $globalDiscount;
        $base = $lines->reduce(fn (string $carry, SalesOrderLine $line) => Decimal::add($carry, $line->taxable_amount), '0.0000');
        if (Decimal::compare($base, '0.0000') <= 0) {
            throw ValidationException::withMessages([
                'discount' => 'La remise globale nécessite au moins une ligne positive dans le panier.',
            ]);
        }

        $result = [];
        $lastIndex = $lines->count() - 1;

        foreach ($lines as $index => $line) {
            $maxAdditional = Decimal::subtract($line->subtotal_excl_tax, $line->discount_amount);
            $allocated = $index === $lastIndex
                ? $remaining
                : $this->min(
                    $maxAdditional,
                    Decimal::normalize((string) round(((float) $line->taxable_amount / max((float) $base, 0.0001)) * (float) $globalDiscount, 4)),
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

        return $result;
    }

    private function globalDiscountAmount(SalesOrder $order, string $baseTotal): string
    {
        $type = SalesOrderDiscountType::tryFrom((string) ($order->pos_global_discount_type ?? 'none')) ?? SalesOrderDiscountType::None;
        $value = Decimal::normalize((string) ($order->pos_global_discount_value ?? '0'));

        return match ($type) {
            SalesOrderDiscountType::None => '0.0000',
            SalesOrderDiscountType::Fixed => $this->bounded($value, $baseTotal),
            SalesOrderDiscountType::Percentage => $this->bounded(Decimal::percentage($baseTotal, $value), $baseTotal),
        };
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
