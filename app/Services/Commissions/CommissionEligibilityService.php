<?php

namespace App\Services\Commissions;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;

final class CommissionEligibilityService
{
    public function __construct(private readonly CommissionRuleResolver $resolver) {}

    /** @return array{status:string,resolution:?array} */
    public function evaluate(Organization $organization, SalesOrder $order, SalesOrderLine $line): array
    {
        if ($order->salesperson_id === null || blank($order->salesperson_name_snapshot)) {
            return ['status' => 'missing_salesperson', 'resolution' => null];
        }
        if ($line->cost_status === null || $line->cost_status === 'missing') {
            return ['status' => 'missing_cost', 'resolution' => null];
        }
        if ($line->cost_status !== 'available' || $line->margin_amount_snapshot === null || $line->margin_rate_snapshot === null) {
            return ['status' => 'margin_unavailable', 'resolution' => null];
        }

        $resolution = $this->resolver->resolve($organization, $order->sale_date, $line->margin_rate_snapshot);

        return [
            'status' => $resolution['status'],
            'resolution' => $resolution['status'] === 'resolved' ? $resolution : null,
        ];
    }
}
