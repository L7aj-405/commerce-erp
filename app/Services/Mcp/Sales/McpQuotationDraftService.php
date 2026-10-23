<?php

namespace App\Services\Mcp\Sales;

use App\Actions\Quotations\CreateQuotationAction;
use App\Actions\Quotations\RemoveQuotationLineAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Actions\Quotations\UpdateQuotationAction;
use App\Enums\CustomerStatus;
use App\Enums\QuotationLineType;
use App\Http\Requests\Quotations\SaveQuotationLineRequest;
use App\Http\Requests\Quotations\StoreQuotationRequest;
use App\Http\Requests\Quotations\UpdateQuotationRequest;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Store;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\Products\ProductStockReadService;
use App\Services\QuotationDocumentSettings;
use App\Services\QuotationLinePricing;
use App\Services\QuotationLineSourceResolver;
use App\Services\QuotationTotals;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * MCP quotation drafts (MCP-5). Candidate data is validated with the same rules
 * as the web Devis forms, priced by QuotationLinePricing, and persisted only
 * through the existing quotation actions (numbering, snapshots, totals, status
 * and audit stay theirs). Nothing here confirms, issues or converts a Devis.
 */
final class McpQuotationDraftService
{
    public const HEADER_FIELDS = ['customer_id', 'quotation_date', 'valid_until', 'notes', 'terms'];
    public const ITEM_FIELDS = ['line_id', 'variant_id', 'non_stock_item_id', 'quantity', 'price_input_mode', 'unit_price', 'discount_type', 'discount_value'];
    /** Line inputs compared to decide whether an existing line is untouched. */
    private const LINE_INPUTS = ['quantity', 'price_input_mode', 'unit_price', 'discount_type', 'discount_value'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpSalesStoreAccess $stores,
        private readonly QuotationReadService $quotations,
        private readonly QuotationLineSourceResolver $sources,
        private readonly QuotationLinePricing $pricing,
        private readonly QuotationDocumentSettings $settings,
        private readonly ProductStockReadService $stock,
        private readonly McpIdempotencyService $canonical,
        private readonly CreateQuotationAction $createQuotation,
        private readonly UpdateQuotationAction $updateQuotation,
        private readonly SaveQuotationLineAction $saveLine,
        private readonly RemoveQuotationLineAction $removeLine,
    ) {}

    public static function requiredScope(string $mode): string
    {
        return $mode === 'update' ? 'quotations.update' : 'quotations.create';
    }

