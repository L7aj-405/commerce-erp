<?php

namespace App\Services\Mcp\Sales;

use App\Models\Store;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Quotations are store-scoped while an MCP connection is organisation-scoped.
 * The connection user may act in the active stores of the connection
 * organisation they are a member of — the same store-membership rule the
 * quotation actions enforce. No browser active store is ever consulted.
 */
final class McpSalesStoreAccess
{
    public function __construct(private readonly McpExecutionContext $context) {}

    /** @return Collection<int, Store> */
    public function stores(): Collection
    {
        $connection = $this->context->connectionOrFail();

        return Store::query()
            ->where('organization_id', $connection->organization_id)
            ->where('status', 'active')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $connection->user_id))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'organization_id', 'name', 'status']);
    }

    /** @return list<int> */
    public function storeIds(): array
    {
        return $this->stores()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The explicit store, or the user's only store when omitted. */
    public function resolve(?int $storeId): Store
    {
        $stores = $this->stores();
        if ($storeId === null) {
            if ($stores->count() === 1) {
                return $stores->first();
            }
            throw ValidationException::withMessages(['store_id' => $stores->isEmpty()
                ? 'The connection user is not a member of any active store.'
                : 'store_id is required: the connection user belongs to several stores.']);
        }

        return $stores->firstWhere('id', $storeId)
            ?? throw ValidationException::withMessages(['store_id' => 'The store is not accessible to this connection.']);
    }
}
