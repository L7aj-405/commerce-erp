<?php

namespace App\Services\Mcp\Procurement;

use App\Actions\Procurement\ReceiveProcurementAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WarehouseStatus;
use App\Models\ProcurementReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\Warehouse;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Supplier goods receipts over MCP. The receipt is always recorded by
 * ReceiveProcurementAction (one inbound movement, the goods reserved at once for
 * the linked Sales Order line, no over-receipt, partial receipts, purchase order
 * and requirement statuses, procurement.receive + store, procurement.* /
 * purchase_order.* audit). The preview runs that same action in a rolled-back
 * transaction. A receipt never confirms the Sales Order.
 */
final class McpProcurementReceiptService
{
    /** Idempotency target type (mcp_idempotency_records.target_type is varchar(32)). */
    public const TARGET_TYPE = 'procurement_receipt';

    public const FIELDS = ['purchase_order_id', 'purchase_order_line_id', 'quantity_received', 'warehouse_id', 'received_date', 'supplier_delivery_reference', 'note'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly PurchaseOrderReadService $purchaseOrders,
        private readonly ReceiveProcurementAction $receive,
        private readonly McpPartyPreviews $previews,
        private readonly McpDryRun $dryRun,
    ) {}

    /**
     * Canonical candidate: known fields, decimal quantity, today (organization
     * date) when no date, and the purchase order line resolved when the order
     * has exactly one line still awaiting goods.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        if (array_diff(array_keys($data), self::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        $payload = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $payload += ['purchase_order_line_id' => null, 'received_date' => null, 'supplier_delivery_reference' => null, 'note' => null];
        Validator::make($payload, [
            'purchase_order_id' => ['required', 'integer', 'min:1'],
            'purchase_order_line_id' => ['nullable', 'integer', 'min:1'],
            'quantity_received' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'received_date' => ['nullable', 'date_format:Y-m-d'],
            'supplier_delivery_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $payload['purchase_order_id'] = (int) $payload['purchase_order_id'];
        $payload['warehouse_id'] = (int) $payload['warehouse_id'];
        $payload['quantity_received'] = Decimal::normalize((string) $payload['quantity_received']);
        $payload['received_date'] ??= BusinessTime::today($this->context->organization());
        if ($payload['purchase_order_line_id'] === null) {
            $po = $this->purchaseOrders->find($this->context->organization(), $payload['purchase_order_id']);
            $open = $po->lines->filter(fn (PurchaseOrderLine $line) => Decimal::compare((string) $line->quantity, (string) $line->received_quantity) > 0);
            if ($open->count() !== 1) {
                throw ValidationException::withMessages(['purchase_order_line_id' => $open->isEmpty()
                    ? 'Nothing is left to receive on this purchase order.'
                    : 'This purchase order has several lines awaiting goods: give purchase_order_line_id (see get_purchase_order).']);
            }
            $payload['purchase_order_line_id'] = (int) $open->first()->getKey();
        }
        $payload['purchase_order_line_id'] = (int) $payload['purchase_order_line_id'];
        ksort($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $result = $this->dryRun->run(function () use ($payload) {
            [$po, $line] = $this->locate($payload, lock: true);
            $requirement = SalesOrderProcurement::query()->where('organization_id', $po->organization_id)->whereKey($line->sales_order_procurement_id)->firstOrFail();
            $state = $this->stateHash($po, $line, $requirement, $payload['warehouse_id']);
            $before = $this->figures($po, $line, $requirement);
            $issues = $this->precheck($po, $line, $payload);
            $after = null;
            if ($issues === []) {
                try {
                    $this->receive->execute($this->context->user(), $requirement, $this->warehouse($payload['warehouse_id']) ?? new Warehouse, $this->actionData($payload, $requirement));
                    $po->refresh()->load('lines');
                    $after = [
                        ...$this->figures($po, $line->fresh(), $requirement->fresh()),
                        'sales_order_procurement' => $this->purchaseOrders->salesOrderProcurement(SalesOrder::query()->findOrFail($line->sales_order_id)),
                    ];
                } catch (ValidationException $exception) {
                    $issues = $this->issues($exception);
                } catch (HttpExceptionInterface $exception) {
                    $issues[] = $exception->getStatusCode() === 404
                        ? ['path' => 'warehouse_id', 'code' => 'warehouse_invalid', 'message' => 'The warehouse does not exist in this organization.']
                        : ['path' => 'receipt', 'code' => 'forbidden', 'message' => 'The ERP refused this receipt for the connection user (procurement.receive or store membership).'];
                }
            }

            return ['po' => $po, 'line' => $line, 'state' => $state, 'before' => $before, 'after' => $after, 'issues' => $issues];
        });

        [$fingerprint, $expiresAt] = $result['issues'] === []
            ? $this->previews->issue('procurement_receipt', $this->binding($payload, $result['state']))
            : [null, null];
        $after = $result['after'];

        return [
            'purchase_order' => ['id' => $result['po']->getKey(), 'number' => $result['po']->purchase_order_number, 'status' => $result['po']->status->value],
            'purchase_order_line_id' => $result['line']->getKey(),
            'receipt' => [
                'quantity_received' => $payload['quantity_received'],
                'warehouse_id' => $payload['warehouse_id'],
                'received_date' => $payload['received_date'],
                'supplier_delivery_reference' => $payload['supplier_delivery_reference'],
            ],
            'before' => $result['before'],
            'after' => $after === null ? null : array_diff_key($after, ['sales_order_procurement' => true]),
            'sales_order_effect' => $after === null ? null : [
                'sales_order' => $after['sales_order_procurement']['sales_order'],
                'reserved_for_sales_order' => $payload['quantity_received'],
                'line' => collect($after['sales_order_procurement']['lines'])->firstWhere('sales_order_line_id', $result['line']->sales_order_line_id),
                'confirmation_ready' => $after['sales_order_procurement']['confirmation_ready'],
                'next_step' => $after['sales_order_procurement']['next_step'],
            ],
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => ['procurement_receipts.create'],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Receiving means the goods physically arrived: it adds them to stock in the warehouse and reserves them at once for the linked sales order line (not available to other orders). It does not confirm the sales order: call preview_sales_order_confirmation / confirm_sales_order separately.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function record(array $payload, string $fingerprint, string $idempotencyKey): array
    {
        [$po, $line] = $this->locate($payload, lock: true);
        $requirement = SalesOrderProcurement::query()->where('organization_id', $po->organization_id)->whereKey($line->sales_order_procurement_id)->firstOrFail();
        $binding = $this->binding($payload, $this->stateHash($po, $line, $requirement, $payload['warehouse_id']));
        $this->previews->consume('procurement_receipt', $fingerprint, $binding);
        $operationId = $this->operationId('receipt|'.$idempotencyKey);

        try {
            $warehouse = $this->warehouse($payload['warehouse_id']) ?? throw (new ModelNotFoundException)->setModel(Warehouse::class, [$payload['warehouse_id']]);
            $this->receive->execute($this->context->user(), $requirement, $warehouse, [...$this->actionData($payload, $requirement), 'client_operation_id' => $operationId]);
        } catch (Throwable $exception) {
            $this->previews->release('procurement_receipt', $fingerprint, $binding);
            throw $exception;
        }
        $receipt = ProcurementReceipt::query()->where('organization_id', $po->organization_id)->where('client_operation_id', $operationId)->firstOrFail();
        $po->refresh()->load('lines');

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (int) $receipt->getKey()],
            'receipt' => [
                'id' => (int) $receipt->getKey(),
                'quantity_received' => $payload['quantity_received'],
                'warehouse_id' => $payload['warehouse_id'],
                'received_date' => $receipt->received_on?->toDateString(),
                'supplier_delivery_reference' => $receipt->supplier_delivery_reference,
                'inventory_movement_id' => (int) $receipt->inventory_movement_id,
                'reserved_for_sales_order' => $payload['quantity_received'],
            ],
            ...$this->figures($po, $line->fresh(), $requirement->fresh()),
            'sales_order_procurement' => $this->purchaseOrders->salesOrderProcurement(SalesOrder::query()->findOrFail($line->sales_order_id)),
            'note' => 'The sales order was not confirmed. If confirmation_ready is true, call preview_sales_order_confirmation then confirm_sales_order.',
        ];
    }

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine} */
    private function locate(array $payload, bool $lock): array
    {
        $po = $this->purchaseOrders->find($this->context->organization(), $payload['purchase_order_id'], $lock);
        $line = PurchaseOrderLine::query()->where('organization_id', $po->organization_id)->where('purchase_order_id', $po->getKey())
            ->whereKey($payload['purchase_order_line_id'])->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($line === null) {
            // A line of another purchase order is never received through this one.
            throw ValidationException::withMessages(['purchase_order_line_id' => 'This line does not belong to the purchase order.']);
        }

        return [$po, $line];
    }

