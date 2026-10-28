<?php

namespace App\Services\Mcp\Procurement;

use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Organization-scoped supplier reads for MCP. Same searchable fields as the
 * web supplier directory (name, contact person, phone, email). Internal notes
 * are not part of reads.
 */
final class SupplierReadService
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

        $suppliers = Supplier::query()
            ->where('organization_id', $organization->getKey())
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('contact_person', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('active', $status === 'active'))
            ->orderBy('name')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($suppliers->items())->map(fn (Supplier $supplier) => $this->summary($supplier))->values()->all(),
            'pagination' => [
                'page' => $suppliers->currentPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
                'has_more' => $suppliers->hasMorePages(),
                'next_page' => $suppliers->hasMorePages() ? $suppliers->currentPage() + 1 : null,
            ],
        ];
    }

    public function find(Organization $organization, int $supplierId, bool $lock = false): Supplier
    {
        if ($supplierId < 1) throw new InvalidArgumentException('invalid_id');

        $query = Supplier::query()->where('organization_id', $organization->getKey())->whereKey($supplierId);
        if ($lock) $query->lockForUpdate();

        return $query->first() ?? throw (new ModelNotFoundException)->setModel(Supplier::class, [$supplierId]);
    }

    /** @return array<string, mixed> */
    public function supplier(Organization $organization, int $supplierId): array
    {
        $supplier = $this->find($organization, $supplierId);

        return [
            ...$this->summary($supplier),
            'address' => $supplier->address,
            'created_at' => $supplier->created_at?->toIso8601String(),
            'updated_at' => $supplier->updated_at?->toIso8601String(),
            'version' => $this->version($supplier),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Supplier $supplier): array
    {
        return [
            'id' => $supplier->getKey(),
            'name' => $supplier->name,
            'contact_person' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'status' => $supplier->active ? 'active' : 'inactive',
        ];
    }

    public function version(Supplier $supplier): string
    {
        return hash('sha256', $supplier->getKey().'|'.$supplier->updated_at?->format('Y-m-d H:i:s.u'));
    }
}