    /**
     * Structural validation and canonical form of the candidate data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(string $mode, array $data, ?int $quotationId = null): array
    {
        $update = $mode === 'update';
        $allowed = $update ? [...self::HEADER_FIELDS, 'items'] : ['store_id', ...self::HEADER_FIELDS, 'items'];
        if (array_diff(array_keys($data), $allowed) !== []) throw new InvalidArgumentException('forbidden_fields');
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (! is_array($item) || array_diff(array_keys($item), self::ITEM_FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        }
        if ($update && ($quotationId === null || $quotationId < 1)) throw new InvalidArgumentException('invalid_id');

        $max = (int) config('mcp.quotations.max_items', 200);
        $header = array_intersect_key(($update ? new UpdateQuotationRequest : new StoreQuotationRequest)->rules(), array_flip(self::HEADER_FIELDS));
        $rules = [
            ...$header,
            'customer_id' => $update ? ['sometimes', 'required', 'integer', 'min:1'] : ['required', 'integer', 'min:1'],
            'items' => $update ? ['sometimes', 'array', 'min:1', 'max:'.$max] : ['required', 'array', 'min:1', 'max:'.$max],
            'items.*' => ['array'],
            'items.*.line_id' => $update ? ['nullable', 'integer', 'min:1', 'distinct'] : ['prohibited'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.non_stock_item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', 'string'],
            'items.*.price_input_mode' => ['nullable', 'string'],
            'items.*.unit_price' => ['nullable', 'string'],
            'items.*.discount_type' => ['nullable', 'string'],
            'items.*.discount_value' => ['nullable', 'string'],
        ];
        if (! $update) $rules['store_id'] = ['nullable', 'integer', 'min:1'];

        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use ($data) {
            foreach ((array) ($data['items'] ?? []) as $index => $item) {
                $articles = (int) isset($item['variant_id']) + (int) isset($item['non_stock_item_id']);
                if ($articles > 1 || (! isset($item['line_id']) && $articles === 0)) {
                    $validator->errors()->add("items.{$index}", 'Each new item needs exactly one of variant_id or non_stock_item_id.');
                }
                if (! isset($item['line_id']) && ! isset($item['quantity'])) {
                    $validator->errors()->add("items.{$index}.quantity", 'quantity is required for a new item.');
                }
            }
        });
        $validator->validate();

        $headerValues = array_intersect_key($data, array_flip(self::HEADER_FIELDS));
        if ($update && $headerValues === [] && ! array_key_exists('items', $data)) throw new InvalidArgumentException('no_changes');
        ksort($headerValues);

        $payload = ['mode' => $update ? 'update' : 'create', 'header' => $headerValues];
        if ($update) $payload['quotation_id'] = $quotationId;
        else $payload['store_id'] = isset($data['store_id']) ? (int) $data['store_id'] : null;
        if (array_key_exists('items', $data)) {
            $payload['items'] = array_map(function (array $item) {
                ksort($item);

                return $item;
            }, array_values($data['items']));
        }

        return $payload;
    }

    /**
     * Resolve and price the candidate without persisting anything.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function evaluate(array $payload, ?Quotation $quotation = null): array
    {
        $update = $payload['mode'] === 'update';
        $organizationId = (int) $this->context->organization()->getKey();
        $issues = [];
        $state = [];

        if ($update && ! $quotation->status->isEditable()) {
            $issues[] = $this->issue('quotation', 'quotation_not_editable', 'Only a draft quotation can be modified.');
        }

        $store = $update ? $quotation->store : null;
        if (! $update) {
            try {
                $store = $this->stores->resolve($payload['store_id']);
            } catch (ValidationException $exception) {
                $issues[] = $this->issue('store_id', 'store_unavailable', $this->first($exception));
            }
        }
        if ($store) $state[] = 's:'.$store->getKey();

        $header = $payload['header'];
        $customer = $update ? $quotation->customer : null;
        if (array_key_exists('customer_id', $header)) {
            $customer = Customer::query()->where('organization_id', $organizationId)->whereKey($header['customer_id'])->first();
            if (! $customer) {
                $issues[] = $this->issue('customer_id', 'customer_not_found', 'The customer was not found.');
            } elseif ($customer->status !== CustomerStatus::Active) {
                $issues[] = $this->issue('customer_id', 'customer_inactive', 'Only an active customer can be linked to a quotation.');
            } else {
                $state[] = 'c:'.$customer->getKey().'@'.$customer->updated_at?->format('Y-m-d H:i:s.u');
            }
        }

        [$currentHeader, $proposedHeader] = $update ? $this->updateHeader($quotation, $header) : [null, $this->createHeader($header)];
        $changedFields = $update
            ? array_values(array_filter(self::HEADER_FIELDS, fn ($field) => (string) ($currentHeader[$field] ?? '') !== (string) ($proposedHeader[$field] ?? '')))
            : array_keys(array_filter($proposedHeader, fn ($value) => $value !== null));

        $existing = $update ? $quotation->lines->keyBy(fn (QuotationLine $line) => (int) $line->getKey()) : collect();
        $lines = [];
        $removed = [];
        if (! array_key_exists('items', $payload)) {
            foreach ($existing as $line) $lines[] = $this->unchangedLine($line);
        } elseif ($store !== null) {
            $referenced = [];
            foreach ($payload['items'] as $index => $item) {
                try {
                    $lines[] = $this->evaluateLine($item, $existing, $store, $organizationId, $state);
                    if (isset($item['line_id'])) $referenced[] = (int) $item['line_id'];
                } catch (ModelNotFoundException) {
                    $issues[] = $this->issue("items.{$index}", 'article_not_found', 'The product variant or non-stock article was not found.');
                } catch (ValidationException $exception) {
                    $issues[] = $this->issue("items.{$index}", 'invalid_item', $this->first($exception), $this->errors($exception));
                }
            }
            $removed = $existing->except($referenced)->map(fn (QuotationLine $line) => $this->quotations->line($line))->values()->all();
        }

        $proposedTotals = QuotationTotals::sum(array_map(fn (array $line) => $line['columns'], $lines));
        $presentedLines = array_map(fn (array $line) => [
            'action' => $line['action'],
            ...$this->quotations->line([...$line['columns'], 'line_id' => $line['line_id']]),
            'stock_available' => $line['stock_available'],
        ], $lines);
        $hasChanges = ! $update || $changedFields !== [] || $removed !== []
            || collect($lines)->contains(fn (array $line) => $line['action'] !== 'unchanged');

        $proposedHash = hash('sha256', $this->canonical->canonicalJson([
            'header' => $proposedHeader,
            'lines' => array_map(fn (array $line) => array_diff_key($line, ['stock_available' => true]), $presentedLines),
            'removed' => array_column($removed, 'line_id'),
            'totals' => $proposedTotals,
        ]));
        if ($update) $state[] = 'q:'.$this->quotations->version($quotation);
        sort($state);

        return [
            'store' => $store,
            'customer' => $customer,
            'header' => ['current' => $currentHeader, 'proposed' => $proposedHeader, 'changed_fields' => $changedFields],
            'lines' => $lines,
            'presented_lines' => $presentedLines,
            'removed_lines' => $removed,
            'totals' => [
                'current' => $update ? $this->quotations->present($quotation)['totals'] : null,
                'proposed' => $proposedTotals,
            ],
            'issues' => $issues,
            'has_changes' => $hasChanges,
            'state_hash' => hash('sha256', implode('|', $state)),
            'proposed_hash' => $proposedHash,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload): array
    {
        $update = $payload['mode'] === 'update';
        $quotation = $update ? $this->quotations->find($this->context->organization(), (int) $payload['quotation_id']) : null;
        $evaluation = $this->evaluate($payload, $quotation);
        $scope = self::requiredScope($payload['mode']);

        $fingerprint = null;
        $expiresAt = null;
        if ($evaluation['issues'] === [] && $evaluation['has_changes']) {
            $fingerprint = bin2hex(random_bytes(32));
            $expiresAt = now()->addSeconds((int) config('mcp.preview_ttl_seconds', 300));
            Cache::put($this->previewKey($fingerprint), [
                'organization_id' => (int) $this->context->organization()->getKey(),
                'connection_id' => (string) $this->context->connectionOrFail()->getKey(),
                'mode' => $payload['mode'],
                'quotation_id' => $payload['quotation_id'] ?? null,
                'payload_hash' => $this->payloadHash($payload),
                'state_hash' => $evaluation['state_hash'],
                'proposed_hash' => $evaluation['proposed_hash'],
                'required_scopes' => [$scope],
            ], $expiresAt);
        }

        return [
            'mode' => $payload['mode'],
            'quotation_id' => $payload['quotation_id'] ?? null,
            'store' => $evaluation['store'] ? ['id' => $evaluation['store']->getKey(), 'name' => $evaluation['store']->name] : null,
            'customer' => $evaluation['customer'] ? [
                'id' => $evaluation['customer']->getKey(),
                'display_name' => $evaluation['customer']->display_name,
                'company_name' => $evaluation['customer']->company_name,
                'status' => $evaluation['customer']->status->value,
            ] : null,
            'currency_code' => $quotation?->currency_code ?? strtoupper((string) config('platform.currency_code', 'MAD')),
            'header' => $evaluation['header'],
            'lines' => $evaluation['presented_lines'],
            'removed_lines' => $evaluation['removed_lines'],
            'totals' => $evaluation['totals'],
            'valid' => $evaluation['issues'] === [],
            'issues' => $evaluation['issues'],
            'has_changes' => $evaluation['has_changes'],
            'required_scopes' => [$scope],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt?->toIso8601String(),
            'note' => 'Draft only: nothing is issued, accepted, converted, reserved or invoiced.',
        ];
    }

    /**
     * Create the draft through CreateQuotationAction + SaveQuotationLineAction.
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     * @return array{quotation: Quotation, evaluation: array<string, mixed>}
     */
    public function create(array $payload, string $fingerprint): array
    {
        $evaluation = $this->evaluate($payload);
        $this->verify($payload, $evaluation, $fingerprint);

        $user = $this->context->user();
        $quotation = $this->createQuotation->execute($user, $this->context->organization(), $evaluation['store'], [
            'customer_id' => $evaluation['customer']->getKey(),
            ...$evaluation['header']['proposed'],
        ]);
        foreach ($evaluation['lines'] as $line) {
            $this->saveLine->execute($user, $quotation, $line['data']);
        }
        Cache::forget($this->previewKey($fingerprint));

        return ['quotation' => $this->reload($quotation), 'evaluation' => $evaluation];
    }

