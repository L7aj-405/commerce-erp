<?php

namespace App\Services;

use App\Enums\SalesOrderDiscountType;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class SalesLineCalculator
{
    /** @return array<string, string> */
    public function calculate(
        string $quantity,
        string $unitPrice,
        string $taxRate,
        SalesOrderDiscountType $discountType,
        string $discountValue,
        bool $wholeQuantity = true,
    ): array
    {
        $quantity = $wholeQuantity
            ? $this->positiveIntegerQuantity($quantity)
            : Decimal::positive($quantity, 'quantity');
        $unitPrice = Decimal::nonNegative($unitPrice, 'unit_price_excl_tax');
        $taxRate = Decimal::nonNegative($taxRate, 'tax_rate');
        if (Decimal::compare($taxRate, '100.0000') > 0) {
            throw ValidationException::withMessages(['tax_rate' => 'The tax rate may not exceed 100%.']);
        }
        $subtotal = Decimal::multiply($quantity, $unitPrice);
        $unitTax = Decimal::compare($taxRate, '0.0000') === 0 ? '0.0000' : Decimal::percentage($unitPrice, $taxRate);
        $unitPriceInclTax = Decimal::add($unitPrice, $unitTax);
        $discountValue = $discountType === SalesOrderDiscountType::None
            ? Decimal::normalize('0')
            : Decimal::nonNegative($discountValue, 'discount_value');
        $discountExclTax = match ($discountType) {
            SalesOrderDiscountType::None => Decimal::normalize('0'),
            SalesOrderDiscountType::Fixed => $discountValue,
            SalesOrderDiscountType::Percentage => $this->percentageDiscount($subtotal, $discountValue),
        };
        if (Decimal::compare($discountExclTax, $subtotal) > 0) {
            throw ValidationException::withMessages(['discount_value' => 'The discount may not exceed the line subtotal.']);
        }
        $taxable = Decimal::subtract($subtotal, $discountExclTax);
        $tax = Decimal::compare($taxRate, '0.0000') === 0 ? '0.0000' : Decimal::percentage($taxable, $taxRate);
        $totalInclTax = Decimal::add($taxable, $tax);

        return [
            'quantity' => $quantity,
            'unit_price_excl_tax' => $unitPrice,
            'unit_price_incl_tax' => $unitPriceInclTax,
            'tax_rate' => $taxRate,
            'discount_value' => $discountValue,
            'subtotal_excl_tax' => $subtotal,
            'discount_amount' => $discountExclTax,
            'taxable_amount' => $taxable,
            'tax_amount' => $tax,
            'total_incl_tax' => $totalInclTax,
        ];
    }

    private function positiveIntegerQuantity(string $quantity): string
    {
        $raw = trim($quantity);
        if (! preg_match('/^[1-9]\d*(?:\.0{1,4})?$/', $raw)) {
            throw ValidationException::withMessages(['quantity' => 'The quantity must be a positive whole number.']);
        }

        return Decimal::positive($raw, 'quantity');
    }

    private function percentageDiscount(string $subtotal, string $percentage): string
    {
        if (Decimal::compare($percentage, '100.0000') > 0) {
            throw ValidationException::withMessages(['discount_value' => 'A percentage discount may not exceed 100%.']);
        }

        return Decimal::percentage($subtotal, $percentage);
    }

}
