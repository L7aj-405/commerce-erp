<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordSalesOrderPaymentsAction
{
    public function __construct(private readonly RecordPaymentAction $recordPayment) {}

    /** @param list<array<string, mixed>> $payments
     * @return Collection<int, Payment>
     */
    public function execute(User $actor, SalesOrder $order, array $payments, string $operationId): Collection
    {
        return DB::transaction(function () use ($actor, $order, $payments, $operationId) {
            $existingCount = Payment::query()
                ->where('organization_id', $order->organization_id)
                ->where('store_id', $order->store_id)
                ->where('client_operation_id', $operationId)
                ->count();
            if ($existingCount > 0 && $existingCount !== count($payments)) {
                throw ValidationException::withMessages([
                    'client_operation_id' => 'This Payment operation identifier was already used with a different number of entries.',
                ]);
            }

            return collect($payments)->values()->map(
                fn (array $payment, int $index) => $this->recordPayment->execute(
                    $actor,
                    $order,
                    $payment,
                    $operationId,
                    $index + 1,
                ),
            );
        }, 3);
    }
}