    /**
     * Apply a previewed draft update through the existing quotation actions.
     * Must run inside the caller's transaction.
     *
     * @param array<string, mixed> $payload
     * @return array{quotation: Quotation, evaluation: array<string, mixed>}
     */
    public function update(array $payload, string $fingerprint): array
    {
        $quotation = $this->quotations->find($this->context->organization(), (int) $payload['quotation_id'], lock: true);
        if (! $quotation->status->isEditable()) throw new InvalidArgumentException('quotation_not_editable');
        $evaluation = $this->evaluate($payload, $quotation);
        $this->verify($payload, $evaluation, $fingerprint);

        $user = $this->context->user();
        $changed = $evaluation['header']['changed_fields'];
        if ($changed !== []) {
            $data = array_intersect_key($evaluation['header']['proposed'], array_flip($changed));
            if (in_array('customer_id', $changed, true)) $data['sync_customer_snapshot'] = true;
            $this->updateQuotation->execute($user, $quotation, $data);
        }
        $lines = $quotation->lines->keyBy(fn (QuotationLine $line) => (int) $line->getKey());
        foreach ($evaluation['removed_lines'] as $removed) {
            $this->removeLine->execute($user, $quotation, $lines[(int) $removed['line_id']]);
        }
        foreach ($evaluation['lines'] as $line) {
            match ($line['action']) {
                'update' => $this->saveLine->execute($user, $quotation, $line['data'], $lines[(int) $line['line_id']]),
                'add' => $this->saveLine->execute($user, $quotation, $line['data']),
                default => null,
            };
        }
        Cache::forget($this->previewKey($fingerprint));

        return ['quotation' => $this->reload($quotation), 'evaluation' => $evaluation];
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $evaluation */
    private function verify(array $payload, array $evaluation, string $fingerprint): void
    {
        $stored = preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? Cache::get($this->previewKey($fingerprint)) : null;
        if ($evaluation['issues'] !== [] || ! $evaluation['has_changes'] || ! is_array($stored)
            || (int) ($stored['organization_id'] ?? 0) !== (int) $this->context->organization()->getKey()
            || ! hash_equals((string) ($stored['connection_id'] ?? ''), (string) $this->context->connectionOrFail()->getKey())
            || ($stored['mode'] ?? null) !== $payload['mode']
            || ($stored['quotation_id'] ?? null) !== ($payload['quotation_id'] ?? null)
            || ($stored['required_scopes'] ?? null) !== [self::requiredScope($payload['mode'])]
            || ! hash_equals((string) ($stored['payload_hash'] ?? ''), $this->payloadHash($payload))
            || ! hash_equals((string) ($stored['state_hash'] ?? ''), $evaluation['state_hash'])
            || ! hash_equals((string) ($stored['proposed_hash'] ?? ''), $evaluation['proposed_hash'])) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param Collection<int, QuotationLine> $existing
     * @param list<string> $state
     * @return array<string, mixed>
     */
    private function evaluateLine(array $item, Collection $existing, Store $store, int $organizationId, array &$state): array
    {
        $line = null;
        if (isset($item['line_id'])) {
            $line = $existing->get((int) $item['line_id'])
                ?? throw ValidationException::withMessages(['line_id' => 'This line does not belong to the quotation.']);
        }

        $articleGiven = isset($item['variant_id']) || isset($item['non_stock_item_id']);
        $variantId = $articleGiven ? ($item['variant_id'] ?? null) : $line?->product_variant_id;
        $nonStockId = $articleGiven ? ($item['non_stock_item_id'] ?? null) : $line?->non_stock_item_id;
        $sameArticle = $line !== null
            && (int) $variantId === (int) $line->product_variant_id
            && (int) $nonStockId === (int) $line->non_stock_item_id;

        if ($sameArticle && $this->untouched($item, $line)) {
            return $this->unchangedLine($line);
        }

        // An existing line keeps its own price/discount unless explicitly
        // changed; a new article starts from its catalogue default price.
        $mode = $item['price_input_mode'] ?? ($sameArticle ? $line->price_input_mode?->value : null);
        $unitPrice = array_key_exists('unit_price', $item) ? $item['unit_price']
            : ($sameArticle ? $this->linePrice($line, $mode) : null);
        $discountType = $item['discount_type'] ?? ($line ? $line->discount_type->value : 'none');
        $discountValue = array_key_exists('discount_value', $item) ? $item['discount_value']
            : ($line && $discountType === $line->discount_type->value ? (string) $line->discount_value : null);

        $data = [
            'line_type' => $variantId !== null ? QuotationLineType::Catalog->value : QuotationLineType::NonStock->value,
            'product_variant_id' => $variantId !== null ? (int) $variantId : null,
            'non_stock_item_id' => $nonStockId !== null ? (int) $nonStockId : null,
            'quantity' => (string) ($item['quantity'] ?? $line?->quantity),
            'price_input_mode' => $mode,
            'unit_price' => $unitPrice,
            'discount_type' => $discountType,
            'discount_value' => $discountType === 'none' ? null : $discountValue,
        ];
        // Same field rules as the web line editor (an existing library item
        // needs no name, as in SaveQuotationLineRequest::prepareForValidation).
        Validator::make([...$data, 'name' => $data['non_stock_item_id'] ? 'article' : null], (new SaveQuotationLineRequest)->rules())->validate();

        $stockAvailable = null;
        if ($data['line_type'] === QuotationLineType::Catalog->value) {
            $variant = $this->sources->catalogVariant($organizationId, $data['product_variant_id']);
            $columns = $this->pricing->forCatalog($variant, $store, $data);
            $state[] = 'v:'.$variant->getKey().'@'.$variant->updated_at?->format('Y-m-d H:i:s.u').'/p@'.$variant->product->updated_at?->format('Y-m-d H:i:s.u');
            if ($this->context->allows('inventory.view')) {
                $stockAvailable = $this->stock->variant($this->context->organization(), (int) $variant->getKey())['totals']['available'];
            }
        } else {
            $nonStock = $this->sources->nonStockItem($organizationId, $data['non_stock_item_id']);
            $columns = $this->pricing->forNonStockItem($nonStock, $data);
            $state[] = 'n:'.$nonStock->getKey().'@'.$nonStock->updated_at?->format('Y-m-d H:i:s.u');
        }

        return [
            'action' => $line ? 'update' : 'add',
            'line_id' => $line?->getKey(),
            'data' => $data,
            'columns' => $columns,
            'stock_available' => $stockAvailable,
        ];
    }

    /** @return array<string, mixed> */
    private function unchangedLine(QuotationLine $line): array
    {
        return ['action' => 'unchanged', 'line_id' => $line->getKey(), 'data' => null, 'columns' => $line->getAttributes(), 'stock_available' => null];
    }

    /** @param array<string, mixed> $item */
    private function untouched(array $item, QuotationLine $line): bool
    {
        $discountType = $item['discount_type'] ?? $line->discount_type->value;
        foreach (array_intersect_key($item, array_flip(self::LINE_INPUTS)) as $field => $value) {
            if ($field === 'discount_value' && $discountType === 'none') continue;
            $current = match ($field) {
                'price_input_mode' => $line->price_input_mode?->value,
                'discount_type' => $line->discount_type->value,
                'unit_price' => $this->linePrice($line, $item['price_input_mode'] ?? $line->price_input_mode?->value),
                default => $line->{$field},
            };
            $same = in_array($field, ['quantity', 'unit_price', 'discount_value'], true)
                ? $value !== null && $current !== null && Decimal::compare((string) $value, (string) $current) === 0
                : (string) $value === (string) $current;
            if (! $same && ! ($value === null && $current === null)) return false;
        }

        return true;
    }

    private function linePrice(QuotationLine $line, ?string $mode): string
    {
        return (string) ($mode === 'ttc' ? $line->unit_price_incl_tax : $line->unit_price_excl_tax);
    }

    /**
     * Mirrors CreateQuotationAction's defaults so the preview shows the values
     * that will be stored; they are then passed to the action explicitly.
     *
     * @param array<string, mixed> $header
     * @return array<string, mixed>
     */
    private function createHeader(array $header): array
    {
        $defaults = $this->settings->settings($this->context->organization());
        $date = $header['quotation_date'] ?? now()->toDateString();

        return [
            'customer_id' => isset($header['customer_id']) ? (int) $header['customer_id'] : null,
            'quotation_date' => $date,
            'valid_until' => $header['valid_until'] ?? Carbon::parse($date)->addDays($defaults['default_validity_days'])->toDateString(),
            'notes' => $header['notes'] ?? $defaults['default_notes'],
            'terms' => $header['terms'] ?? $defaults['default_terms'],
        ];
    }

    /** @param array<string, mixed> $header @return array{array<string, mixed>, array<string, mixed>} */
    private function updateHeader(Quotation $quotation, array $header): array
    {
        $current = [
            'customer_id' => $quotation->customer_id,
            'quotation_date' => $quotation->quotation_date?->toDateString(),
            'valid_until' => $quotation->valid_until?->toDateString(),
            'notes' => $quotation->notes,
            'terms' => $quotation->terms,
        ];

        return [$current, array_replace($current, $header)];
    }

    private function reload(Quotation $quotation): Quotation
    {
        return $this->quotations->find($this->context->organization(), (int) $quotation->getKey());
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', $this->canonical->canonicalJson($payload));
    }

    private function previewKey(string $fingerprint): string
    {
        return 'mcp:quotation-preview:'.hash('sha256', $fingerprint);
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
