<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Support\Decimal;

class SalesOrderTotalsCalculator
{
    public function recalculate(SalesOrder $order): SalesOrder
    {
        $totals = ['subtotal_excl_tax' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total_incl_tax' => '0.0000'];
        foreach ($order->lines()->get() as $line) {
            $totals['subtotal_excl_tax'] = Decimal::add($totals['subtotal_excl_tax'], $line->subtotal_excl_tax);
            $totals['discount_total'] = Decimal::add($totals['discount_total'], $line->discount_amount);
            $totals['tax_total'] = Decimal::add($totals['tax_total'], $line->tax_amount);
            $totals['total_incl_tax'] = Decimal::add($totals['total_incl_tax'], $line->total_incl_tax);
        }
        foreach ($totals as $field => $value) {
            $order->{$field} = $value;
        }
        $order->save();

        return $order;
    }
}
