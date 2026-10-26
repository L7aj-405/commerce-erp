<?php

namespace App\Services\Mcp\Sales;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\CustomerFinancialSummary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class CustomerReadService
{
    public function __construct(private readonly CustomerFinancialSummary $summary) {}

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
        if ($status !== null && CustomerStatus::tryFrom((string) $status) === null) throw new InvalidArgumentException('invalid_status');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');

        $customers = Customer::query()
            ->where('organization_id', $organization->getKey())
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('display_name', 'like', $like)->orWhere('company_name', 'like', $like)
                    ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
                    ->orWhere('whatsapp_username', 'like', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('display_name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($customers->items())->map(fn (Customer $customer) => [
                'id' => $customer->getKey(),
                'type' => $customer->type->value,
                'display_name' => $customer->display_name,
                'company_name' => $customer->company_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'status' => $customer->status->value,
            ])->values()->all(),
            'pagination' => [
                'page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'has_more' => $customers->hasMorePages(),
                'next_page' => $customers->hasMorePages() ? $customers->currentPage() + 1 : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function customer(Organization $organization, int $customerId): array
    {
        $customer = $this->find($organization, $customerId);

        // Internal notes are deliberately not exposed.
        return [
            'id' => $customer->getKey(),
            'type' => $customer->type->value,
            'display_name' => $customer->display_name,
            'company_name' => $customer->company_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'whatsapp_username' => $customer->whatsapp_username,
            'tax_identifier' => $customer->tax_identifier,
            'billing_address' => $customer->billing_address,
            'status' => $customer->status->value,
            'can_be_quoted' => $customer->status === CustomerStatus::Active,
            'version' => $this->version($customer),
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function balance(Organization $organization, int $customerId, User $viewer): array
    {
        $customer = $this->find($organization, $customerId);

        return [
            'customer' => ['id' => $customer->getKey(), 'display_name' => $customer->display_name],
            'currency_code' => config('platform.currency_code', 'MAD'),
            ...$this->summary->forCustomer($customer, $viewer),
            'definitions' => [
                'scope' => 'Only documents of the stores the connection user may access; a figure is null when the user lacks its view permission.',
                'sales_total' => 'Sum of confirmed sales orders (TTC).',
                'invoiced_total' => 'Sum of issued invoices (TTC).',
                'paid_total' => 'Sum of posted payment allocations on this customer\'s sales orders.',
                'outstanding' => 'invoiced_total minus paid_total, floored at zero.',
                'last_activity' => 'Most recent sales order date.',
            ],
        ];
    }

    public function find(Organization $organization, int $customerId, bool $lock = false): Customer
    {
        if ($customerId < 1) throw new InvalidArgumentException('invalid_id');

        return Customer::query()->where('organization_id', $organization->getKey())->whereKey($customerId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw (new ModelNotFoundException)->setModel(Customer::class, [$customerId]);
    }

    /** Version token over every editable field (notes included) and the row timestamp. */
    public function version(Customer $customer): string
    {
        $fields = $customer->only(['display_name', 'company_name', 'email', 'phone', 'whatsapp_username', 'tax_identifier', 'billing_address', 'notes']);
        $fields['type'] = $customer->type?->value;
        $fields['status'] = $customer->status?->value;
        ksort($fields);

        return hash('sha256', $customer->getKey().'|'.$customer->updated_at?->format('Y-m-d H:i:s.u').'|'.json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
