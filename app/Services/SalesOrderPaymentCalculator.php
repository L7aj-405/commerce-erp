<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SalesOrderPaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\CustomerReturn;
use App\Support\Decimal;
use LogicException;

class SalesOrderPaymentCalculator
{
    public function collectedAmount(SalesOrder $order): string
    {
        $collected = '0.0000';
        $amounts = PaymentAllocation::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->whereHas('payment', fn ($query) => $query->where('status', PaymentStatus::Posted->value))
            ->pluck('amount');

        foreach ($amounts as $amount) {
            $collected = Decimal::add($collected, $amount);
        }

        return $collected;
    }

    public function refundedAmount(SalesOrder $order): string
    {
        $refunded = '0.0000';
        $amounts = PaymentRefund::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->where('status', 'posted')
            ->pluck('amount');

        foreach ($amounts as $amount) {
            $refunded = Decimal::add($refunded, $amount);
        }

        return $refunded;
    }

    /** Net money retained against the order: collections minus refunds. */
    public function paidAmount(SalesOrder $order): string
    {
        $collected = $this->collectedAmount($order);
        $refunded = $this->refundedAmount($order);
        if (Decimal::compare($refunded, $collected) > 0) {
            throw new LogicException('Posted refunds exceed posted payment allocations.');
        }

        return Decimal::subtract($collected, $refunded);
    }

    public function remainingAmount(SalesOrder $order): string
    {
        if ($order->status === SalesOrderStatus::Cancelled) {
            return '0.0000';
        }

        return $this->nonNegative(Decimal::subtract($this->commercialTotal($order), $this->paidAmount($order)));
    }

    /** @return array{paid: string, collected: string, refunded: string, net: string, remaining: string, status: string} */
    public function summary(SalesOrder $order): array
    {
        $collected = $this->collectedAmount($order);
        $refunded = $this->refundedAmount($order);
        if (Decimal::compare($refunded, $collected) > 0) {
            throw new LogicException('Posted refunds exceed posted payment allocations.');
        }
        $paid = Decimal::subtract($collected, $refunded);
        $commercialTotal = $this->commercialTotal($order);
        $remaining = $order->status === SalesOrderStatus::Cancelled
            ? '0.0000'
            : $this->nonNegative(Decimal::subtract($commercialTotal, $paid));

        return [
            'paid' => $paid,
            'collected' => $collected,
            'refunded' => $refunded,
            'net' => $paid,
            'remaining' => $remaining,
            'status' => $this->status($commercialTotal, $paid)->value,
        ];
    }

    public function recalculate(SalesOrder $order): SalesOrder
    {
        $paid = $this->paidAmount($order);
        $order->payment_status = $this->status($this->commercialTotal($order), $paid);
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

    private function commercialTotal(SalesOrder $order): string
    {
        $returned = CustomerReturn::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->id)->where('status', 'received')->pluck('total_incl_tax')
            ->reduce(fn (string $sum, $amount) => Decimal::add($sum, $amount), '0.0000');
        return $this->nonNegative(Decimal::subtract($order->total_incl_tax, $returned));
    }

    private function nonNegative(string $amount): string
    {
        return Decimal::compare($amount, '0.0000') < 0 ? '0.0000' : $amount;
    }
}
