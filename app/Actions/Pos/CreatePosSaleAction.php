<?php

namespace App\Actions\Pos;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderPaymentStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePosSaleAction
{
    public function __construct(
        private readonly CreateSalesOrderAction $createOrder,
        private readonly SaveSalesOrderLineAction $saveLine,
        private readonly ConfirmSalesOrderAction $confirmOrder,
        private readonly FulfillSalesOrderAction $fulfillOrder,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, Store $store, array $data): SalesOrder
    {
        try {
            return DB::transaction(function () use ($actor, $organization, $store, $data) {
                $existing = $this->findExisting($organization, $store, $data['client_operation_id'], true);

                if ($existing) {
                    return $this->completed($existing);
                }

                $order = $this->createOrder->execute(
                    $actor,
                    $organization,
                    $store,
                    [
                        'customer_id' => $data['customer_id'] ?? null,
                        'sale_date' => now()->toDateString(),
                        'currency_code' => config('platform.currency_code', 'MAD'),
                        'notes' => null,
                    ],
                    SalesOrderSource::Pos,
                    $data['client_operation_id'],
                );

                foreach ($this->normalizedLines($data['lines']) as $line) {
                    if ($line['line_type'] === 'catalog') {
                        $line['warehouse_id'] = $data['warehouse_id'];
                    }

                    $this->saveLine->execute($actor, $order, $line);
                }

                $order = $this->confirmOrder->execute($actor, $order);

                return $this->fulfillOrder->execute($actor, $order->fresh())
                    ->load(['store:id,name,code', 'customer:id,display_name', 'lines']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findExisting($organization, $store, $data['client_operation_id']);

            if (! $existing) {
                throw $exception;
            }

            return $this->completed($existing);
        }
    }

    /** @param list<array<string, mixed>> $lines
     *  @return list<array<string, mixed>>
     */
    private function normalizedLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            if ($line['line_type'] !== 'catalog') {
                $normalized[] = $line;

                continue;
            }

            $key = implode(':', [
                $line['product_variant_id'],
                $line['unit_price_excl_tax'] ?? 'default',
                $line['discount_type'] ?? 'none',
                $line['discount_value'] ?? '0',
            ]);

            if (isset($normalized[$key])) {
                $normalized[$key]['quantity'] = Decimal::add(
                    (string) $normalized[$key]['quantity'],
                    (string) $line['quantity'],
                );

                continue;
            }

            $normalized[$key] = $line;
        }

        return array_values($normalized);
    }

    private function findExisting(Organization $organization, Store $store, string $operationId, bool $lock = false): ?SalesOrder
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->where('client_operation_id', $operationId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    private function completed(SalesOrder $order): SalesOrder
    {
        if (
            $order->source !== SalesOrderSource::Pos
            || $order->status !== SalesOrderStatus::Confirmed
            || $order->fulfillment_status !== SalesOrderFulfillmentStatus::Fulfilled
            || $order->payment_status !== SalesOrderPaymentStatus::Unpaid
        ) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'This POS operation exists but is not a completed unpaid sale.',
            ]);
        }

        return $order->load(['store:id,name,code', 'customer:id,display_name', 'lines']);
    }
}