    /**
     * Refusals the canonical action would also raise, named precisely.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function precheck(PurchaseOrder $po, PurchaseOrderLine $line, array $payload): array
    {
        if ($po->status === PurchaseOrderStatus::Cancelled) {
            return [['path' => 'purchase_order_id', 'code' => 'purchase_order_cancelled', 'message' => 'This purchase order is cancelled: nothing can be received on it.']];
        }
        $remaining = Decimal::subtract((string) $line->quantity, (string) $line->received_quantity);
        if (Decimal::compare($remaining, '0') <= 0) {
            return [['path' => 'purchase_order_line_id', 'code' => 'line_fully_received', 'message' => 'This line has already been fully received.']];
        }
        if (Decimal::compare($payload['quantity_received'], $remaining) > 0) {
            return [['path' => 'quantity_received', 'code' => 'receipt_quantity_exceeds_remaining', 'message' => "Only {$remaining} is still expected on this line.",
                'details' => ['ordered' => Decimal::normalize((string) $line->quantity), 'received' => Decimal::normalize((string) $line->received_quantity), 'remaining' => Decimal::normalize($remaining)]]];
        }
        $warehouse = $this->warehouse($payload['warehouse_id']);
        if ($warehouse === null || $warehouse->status !== WarehouseStatus::Active) {
            return [['path' => 'warehouse_id', 'code' => 'warehouse_invalid', 'message' => 'Choose an active warehouse of this organization.']];
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private function issues(ValidationException $exception): array
    {
        $issues = [];
        foreach ($exception->errors() as $field => $messages) {
            $message = mb_substr((string) ($messages[0] ?? 'Rejected.'), 0, 300);
            $issues[] = match (true) {
                $field === 'quantity' && str_contains($message, 'dépasse') => ['path' => 'quantity_received', 'code' => 'receipt_quantity_exceeds_remaining', 'message' => $message],
                $field === 'quantity' && str_contains($message, 'entre-temps') => ['path' => 'quantity_received', 'code' => 'stale_received_quantity', 'message' => $message],
                $field === 'receiving_warehouse_id' => ['path' => 'warehouse_id', 'code' => 'warehouse_invalid', 'message' => $message],
                $field === 'received_on' => ['path' => 'received_date', 'code' => 'invalid_received_date', 'message' => $message],
                $field === 'purchase_order_line_id' => ['path' => 'purchase_order_line_id', 'code' => 'purchase_order_line_not_open', 'message' => $message],
                $field === 'status' => ['path' => 'receipt', 'code' => 'not_receivable', 'message' => $message],
                default => ['path' => $field, 'code' => 'invalid', 'message' => $message],
            };
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    private function figures(PurchaseOrder $po, PurchaseOrderLine $line, SalesOrderProcurement $requirement): array
    {
        return [
            'purchase_order_status' => $po->status->value,
            'line' => [
                'ordered_quantity' => Decimal::normalize((string) $line->quantity),
                'received_quantity' => Decimal::normalize((string) $line->received_quantity),
                'remaining_quantity' => Decimal::compare((string) $line->quantity, (string) $line->received_quantity) > 0
                    ? Decimal::subtract((string) $line->quantity, (string) $line->received_quantity) : '0.0000',
                'status' => Decimal::compare((string) $line->received_quantity, (string) $line->quantity) >= 0 ? 'received'
                    : (Decimal::compare((string) $line->received_quantity, '0') > 0 ? 'partially_received' : 'ordered'),
            ],
            'requirement' => ['number' => $requirement->procurement_number, 'status' => $requirement->status->value, 'received_quantity' => Decimal::normalize((string) $requirement->received_quantity)],
        ];
    }

    private function warehouse(int $warehouseId): ?Warehouse
    {
        return Warehouse::query()->where('organization_id', $this->context->organization()->getKey())->whereKey($warehouseId)->first();
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function actionData(array $payload, SalesOrderProcurement $requirement): array
    {
        return [
            'quantity' => $payload['quantity_received'],
            'purchase_order_line_id' => $payload['purchase_order_line_id'],
            'received_on' => $payload['received_date'],
            'supplier_delivery_reference' => $payload['supplier_delivery_reference'],
            'notes' => $payload['note'],
            // The figures the preview was made on: a receipt recorded meanwhile is refused.
            'expected_received_quantity' => (string) $requirement->received_quantity,
        ];
    }

    /** Purchase order + line + requirement received quantities + warehouse: what the receipt depends on. */
    private function stateHash(PurchaseOrder $po, PurchaseOrderLine $line, SalesOrderProcurement $requirement, int $warehouseId): string
    {
        $warehouse = Warehouse::query()->where('organization_id', $po->organization_id)->whereKey($warehouseId)->first(['id', 'status', 'updated_at']);

        return hash('sha256', implode('|', [
            $this->purchaseOrders->version($po),
            $line->getKey().':'.Decimal::normalize((string) $line->received_quantity),
            $requirement->getKey().':'.$requirement->status->value.':'.Decimal::normalize((string) $requirement->received_quantity),
            $warehouse ? $warehouse->id.':'.$warehouse->status->value.':'.$warehouse->updated_at?->format('Y-m-d H:i:s.u') : 'missing',
        ]));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function binding(array $payload, string $stateHash): array
    {
        return [
            'entity' => 'procurement_receipt',
            'mode' => 'receive',
            'target_id' => $payload['purchase_order_line_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $stateHash,
            'required_scopes' => ['procurement_receipts.create'],
        ];
    }

    /** Deterministic RFC-4122-shaped id (procurement_receipts.client_operation_id is a uuid column). */
    private function operationId(string $seed): string
    {
        $hex = hash('sha256', $this->context->connectionOrFail()->getKey().'|'.$seed);
        $hex[12] = '8';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
