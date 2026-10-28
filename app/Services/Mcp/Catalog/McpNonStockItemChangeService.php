<?php

namespace App\Services\Mcp\Catalog;

use App\Actions\Catalog\CreateNonStockItemAction;
use App\Actions\Catalog\UpdateNonStockItemAction;
use App\Http\Requests\Catalog\StoreNonStockItemRequest;
use App\Http\Requests\Catalog\UpdateNonStockItemRequest;
use App\Models\NonStockItem;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Non-stock article create/update over MCP. Candidate data is validated with
 * the web form request rules, and the preview runs the real
 * CreateNonStockItemAction / UpdateNonStockItemAction inside a rolled-back
 * transaction, so HT/TTC and tax resolution are exactly the ERP's. Execution
 * goes through the same actions (which own the non_stock_item.* audit). A
 * non-stock article is NEVER a catalogue Product and never gets stock.
 */
final class McpNonStockItemChangeService
{
    public const CREATE_FIELDS = ['name', 'reference', 'unit_label', 'price_input_mode', 'unit_price', 'tax_rate_id'];

    public const UPDATE_FIELDS = [...self::CREATE_FIELDS, 'status'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly NonStockItemReadService $items,
        private readonly CreateNonStockItemAction $createAction,
        private readonly UpdateNonStockItemAction $updateAction,
        private readonly McpPartyPreviews $previews,
        private readonly McpDryRun $dryRun,
    ) {}

