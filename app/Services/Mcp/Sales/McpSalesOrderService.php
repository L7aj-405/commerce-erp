<?php

namespace App\Services\Mcp\Sales;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\RemoveSalesOrderLineAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\UpdateSalesOrderAction;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderStatus;
use App\Http\Requests\Sales\SalesOrderRules;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\Mcp\McpDryRun;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\Products\ProductStockReadService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * MCP Sales Orders (MCP-7).
 *
 * Previews never re-implement Sales logic: they run the authoritative actions
 * (CreateSalesOrderAction, UpdateSalesOrderAction, Save/RemoveSalesOrderLineAction,
 * ConfirmSalesOrderAction) inside a transaction that is always rolled back,
 * so pricing, tax resolution, permission gates (price override, discount),
 * stock and procurement checks are exactly the ERP's.
 *
 * Execution runs the same steps for real and re-hashes the outcome: if it no
 * longer matches the fingerprinted preview the whole transaction rolls back.
 */
final class McpSalesOrderService
{
    public const HEADER_FIELDS = ['customer_id', 'order_date', 'notes'];
    public const ITEM_FIELDS = [
        'line_id', 'variant_id', 'description', 'reference', 'unit_label', 'tax_rate_id',
        'quantity', 'price_input_mode', 'unit_price', 'discount_type', 'discount_value',
    ];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpSalesStoreAccess $stores,
        private readonly SalesOrderReadService $orders,
        private readonly CreateSalesOrderAction $createOrder,
        private readonly UpdateSalesOrderAction $updateOrder,
        private readonly SaveSalesOrderLineAction $saveLine,
        private readonly RemoveSalesOrderLineAction $removeLine,
        private readonly ConfirmSalesOrderAction $confirmOrder,
        private readonly ProductStockReadService $stock,
        private readonly McpIdempotencyService $canonical,
        private readonly McpDryRun $dryRunner,
        private readonly SalesOrderProcurementPlanner $procurement,
    ) {}

    public static function requiredScope(string $mode): string
    {
        return match ($mode) {
            'update' => 'sales_orders.update',
            'confirm' => 'sales_orders.confirm',
            default => 'sales_orders.create',
        };
    }

    /* ------------------------------------------------------------------
     |  Drafts
     * ---------------------------------------------------------------- */

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function normalize(string $mode, array $data, ?int $orderId = null): array
    {
        $update = $mode === 'update';
        $allowed = $update ? [...self::HEADER_FIELDS, 'items'] : ['store_id', ...self::HEADER_FIELDS, 'items'];
        if (array_diff(array_keys($data), $allowed) !== []) throw new InvalidArgumentException('forbidden_fields');
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (! is_array($item) || array_diff(array_keys($item), self::ITEM_FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        }
        if ($update && ($orderId === null || $orderId < 1)) throw new InvalidArgumentException('invalid_id');

        $max = (int) config('mcp.sales_orders.max_items', 200);
        $validator = Validator::make($data, [
            'store_id' => $update ? ['prohibited'] : ['nullable', 'integer', 'min:1'],
            'customer_id' => $update ? ['sometimes', 'nullable', 'integer', 'min:1'] : ['nullable', 'integer', 'min:1'],
            'order_date' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:'.$max],
            'items.*' => ['array'],
            'items.*.line_id' => $update ? ['nullable', 'integer', 'min:1', 'distinct'] : ['prohibited'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.reference' => ['nullable', 'string', 'max:255'],
            'items.*.unit_label' => ['nullable', 'string', 'max:255'],
            'items.*.tax_rate_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', 'string'],
            'items.*.price_input_mode' => ['nullable', 'in:ht,ttc'],
            'items.*.unit_price' => ['nullable', 'string'],
            'items.*.discount_type' => ['nullable', 'string'],
            'items.*.discount_value' => ['nullable', 'string'],
        ]);
        $validator->after(function ($validator) use ($data) {
            foreach ((array) ($data['items'] ?? []) as $index => $item) {
                $kinds = (int) isset($item['variant_id']) + (int) isset($item['description']);
                if ($kinds > 1 || (! isset($item['line_id']) && $kinds === 0)) {
                    $validator->errors()->add("items.{$index}", 'A new item needs exactly one of variant_id (catalog line) or description (custom line).');
                }
                if (! isset($item['line_id']) && ! isset($item['quantity'])) {
                    $validator->errors()->add("items.{$index}.quantity", 'quantity is required for a new item.');
                }
            }
        });
        $validator->validate();

        $header = array_intersect_key($data, array_flip(self::HEADER_FIELDS));
        if ($update && $header === [] && ! array_key_exists('items', $data)) throw new InvalidArgumentException('no_changes');
        ksort($header);

        $payload = ['mode' => $update ? 'update' : 'create', 'header' => $header];
        if ($update) $payload['sales_order_id'] = $orderId;
        else $payload['store_id'] = isset($data['store_id']) ? (int) $data['store_id'] : null;
        if (array_key_exists('items', $data)) {
            $payload['items'] = array_map(function (array $item) {
                ksort($item);

                return $item;
            }, array_values($data['items']));
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $evaluation = $this->dryRun(function () use ($payload) {
            $order = $payload['mode'] === 'update'
                ? $this->orders->find($this->context->organization(), (int) $payload['sales_order_id'], lock: true)
                : null;

            return $this->run($payload, $order);
        });
        $scope = self::requiredScope($payload['mode']);
        [$fingerprint, $expiresAt] = $evaluation['issues'] === [] && $evaluation['has_changes']
            ? $this->issueFingerprint($payload['mode'], $payload['sales_order_id'] ?? null, [
                'payload_hash' => $this->hash($payload),
                'state_hash' => $evaluation['state_hash'],
                'proposed_hash' => $evaluation['proposed_hash'],
            ])
            : [null, null];

        return [
            'mode' => $payload['mode'],
            'sales_order_id' => $payload['sales_order_id'] ?? null,
            'store' => $evaluation['store'],
            'customer' => $evaluation['customer'],
            'header' => $evaluation['header'],
            'lines' => $evaluation['lines'],
            'removed_lines' => $evaluation['removed_lines'],
            'totals' => $evaluation['totals'],
            'valid' => $evaluation['issues'] === [],
            'issues' => $evaluation['issues'],
            'has_changes' => $evaluation['has_changes'],
            'required_scopes' => [$scope],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Draft only: nothing is confirmed, reserved, invoiced or paid. Draft stock sourcing stays provisional until confirmation.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     * @return array{order: SalesOrder, evaluation: array<string, mixed>}
     */
    public function create(array $payload, string $fingerprint): array
    {
        $stored = $this->storedFingerprint($fingerprint, 'create', null);
        $evaluation = $this->run($payload, null);
        $this->assertMatches($stored, $payload, $evaluation);
        Cache::forget($this->previewKey($fingerprint));

        return ['order' => $evaluation['order'], 'evaluation' => $evaluation];
    }

    /**
     * Must run inside the caller's transaction.
     *
     * @param array<string, mixed> $payload
     * @return array{order: SalesOrder, evaluation: array<string, mixed>}
     */
    public function update(array $payload, string $fingerprint): array
    {
        $stored = $this->storedFingerprint($fingerprint, 'update', (int) $payload['sales_order_id']);
        $order = $this->orders->find($this->context->organization(), (int) $payload['sales_order_id'], lock: true);
        if (! $order->isCommerciallyEditable()) throw new InvalidArgumentException('sales_order_not_editable');
        $evaluation = $this->run($payload, $order);
        $this->assertMatches($stored, $payload, $evaluation);
        Cache::forget($this->previewKey($fingerprint));

        return ['order' => $evaluation['order'], 'evaluation' => $evaluation];
    }

    /* ------------------------------------------------------------------
     |  Confirmation
     * ---------------------------------------------------------------- */

    /** @return array<string, mixed> */
    public function previewConfirmation(int $orderId): array
    {
        $result = $this->dryRun(function () use ($orderId) {
            $order = $this->orders->find($this->context->organization(), $orderId, lock: true);
            $state = $this->orders->version($order);
            $before = $this->orders->totals($order);
            $issues = [];
            $plan = [];
            $totals = $before;
            $blockers = $order->status === SalesOrderStatus::Draft ? $this->procurement->confirmationBlockers($order) : [];
            if ($order->status !== SalesOrderStatus::Draft) {
                $issues[] = $this->issue('sales_order', 'not_draft', 'Only a draft order can be confirmed.');
            } elseif ($blockers !== []) {
                // Supplier goods derived from the quotation not physically received yet
                // (the same check ConfirmSalesOrderAction applies), one issue per line.
                foreach ($blockers as $blocker) {
                    $issues[] = ['path' => $blocker['path'], 'code' => $blocker['code'], 'message' => mb_substr($blocker['message'], 0, 300), 'details' => $blocker['details']];
                }
            } else {
                try {
                    // The authoritative confirmation, rolled back below.
                    $confirmed = $this->confirmOrder->execute($this->context->user(), $order);
                    $confirmed->load(['lines.allocations.warehouse:id,name,code']);
                    $totals = $this->orders->totals($confirmed);
                    $plan = $confirmed->lines->map(fn (SalesOrderLine $line) => [
                        'line_id' => $line->getKey(),
                        'product_name' => $line->product_name,
                        'sku' => $line->sku,
                        'quantity' => (string) $line->quantity,
                        'reservations' => $line->allocations->map(fn ($allocation) => [
                            'warehouse' => ['id' => $allocation->warehouse_id, 'name' => $allocation->warehouse?->name, 'code' => $allocation->warehouse?->code],
                            'quantity' => (string) $allocation->quantity,
                        ])->values()->all(),
                    ])->values()->all();
                } catch (ValidationException $exception) {
                    $issues[] = $this->issue('sales_order', 'confirmation_rejected', $this->first($exception), $this->errors($exception));
                } catch (HttpExceptionInterface) {
                    $issues[] = $this->issue('sales_order', 'not_permitted', 'The ERP refused the confirmation for this user.');
                }
            }

            return ['order' => $order, 'state' => $state, 'issues' => $issues, 'plan' => $plan, 'totals' => $totals];
        });

        [$fingerprint, $expiresAt] = $result['issues'] === []
            ? $this->issueFingerprint('confirm', $orderId, ['state_hash' => $result['state']])
            : [null, null];
        $order = $result['order'];

        return [
            'mode' => 'confirm',
            'sales_order_id' => $orderId,
            'order_number' => $order->order_number,
            'current_status' => $order->status->value,
            'customer' => ['id' => $order->customer_id, 'name' => $order->customer_name, 'company' => $order->customer_company],
            'totals' => $result['totals'],
            'reservation_plan' => $result['plan'],
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => [self::requiredScope('confirm')],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Confirmation makes the order an official commitment and reserves company stock at execution time (re-checked then). It does not issue an invoice or record a payment.',
        ];
    }

    /**
     * Confirm through ConfirmSalesOrderAction. Must run inside the caller's transaction.
     *
     * @return array{order: SalesOrder, replay: bool}
     */
    public function confirm(int $orderId, string $fingerprint): array
    {
        $order = $this->orders->find($this->context->organization(), $orderId, lock: true);

        // Retry after a successful confirmation with the same fingerprint.
        $done = Cache::get($this->doneKey($fingerprint));
        if (is_array($done) && (int) ($done['sales_order_id'] ?? 0) === $orderId
            && hash_equals((string) ($done['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            && $order->status === SalesOrderStatus::Confirmed) {
            return ['order' => $order, 'replay' => true];
        }

        $stored = $this->storedFingerprint($fingerprint, 'confirm', $orderId);
        if (! hash_equals((string) ($stored['state_hash'] ?? ''), $this->orders->version($order))) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        $confirmed = $this->confirmOrder->execute($this->context->user(), $order);
        Cache::forget($this->previewKey($fingerprint));
        Cache::put($this->doneKey($fingerprint), [
            'sales_order_id' => $orderId,
            'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
        ], now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300) * 2));

        return ['order' => $this->orders->find($this->context->organization(), (int) $confirmed->getKey()), 'replay' => false];
    }

    /* ------------------------------------------------------------------
     |  Shared evaluation
     * ---------------------------------------------------------------- */

    /**
     * Apply the candidate through the real actions in the current transaction.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function run(array $payload, ?SalesOrder $order): array
    {
        $update = $payload['mode'] === 'update';
        $organization = $this->context->organization();
        $user = $this->context->user();
        $issues = [];
        $state = [];
        $header = $payload['header'];
        $currentHeader = null;
        $changedFields = [];

        if ($update) {
            $state[] = 'o:'.$this->orders->version($order);
            $currentHeader = ['customer_id' => $order->customer_id, 'order_date' => $order->sale_date?->toDateString(), 'notes' => $order->notes];
            if (! $order->isCommerciallyEditable()) {
                $issues[] = $this->issue('sales_order', 'not_editable', 'Only a draft sales order can be modified.');

                return $this->result($order, $issues, $state, $currentHeader, $currentHeader, [], [], [], false);
            }
        } else {
            try {
                $store = $this->stores->resolve($payload['store_id']);
            } catch (ValidationException $exception) {
                $issues[] = $this->issue('store_id', 'store_unavailable', $this->first($exception));

                return $this->result(null, $issues, $state, null, $header, [], [], [], true);
            }
            $state[] = 's:'.$store->getKey();
        }

        $proposedHeader = array_replace($currentHeader ?? ['customer_id' => null, 'order_date' => now()->toDateString(), 'notes' => null], $header);
        if (($proposedHeader['customer_id'] ?? null) !== null) {
            $customer = Customer::query()->where('organization_id', $organization->getKey())->whereKey($proposedHeader['customer_id'])->first();
            if ($customer) $state[] = 'c:'.$customer->getKey().'@'.$customer->updated_at?->format('Y-m-d H:i:s.u');
        }
        $changedFields = $update
            ? array_values(array_filter(self::HEADER_FIELDS, fn ($field) => (string) ($currentHeader[$field] ?? '') !== (string) ($proposedHeader[$field] ?? '')))
            : array_keys(array_filter($proposedHeader, fn ($value) => $value !== null));

        $headerData = [
            'customer_id' => $proposedHeader['customer_id'],
            'sale_date' => $proposedHeader['order_date'],
            'currency_code' => $update ? $order->currency_code : strtoupper((string) config('platform.currency_code', 'MAD')),
            'notes' => $proposedHeader['notes'],
        ];
        if (! $update || $changedFields !== []) {
            try {
                Validator::make($headerData, SalesOrderRules::header((int) $organization->getKey()))->validate();
                $order = $update
                    ? $this->updateOrder->execute($user, $order, $headerData)
                    : $this->createOrder->execute($user, $organization, $store, $headerData);
            } catch (ValidationException $exception) {
                $issues[] = $this->issue('header', 'invalid_header', $this->first($exception), $this->errors($exception));
            } catch (ModelNotFoundException) {
                $issues[] = $this->issue('customer_id', 'customer_not_found', 'The customer was not found.');
            } catch (HttpExceptionInterface) {
                $issues[] = $this->issue('header', 'not_permitted', 'The ERP refused this change for this user.');
            }
            if (! $order) {
                return $this->result(null, $issues, $state, $currentHeader, $proposedHeader, $changedFields, [], [], true);
            }
        }

        $existing = $order->lines()->get()->keyBy(fn (SalesOrderLine $line) => (int) $line->getKey());
        $lineResults = [];
        $removed = [];
        if (array_key_exists('items', $payload)) {
            $referenced = array_values(array_filter(array_map(fn (array $item) => isset($item['line_id']) ? (int) $item['line_id'] : null, $payload['items'])));
            foreach ($existing->except($referenced) as $line) {
                $removed[] = $this->orders->line($line);
                try {
                    $this->removeLine->execute($user, $order, $line);
                } catch (ValidationException $exception) {
                    $issues[] = $this->issue("lines.{$line->getKey()}", 'line_not_removable', $this->first($exception));
                }
            }
            foreach ($payload['items'] as $index => $item) {
                $line = isset($item['line_id']) ? $existing->get((int) $item['line_id']) : null;
                if (isset($item['line_id']) && ! $line) {
                    $issues[] = $this->issue("items.{$index}", 'line_not_found', 'This line does not belong to the sales order.');
                    continue;
                }
                if ($line && $this->untouched($item, $line)) {
                    $lineResults[] = ['action' => 'unchanged', 'line_id' => (int) $line->getKey()];
                    continue;
                }
                try {
                    $data = $this->lineData($item, $line);
                    Validator::make($data, SalesOrderRules::line())->validate();
                    $saved = $this->saveLine->execute($user, $order, $data, $line);
                    $lineResults[] = ['action' => $line ? 'update' : 'add', 'line_id' => (int) $saved->getKey()];
                } catch (ModelNotFoundException) {
                    $issues[] = $this->issue("items.{$index}", 'article_not_found', 'The product variant or tax rate was not found.');
                } catch (ValidationException $exception) {
                    $issues[] = $this->issue("items.{$index}", 'invalid_item', $this->first($exception), $this->errors($exception));
                } catch (HttpExceptionInterface) {
                    $issues[] = $this->issue("items.{$index}", 'not_permitted', 'This line needs an ERP permission the user lacks (price override: sales_orders.override_price, discount: sales_orders.apply_discount).');
                }
            }
        } else {
            foreach ($existing as $line) $lineResults[] = ['action' => 'unchanged', 'line_id' => (int) $line->getKey()];
        }

        return $this->result($order->fresh(['lines', 'store']), $issues, $state, $currentHeader, $proposedHeader, $changedFields, $lineResults, $removed, ! $update);
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param list<string> $state
     * @param list<array{action: string, line_id: int}> $lineResults
     * @param list<array<string, mixed>> $removed
     * @return array<string, mixed>
     */
    private function result(?SalesOrder $order, array $issues, array $state, ?array $currentHeader, array $proposedHeader, array $changedFields, array $lineResults, array $removed, bool $creating): array
    {
        $lines = [];
        if ($order) {
            $byId = $order->lines->keyBy(fn (SalesOrderLine $line) => (int) $line->getKey());
            $variantIds = $order->lines->pluck('product_variant_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            foreach (ProductVariant::query()->with('product:id,updated_at')->whereIn('id', $variantIds)->get(['id', 'product_id', 'updated_at']) as $variant) {
                $state[] = 'v:'.$variant->getKey().'@'.$variant->updated_at?->format('Y-m-d H:i:s.u').'/p@'.$variant->product?->updated_at?->format('Y-m-d H:i:s.u');
            }
            $stock = $this->context->allows('inventory.view') ? $this->stock->variantTotals($this->context->organization(), $variantIds) : [];
            foreach ($lineResults as $result) {
                $line = $byId->get($result['line_id']);
                if (! $line) continue;
                $presented = $this->orders->line($line);
                $lines[] = [
                    'action' => $result['action'],
                    ...$presented,
                    // Line ids of lines added by a preview are not persisted.
                    'line_id' => $result['action'] === 'add' ? null : $presented['line_id'],
                    'stock_available' => $line->product_variant_id ? ($stock[(int) $line->product_variant_id]['available'] ?? null) : null,
                ];
            }
        }
        $totals = $order ? $this->orders->totals($order) : null;
        $hasChanges = $creating || $changedFields !== [] || $removed !== []
            || collect($lines)->contains(fn (array $line) => $line['action'] !== 'unchanged');
        sort($state);

        $customer = $order && $order->customer_id ? ['id' => $order->customer_id, 'name' => $order->customer_name, 'company' => $order->customer_company] : null;

        return [
            'order' => $order,
            'store' => $order?->store ? ['id' => $order->store_id, 'name' => $order->store->name] : null,
            'customer' => $customer,
            'header' => ['current' => $currentHeader, 'proposed' => $proposedHeader, 'changed_fields' => $changedFields],
            'lines' => $lines,
            'removed_lines' => $removed,
            'totals' => $totals,
            'issues' => $issues,
            'has_changes' => $hasChanges,
            'state_hash' => hash('sha256', implode('|', $state)),
            'proposed_hash' => $this->hash([
                'header' => $proposedHeader,
                'customer' => $customer,
                'lines' => array_map(fn (array $line) => array_diff_key($line, ['stock_available' => true]), $lines),
                'removed' => array_column($removed, 'line_id'),
                'totals' => $totals,
            ]),
        ];
    }

    /** Map an MCP item onto SaveSalesOrderLineAction input. @param array<string, mixed> $item @return array<string, mixed> */
    private function lineData(array $item, ?SalesOrderLine $line): array
    {
        $custom = isset($item['description']) || (! isset($item['variant_id']) && $line?->line_type === SalesOrderLineType::Custom);
        $discountType = $item['discount_type'] ?? $line?->discount_type?->value ?? 'none';
        $discountValue = array_key_exists('discount_value', $item) ? $item['discount_value']
            : ($line && $discountType === $line->discount_type?->value ? (string) $line->discount_value : null);
        $common = [
            'quantity' => (string) ($item['quantity'] ?? $line?->quantity),
            'discount_type' => $discountType,
            'discount_value' => $discountType === 'none' ? null : $discountValue,
            'tax_rate_id' => $item['tax_rate_id'] ?? null,
        ];

        if (! $custom) {
            // Catalog override is HT only, exactly like the web line editor.
            if (isset($item['unit_price']) && ($item['price_input_mode'] ?? 'ht') !== 'ht') {
                throw ValidationException::withMessages(['unit_price' => 'A catalog price override is entered HT (price_input_mode "ht").']);
            }
            // An omitted price on a changed line means the current catalog price.
            return [
                'line_type' => SalesOrderLineType::Catalog->value,
                'product_variant_id' => (int) ($item['variant_id'] ?? $line?->product_variant_id),
                ...$common,
                ...(isset($item['unit_price']) ? ['unit_price_excl_tax' => $item['unit_price']] : []),
            ];
        }

        if ($line && ! isset($item['tax_rate_id']) && Decimal::compare((string) $line->tax_rate, '0') > 0) {
            throw ValidationException::withMessages(['tax_rate_id' => 'Re-send tax_rate_id when modifying a taxed custom line.']);
        }

        return [
            'line_type' => SalesOrderLineType::Custom->value,
            'description' => $item['description'] ?? $line?->product_name,
            'reference' => array_key_exists('reference', $item) ? $item['reference'] : $line?->reference,
            'unit_label' => array_key_exists('unit_label', $item) ? $item['unit_label'] : $line?->unit_label,
            'price_input_mode' => isset($item['unit_price']) ? ($item['price_input_mode'] ?? 'ht') : 'ht',
            'unit_price' => $item['unit_price'] ?? ($line ? (string) $line->unit_price_excl_tax : null),
            ...$common,
        ];
    }

    /** @param array<string, mixed> $item */
    private function untouched(array $item, SalesOrderLine $line): bool
    {
        if (isset($item['tax_rate_id']) || isset($item['price_input_mode'])) return false;
        if (isset($item['variant_id']) && (int) $item['variant_id'] !== (int) $line->product_variant_id) return false;
        if (isset($item['description']) && ($line->line_type !== SalesOrderLineType::Custom || $item['description'] !== $line->product_name)) return false;
        $discountType = $item['discount_type'] ?? $line->discount_type?->value;
        foreach (['reference', 'unit_label'] as $field) {
            if (array_key_exists($field, $item) && (string) $item[$field] !== (string) $line->{$field}) return false;
        }
        $decimals = [
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price_excl_tax,
            'discount_value' => $discountType === 'none' ? null : $line->discount_value,
        ];
        foreach ($decimals as $field => $current) {
            if ($field === 'discount_value' && $discountType === 'none') continue;
            if (! array_key_exists($field, $item)) continue;
            if ($item[$field] === null || $current === null || Decimal::compare((string) $item[$field], (string) $current) !== 0) return false;
        }

        return $discountType === $line->discount_type?->value;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function dryRun(callable $callback): mixed
    {
        return $this->dryRunner->run($callback);
    }

    /* ------------------------------------------------------------------
     |  Fingerprints
     * ---------------------------------------------------------------- */

    /** @param array<string, string> $bound @return array{string, string} */
    private function issueFingerprint(string $mode, ?int $orderId, array $bound): array
    {
        $fingerprint = bin2hex(random_bytes(32));
        $expiresAt = now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300));
        Cache::put($this->previewKey($fingerprint), [
            'organization_id' => (int) $this->context->organization()->getKey(),
            'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
            'mode' => $mode,
            'sales_order_id' => $orderId,
            'required_scopes' => [self::requiredScope($mode)],
            ...$bound,
        ], $expiresAt);

        return [$fingerprint, $expiresAt->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function storedFingerprint(string $fingerprint, string $mode, ?int $orderId): array
    {
        $stored = preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? Cache::get($this->previewKey($fingerprint)) : null;
        if (! is_array($stored)
            || (int) ($stored['organization_id'] ?? 0) !== (int) $this->context->organization()->getKey()
            || ! hash_equals((string) ($stored['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            || ($stored['mode'] ?? null) !== $mode
            || ($stored['sales_order_id'] ?? null) !== $orderId
            || ($stored['required_scopes'] ?? null) !== [self::requiredScope($mode)]) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }

        return $stored;
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $payload @param array<string, mixed> $evaluation */
    private function assertMatches(array $stored, array $payload, array $evaluation): void
    {
        if ($evaluation['issues'] !== [] || ! $evaluation['has_changes']
            || ! hash_equals((string) ($stored['payload_hash'] ?? ''), $this->hash($payload))
            || ! hash_equals((string) ($stored['state_hash'] ?? ''), $evaluation['state_hash'])
            || ! hash_equals((string) ($stored['proposed_hash'] ?? ''), $evaluation['proposed_hash'])) {
            // Thrown inside the caller's transaction: every action write rolls back.
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', $this->canonical->canonicalJson($value));
    }

    private function previewKey(string $fingerprint): string
    {
        return 'mcp:sales-order-preview:'.hash('sha256', $fingerprint);
    }

    private function doneKey(string $fingerprint): string
    {
        return 'mcp:sales-order-confirmed:'.hash('sha256', $fingerprint);
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private function issue(string $path, string $code, string $message, array $details = []): array
    {
        return ['path' => $path, 'code' => $code, 'message' => mb_substr($message, 0, 300)] + ($details !== [] ? ['fields' => $details] : []);
    }

    private function first(ValidationException $exception): string
    {
        return (string) (collect($exception->errors())->flatten()->first() ?? 'Invalid value.');
    }

    /** @return array<string, list<string>> */
    private function errors(ValidationException $exception): array
    {
        return collect($exception->errors())->take(10)
            ->map(fn ($messages) => array_values(array_map(fn ($message) => mb_substr((string) $message, 0, 200), array_slice((array) $messages, 0, 3))))
            ->all();
    }
}
