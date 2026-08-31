<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SalesOrderPaymentStatus;
use App\Models\PaymentAllocation;
use App\Models\SalesOrder;
use App\Support\Decimal;
use LogicException;

class SalesOrderPaymentCalculator
{
    public function paidAmount(SalesOrder $order): string
    {
        $paid = '0.0000';
        $amounts = PaymentAllocation::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->whereHas('payment', fn ($query) => $query->where('status', PaymentStatus::Posted->value))
            ->pluck('amount');

        foreach ($amounts as $amount) {
            $paid = Decimal::add($paid, $amount);
        }

        return $paid;
    }

    public function remainingAmount(SalesOrder $order): string
    {
        return Decimal::subtract($order->total_incl_tax, $this->paidAmount($order));
    }

    /** @return array{paid: string, remaining: string, status: string} */
    public function summary(SalesOrder $order): array
    {
        $paid = $this->paidAmount($order);
        $remaining = Decimal::subtract($order->total_incl_tax, $paid);

        return [
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => $this->status($order->total_incl_tax, $paid)->value,
        ];
    }

    public function recalculate(SalesOrder $order): SalesOrder
    {
        $paid = $this->paidAmount($order);
        if (Decimal::compare($paid, $order->total_incl_tax) > 0) {
            throw new LogicException('Posted payment allocations exceed the Sales Order total.');
        }

        $order->payment_status = $this->status($order->total_incl_tax, $paid);
        $order->save();

        return $order;
    }

    private function status(string $total, string $paid): SalesOrderPaymentStatus
    {
        if (Decimal::compare($paid, '0.0000') === 0) {
            return SalesOrderPaymentStatus::Unpaid;
        }

        return Decimal::compare($paid, $total) < 0
            ? SalesOrderPaymentStatus::PartiallyPaid
            : SalesOrderPaymentStatus::Paid;
    }
}
