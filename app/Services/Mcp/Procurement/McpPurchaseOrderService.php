<?php

namespace App\Services\Mcp\Procurement;

use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderProcurement;
use App\Models\Supplier;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Services\Mcp\Sales\SalesOrderReadService;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Supplier purchase orders over MCP. The purchase order is always created by
 * CreatePurchaseOrderFromProcurementRequirementsAction::forSalesOrder (derive a
 * missing requirement from the converted quotation, every open requirement of
 * the supplier on the order, draft or confirmed order, no duplicate or
 * over-coverage, procurement.manage + store, purchase_order.* / procurement.*
 * audit). The preview runs that same action in a rolled-back transaction.
 * Nothing here touches stock, payments, invoices or the order status.
 */
final class McpPurchaseOrderService
{
    /** Idempotency target type (mcp_idempotency_records.target_type is varchar(32)). */
    public const TARGET_TYPE = 'purchase_order';

    public const FIELDS = ['sales_order_id', 'supplier_id', 'expected_delivery_date', 'notes'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SalesOrderReadService $orders,
        private readonly PurchaseOrderReadService $purchaseOrders,
        private readonly SalesOrderProcurementPlanner $planner,
        private readonly CreatePurchaseOrderFromProcurementRequirementsAction $create,
        private readonly McpPartyPreviews $previews,
        private readonly McpDryRun $dryRun,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function normalize(array $data): array
    {
        if (array_diff(array_keys($data), self::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        $payload = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $payload += ['expected_delivery_date' => null, 'notes' => null];
        Validator::make($payload, [
            'sales_order_id' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $payload['sales_order_id'] = (int) $payload['sales_order_id'];
        $payload['supplier_id'] = (int) $payload['supplier_id'];
        ksort($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $result = $this->dryRun->run(function () use ($payload) {
            $order = $this->orders->find($this->context->organization(), $payload['sales_order_id'], lock: true);
            $supplier = $this->supplier($payload['supplier_id']);
            // Captured BEFORE the action runs: create() must see the same state.
            $state = $this->stateHash($order, $payload['supplier_id']);
            $issues = [];
            $lines = [];
            if ($supplier === null) {
                $issues[] = ['path' => 'supplier_id', 'code' => 'supplier_not_found', 'message' => 'The supplier does not exist in this organization. Use search_suppliers.'];
            } else {
                try {
                    $po = $this->create->forSalesOrder($this->context->user(), $order, $supplier, $this->actionData($payload));
                    $lines = $po->lines()->with(['salesOrderLine:id,product_name,variant_name', 'procurement:id,procurement_number,source_quotation_id'])->get()
                        ->map(fn (PurchaseOrderLine $line) => [
                            'sales_order_line_id' => $line->sales_order_line_id,
                            'article' => trim(($line->salesOrderLine?->product_name ?? '').($line->salesOrderLine?->variant_name ? ' — '.$line->salesOrderLine->variant_name : '')),
                            'product_variant_id' => $line->product_variant_id,
                            'quantity' => Decimal::normalize((string) $line->quantity),
                            'requirement' => $line->procurement?->procurement_number,
                            'derived_from_quotation' => $line->procurement?->source_quotation_id !== null,
                        ])->values()->all();
                } catch (ValidationException $exception) {
                    $issues = $this->issues($exception);
                } catch (HttpExceptionInterface $exception) {
                    $issues[] = $exception->getStatusCode() === 404
                        ? ['path' => 'supplier_id', 'code' => 'supplier_not_found', 'message' => 'The supplier or a requirement was not found in this organization.']
                        : ['path' => 'purchase_order', 'code' => 'forbidden', 'message' => 'The ERP refused this purchase order for the connection user (procurement.manage or store membership).'];
                }
            }

            return ['order' => $order, 'supplier' => $supplier, 'state' => $state, 'issues' => $issues, 'lines' => $lines];
        });

        [$fingerprint, $expiresAt] = $result['issues'] === []
            ? $this->previews->issue('purchase_order', $this->binding($payload, $result['state']))
            : [null, null];
        $order = $result['order'];

        return [
            'sales_order' => ['id' => $order->getKey(), 'order_number' => $order->order_number, 'status' => $order->status->value],
            'supplier' => $result['supplier'] ? ['id' => $result['supplier']->id, 'name' => $result['supplier']->name] : null,
            'expected_delivery_date' => $payload['expected_delivery_date'],
            'lines' => $result['lines'],
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => ['purchase_orders.create'],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Creating it places a real supplier order (financial commitment) for these quantities, linked to the sales order. It creates no stock, no payment and no invoice, and the sales order stays as it is (a draft stays a draft). Goods are stocked only by receive_procurement once they physically arrive.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, string $fingerprint, string $idempotencyKey): array
    {
        $order = $this->orders->find($this->context->organization(), $payload['sales_order_id'], lock: true);
        $supplier = $this->supplier($payload['supplier_id']) ?? throw (new ModelNotFoundException)->setModel(Supplier::class, [$payload['supplier_id']]);
        $binding = $this->binding($payload, $this->stateHash($order, $payload['supplier_id']));
        $this->previews->consume('purchase_order', $fingerprint, $binding);

        try {
            $po = $this->create->forSalesOrder($this->context->user(), $order, $supplier, $this->actionData($payload), $this->operationId('po|'.$idempotencyKey));
        } catch (Throwable $exception) {
            $this->previews->release('purchase_order', $fingerprint, $binding);
            throw $exception;
        }

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (int) $po->getKey()],
            'purchase_order' => $this->purchaseOrders->present(PurchaseOrder::query()->whereKey($po->getKey())->firstOrFail()),
            'sales_order_procurement' => $this->purchaseOrders->salesOrderProcurement($order->fresh()),
        ];
    }

    private function supplier(int $supplierId): ?Supplier
    {
        return Supplier::query()->where('organization_id', $this->context->organization()->getKey())->whereKey($supplierId)->first();
    }

    /**
     * Label the ERP's refusal (the decision is the action's).
     *
     * @return list<array<string, mixed>>
     */
    private function issues(ValidationException $exception): array
    {
        $issues = [];
        foreach ($exception->errors() as $field => $messages) {
            $message = mb_substr((string) ($messages[0] ?? 'Rejected.'), 0, 300);
            $issues[] = match (true) {
                $field === 'supplier_id' && str_contains($message, 'inactif') => ['path' => 'supplier_id', 'code' => 'supplier_inactive', 'message' => $message],
                $field === 'supplier_id' => ['path' => 'supplier_id', 'code' => 'no_open_requirement', 'message' => $message],
                str_contains($message, 'déjà entièrement couvert') || str_contains($message, 'déjà commandé') => ['path' => 'requirements', 'code' => 'already_ordered', 'message' => $message],
                str_contains($message, 'autre fournisseur') => ['path' => 'supplier_id', 'code' => 'wrong_supplier', 'message' => $message],
                str_contains($message, 'annulée') => ['path' => 'sales_order', 'code' => 'sales_order_not_open', 'message' => $message],
                default => ['path' => $field === 'requirement_ids' ? 'requirements' : $field, 'code' => 'requirement_not_orderable', 'message' => $message],
            };
        }

        return $issues;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function actionData(array $payload): array
    {
        return ['expected_delivery_date' => $payload['expected_delivery_date'], 'notes' => $payload['notes']];
    }

    /** The order + its requirements + their active purchase order quantities + the supplier: what the purchase order depends on. */
    private function stateHash(SalesOrder $order, int $supplierId): string
    {
        $requirements = SalesOrderProcurement::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->orderBy('id')->get(['id', 'status', 'quantity', 'received_quantity', 'supplier_id', 'updated_at'])
            ->map(fn ($r) => implode(':', [$r->id, $r->status->value, $r->quantity, $r->received_quantity, $r->supplier_id, $r->updated_at?->format('Y-m-d H:i:s.u')]))->all();
        $onOrders = SalesOrderProcurement::activePurchaseOrderQuantities((int) $order->organization_id,
            SalesOrderProcurement::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())->pluck('id')->map(fn ($id) => (int) $id)->all());
        ksort($onOrders);
        $supplier = Supplier::query()->where('organization_id', $order->organization_id)->whereKey($supplierId)->first(['id', 'active', 'updated_at']);

        return hash('sha256', implode('|', [
            $this->orders->version($order), implode(',', $requirements), json_encode($onOrders),
            $supplier ? $supplier->id.':'.(int) $supplier->active.':'.$supplier->updated_at?->format('Y-m-d H:i:s.u') : 'missing',
        ]));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function binding(array $payload, string $stateHash): array
    {
        return [
            'entity' => 'purchase_order',
            'mode' => 'create',
            'target_id' => $payload['sales_order_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $stateHash,
            'required_scopes' => ['purchase_orders.create'],
        ];
    }

    /** Deterministic RFC-4122-shaped id (client_operation_id columns are uuid). */
    private function operationId(string $seed): string
    {
        $hex = hash('sha256', $this->context->connectionOrFail()->getKey().'|'.$seed);
        $hex[12] = '8';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
