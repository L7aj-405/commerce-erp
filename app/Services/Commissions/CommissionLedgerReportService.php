<?php

namespace App\Services\Commissions;

use App\Models\CommissionEntry;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;

final class CommissionLedgerReportService
{
    /** @return array<string,string|int> */
    public function summary(
        Organization $organization,
        string $from,
        string $to,
        ?Store $store = null,
        ?int $salespersonId = null
    ): array {
        $rows = CommissionEntry::query()
            ->where('organization_id', $organization->getKey())
            ->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when(
                $store,
                fn ($query) => $query->where('store_id', $store->getKey())
            )
            ->when(
                $salespersonId !== null,
                fn ($query) => $query->where('salesperson_id', $salespersonId)
            )
            ->get([
                'entry_type',
                'status',
                'commission_amount',
            ]);

        $result = [
            'gross_earned' => '0.0000',
            'reversals' => '0.0000',
            'net' => '0.0000',

            'pending' => '0.0000',
            'approved' => '0.0000',
            'paid' => '0.0000',

            'no_commission_count' => 0,
            'entry_count' => $rows->count(),
        ];

        foreach ($rows as $entry) {
            /*
             * Net commission amount.
             *
             * no_commission entries have commission_amount = 0,
             * so they remain part of the ledger/history without
             * affecting monetary totals.
             */
            $result['net'] = Decimal::add(
                $result['net'],
                $entry->commission_amount
            );

            /*
             * no_commission is historical/non-payable.
             * Count it separately and never put it into
             * pending / approved / paid monetary buckets.
             */
            if ($entry->status === CommissionEntry::STATUS_NO_COMMISSION) {
                $result['no_commission_count']++;
            } elseif (in_array($entry->status, [
                CommissionEntry::STATUS_PENDING,
                CommissionEntry::STATUS_APPROVED,
                CommissionEntry::STATUS_PAID,
            ], true)) {
                $result[$entry->status] = Decimal::add(
                    $result[$entry->status],
                    $entry->commission_amount
                );
            }

            /*
             * Gross earned / reversals keep their existing semantics.
             */
            if ($entry->entry_type === CommissionEntry::TYPE_SALE) {
                $result['gross_earned'] = Decimal::add(
                    $result['gross_earned'],
                    $entry->commission_amount
                );
            } else {
                $result['reversals'] = Decimal::add(
                    $result['reversals'],
                    $entry->commission_amount
                );
            }
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function bySalesperson(
        Organization $organization,
        string $from,
        string $to,
        ?Store $store = null
    ): array {
        $rows = CommissionEntry::query()
            ->where('organization_id', $organization->getKey())
            ->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when(
                $store,
                fn ($query) => $query->where('store_id', $store->getKey())
            )
            ->select([
                'salesperson_id',
                'salesperson_name_snapshot',
            ])
            ->selectRaw(
                "SUM(CASE WHEN entry_type = 'sale' THEN commission_amount ELSE 0 END) as gross_earned"
            )
            ->selectRaw(
                "SUM(CASE WHEN entry_type <> 'sale' THEN commission_amount ELSE 0 END) as reversals"
            )
            ->selectRaw(
                'SUM(commission_amount) as net'
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END) as pending"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'approved' THEN commission_amount ELSE 0 END) as approved"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END) as paid"
            )
            ->groupBy(
                'salesperson_id',
                'salesperson_name_snapshot'
            )
            ->orderBy('salesperson_name_snapshot')
            ->get();

        return $rows->map(fn ($row) => [
            'salesperson_id' => $row->salesperson_id === null
                ? null
                : (int) $row->salesperson_id,

            'salesperson_name' => $row->salesperson_name_snapshot,

            'gross_earned' => Decimal::normalize(
                (string) $row->gross_earned
            ),

            'reversals' => Decimal::normalize(
                (string) $row->reversals
            ),

            'net' => Decimal::normalize(
                (string) $row->net
            ),

            'pending' => Decimal::normalize(
                (string) $row->pending
            ),

            'approved' => Decimal::normalize(
                (string) $row->approved
            ),

            'paid' => Decimal::normalize(
                (string) $row->paid
            ),
        ])->all();
    }
}