    public static function requiredScope(string $mode): string
    {
        return $mode === 'update' ? 'non_stock_items.update' : 'non_stock_items.create';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(string $mode, array $data, ?int $itemId = null): array
    {
        $update = $mode === 'update';
        if (array_diff(array_keys($data), $update ? self::UPDATE_FIELDS : self::CREATE_FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        if ($update && ($itemId === null || $itemId < 1)) throw new InvalidArgumentException('invalid_id');
        if ($update && $data === []) throw new InvalidArgumentException('no_changes');

        $changes = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $data);
        ksort($changes);

        return ['mode' => $update ? 'update' : 'create', 'item_id' => $update ? $itemId : null, 'changes' => $changes];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $item = $payload['mode'] === 'update' ? $this->items->find($this->context->organization(), (int) $payload['item_id']) : null;
        $evaluation = $this->evaluate($payload, $item);

        [$fingerprint, $expiresAt] = $evaluation['issues'] === []
            ? $this->previews->issue('non_stock_item', $this->binding($payload, $evaluation))
            : [null, null];

        return [
            'mode' => $payload['mode'],
            'item_id' => $payload['item_id'],
            'current' => $item ? $this->items->present($item) : null,
            'proposed' => $evaluation['proposed'],
            'existing_item' => $evaluation['existing'],
            'possible_duplicates' => $evaluation['possible_duplicates'],
            'valid' => $evaluation['issues'] === [],
            'issues' => $evaluation['issues'],
            'required_scopes' => [self::requiredScope($payload['mode'])],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => $payload['mode'] === 'create'
                ? ($evaluation['existing'] !== null
                    ? 'An article with this exact name already exists: create_non_stock_item will return that existing article (ERP rule) instead of creating a duplicate. Use it directly.'
                    : 'Creates one reusable non-stock (external) article for quotations. It is NOT a catalogue Product and has no stock; promoting it to the catalogue is a separate ERP workflow.')
                : 'Updates the reusable non-stock article. Existing quotation lines keep their own price/label snapshots.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     * @return array{item: NonStockItem, reused: bool}
     */
    public function create(array $payload, string $fingerprint): array
    {
        $evaluation = $this->evaluate($payload, null);

        $item = $this->apply('create', $payload, $evaluation, $fingerprint,
            fn () => $this->createAction->execute($this->context->user(), $this->context->organization(), $payload['changes']));

        return ['item' => $item, 'reused' => ! $item->wasRecentlyCreated];
    }

    /** Must run inside the caller's transaction (row lock). @param array<string, mixed> $payload */
    public function update(array $payload, string $fingerprint): NonStockItem
    {
        $item = $this->items->find($this->context->organization(), (int) $payload['item_id'], lock: true);
        $evaluation = $this->evaluate($payload, $item);

        return $this->apply('update', $payload, $evaluation, $fingerprint,
            fn () => $this->updateAction->execute($this->context->user(), $item, $payload['changes'])->refresh());
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evaluation
     * @param callable(): NonStockItem $action
     */
    private function apply(string $mode, array $payload, array $evaluation, string $fingerprint, callable $action): NonStockItem
    {
        if ($evaluation['issues'] !== [] || $payload['mode'] !== $mode) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        $binding = $this->binding($payload, $evaluation);
        $this->previews->consume('non_stock_item', $fingerprint, $binding);

        try {
            return $action();
        } catch (Throwable $exception) {
            $this->previews->release('non_stock_item', $fingerprint, $binding);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function evaluate(array $payload, ?NonStockItem $item): array
    {
        $organization = $this->context->organization();
        $changes = $payload['changes'];
        $rules = $item ? (new UpdateNonStockItemRequest)->rules() : (new StoreNonStockItemRequest)->rules();

        $issues = [];
        foreach (Validator::make($changes, $rules)->errors()->messages() as $field => $messages) {
            $issues[] = ['path' => "non_stock_item.{$field}", 'code' => 'invalid', 'message' => mb_substr((string) $messages[0], 0, 300)];
        }

        $name = is_string($changes['name'] ?? null) ? $changes['name'] : null;
        $existing = null;
        if ($issues === [] && $name !== null) {
            $sameName = NonStockItem::query()->where('organization_id', $organization->getKey())->where('name', $name)
                ->when($item, fn ($query) => $query->whereKeyNot($item->getKey()))->first();
            if ($sameName && $item) {
                $issues[] = ['path' => 'non_stock_item.name', 'code' => 'duplicate', 'message' => 'Another non-stock article already uses this name.'];
            } elseif ($sameName) {
                $existing = $this->items->present($sameName);
            }
        }

        $proposed = null;
        if ($issues === [] && $existing === null) {
            // Exact ERP outcome (tax resolution, HT/TTC), never persisted.
            try {
                $proposed = $this->dryRun->run(fn () => $this->items->present($item
                    ? $this->updateAction->execute($this->context->user(), $item, $changes)->refresh()
                    : $this->createAction->execute($this->context->user(), $organization, $changes)));
                unset($proposed['id'], $proposed['usage_count']);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $issues[] = ['path' => "non_stock_item.{$field}", 'code' => 'invalid', 'message' => mb_substr((string) ($messages[0] ?? 'Invalid.'), 0, 300)];
                }
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $issues[] = ['path' => 'non_stock_item.tax_rate_id', 'code' => 'not_found', 'message' => 'The tax rate does not exist in this organization.'];
            }
        }

        return [
            'proposed' => $proposed,
            'existing' => $existing,
            'possible_duplicates' => $issues === [] && ! $item ? $this->duplicates($changes, $existing) : [],
            'issues' => $issues,
            'state_hash' => $item ? $this->items->version($item) : 'new:'.($existing['id'] ?? '-'),
            'proposed_hash' => $this->previews->hash($proposed ?? $existing ?? []),
        ];
    }

    /**
     * Same reference, or a name containing the requested one. Informational.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed>|null $existing
     * @return list<array<string, mixed>>
     */
    private function duplicates(array $changes, ?array $existing): array
    {
        $name = is_string($changes['name'] ?? null) ? $changes['name'] : null;
        $reference = is_string($changes['reference'] ?? null) ? $changes['reference'] : null;
        if ($name === null && $reference === null) return [];

        return NonStockItem::query()
            ->where('organization_id', $this->context->organization()->getKey())
            ->when($existing, fn ($query) => $query->whereKeyNot($existing['id']))
            ->where(fn ($query) => $query
                ->when($reference !== null, fn ($query) => $query->orWhere('reference', $reference))
                ->when($name !== null, fn ($query) => $query->orWhere('name', 'like', '%'.addcslashes($name, '\\%_').'%')))
            ->orderBy('id')->limit(5)->get()
            ->map(fn (NonStockItem $found) => $this->items->present($found))->all();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evaluation
     * @return array<string, mixed>
     */
    private function binding(array $payload, array $evaluation): array
    {
        return [
            'entity' => 'non_stock_item',
            'mode' => $payload['mode'],
            'target_id' => $payload['item_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $evaluation['state_hash'],
            'proposed_hash' => $evaluation['proposed_hash'],
            'required_scopes' => [self::requiredScope($payload['mode'])],
        ];
    }
}
