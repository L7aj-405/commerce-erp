<?php

namespace App\Services;

use App\Support\Decimal;

final class SaleLineMarginCalculator
{
    /**
     * @return array{purchase_price_snapshot: ?string, cost_total_snapshot: ?string, margin_amount_snapshot: ?string, margin_rate_snapshot: ?string, cost_status: string}
     */
    public function calculate(string $netRevenueExclTax, string $quantity, ?string $unitPurchasePrice): array
    {
        if ($unitPurchasePrice === null) {
            return $this->unavailable('missing');
        }

        $unitPurchasePrice = Decimal::normalize($unitPurchasePrice);
        $costTotal = Decimal::multiply($unitPurchasePrice, $quantity);
        $marginAmount = Decimal::subtract($netRevenueExclTax, $costTotal);
        $marginRate = Decimal::compare($netRevenueExclTax, '0') === 0
            ? null
            : Decimal::divide(Decimal::multiply($marginAmount, '100'), $netRevenueExclTax);

        return [
            'purchase_price_snapshot' => $unitPurchasePrice,
            'cost_total_snapshot' => $costTotal,
            'margin_amount_snapshot' => $marginAmount,
            'margin_rate_snapshot' => $marginRate,
            'cost_status' => 'available',
        ];
    }

    /**
     * @return array{purchase_price_snapshot: null, cost_total_snapshot: null, margin_amount_snapshot: null, margin_rate_snapshot: null, cost_status: string}
     */
    public function unavailable(string $status = 'unavailable'): array
    {
        return [
            'purchase_price_snapshot' => null,
            'cost_total_snapshot' => null,
            'margin_amount_snapshot' => null,
            'margin_rate_snapshot' => null,
            'cost_status' => $status,
        ];
    }
}
