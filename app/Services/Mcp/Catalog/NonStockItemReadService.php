<?php

namespace App\Services\Mcp\Catalog;

use App\Models\NonStockItem;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Organization-scoped reads of the reusable non-stock (external) article
 * library. These articles are never catalogue Products and have no stock.
 */
final class NonStockItemReadService
{
    /**
     * @param array{query?: string|null, status?: string|null, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters): array
    {
        $queryText = trim((string) ($filters['query'] ?? ''));
        $status = $filters['status'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);

        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($status !== null && ! in_array($status, ['active', 'inactive'], true)) throw new InvalidArgumentException('invalid_status');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');

        $items = NonStockItem::query()
            ->where('organization_id', $organization->getKey())
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('reference', 'like', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('name')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($items->items())->map(fn (NonStockItem $item) => $this->present($item))->values()->all(),
            'pagination' => [
                'page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'has_more' => $items->hasMorePages(),
                'next_page' => $items->hasMorePages() ? $items->currentPage() + 1 : null,
            ],
        ];
    }

    public function find(Organization $organization, int $itemId, bool $lock = false): NonStockItem
    {
        if ($itemId < 1) throw new InvalidArgumentException('invalid_id');

        $query = NonStockItem::query()->where('organization_id', $organization->getKey())->whereKey($itemId);
        if ($lock) $query->lockForUpdate();

        return $query->first() ?? throw (new ModelNotFoundException)->setModel(NonStockItem::class, [$itemId]);
    }

    /** @return array<string, mixed> */
    public function item(Organization $organization, int $itemId): array
    {
        $item = $this->find($organization, $itemId);

        return [...$this->present($item), 'version' => $this->version($item)];
    }

    /** @return array<string, mixed> */
    public function present(NonStockItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'name' => $item->name,
            'reference' => $item->reference,
            'unit_label' => $item->unit_label,
            'price_input_mode' => $item->price_input_mode?->value,
            'default_price_excl_tax' => $item->default_price_excl_tax === null ? null : (string) $item->default_price_excl_tax,
            'default_price_incl_tax' => $item->default_price_incl_tax === null ? null : (string) $item->default_price_incl_tax,
            'tax_rate_id' => $item->tax_rate_id,
            'tax_name' => $item->tax_name,
            'tax_rate' => (string) $item->tax_rate,
            'status' => $item->status?->value,
            'usage_count' => (int) $item->usage_count,
            'is_catalog_product' => false,
        ];
    }

    public function version(NonStockItem $item): string
    {
        return hash('sha256', $item->getKey().'|'.$item->updated_at?->format('Y-m-d H:i:s.u'));
    }
}
