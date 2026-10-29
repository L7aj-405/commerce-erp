<?php

namespace App\Console\Commands;

use App\Enums\SalesOrderPaymentStatus;
use App\Models\SalesOrder;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-derives the persisted payment_status of partially paid orders through
 * SalesOrderPaymentCalculator::recalculate() — e.g. after the currency-precision
 * rule (App\Support\Money) settled sub-centime residues. Touches no payment,
 * allocation or total; only the derived status column. Idempotent, chunked,
 * row-locked, safe to re-run.
 */
class RecalculateSalesOrderPaymentStatusCommand extends Command
{
    protected $signature = 'sales:recalculate-payment-status {--organization= : Limit to one organization id} {--dry-run : Report without saving}';

    protected $description = 'Re-derive the stored payment status of partially paid Sales Orders with the canonical payment calculator (derived column only; idempotent)';

    public function handle(SalesOrderPaymentCalculator $payments): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $failed = 0;

        SalesOrder::query()->withoutGlobalScopes()
            ->where('payment_status', SalesOrderPaymentStatus::PartiallyPaid->value)
            ->when($this->option('organization'), fn ($query, $id) => $query->where('organization_id', (int) $id))
            ->select(['id', 'organization_id', 'order_number'])
            ->chunkById(200, function ($orders) use ($payments, $dryRun, &$changed, &$failed) {
                foreach ($orders as $candidate) {
                    try {
                        DB::transaction(function () use ($candidate, $payments, $dryRun, &$changed) {
                            $order = SalesOrder::query()->withoutGlobalScopes()
                                ->where('organization_id', $candidate->organization_id)
                                ->whereKey($candidate->getKey())
                                ->lockForUpdate()
                                ->firstOrFail();
                            $status = $payments->summary($order)['status'];
                            if ($status === $order->payment_status->value) {
                                return;
                            }
                            $this->line("{$order->order_number} (organization {$order->organization_id}): {$order->payment_status->value} -> {$status}");
                            $changed++;
                            if (! $dryRun) {
                                $payments->recalculate($order);
                            }
                        });
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error("Order {$candidate->getKey()}: {$exception->getMessage()}");
                    }
                }
            });

        $this->info(($dryRun ? 'Dry run: ' : '')."{$changed} order(s) ".($dryRun ? 'would change' : 'updated').", {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
