<?php

namespace App\Services\Mcp\Sales;

use App\Enums\QuotationStatus;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class QuotationReadService
{
    public function __construct(private readonly McpSalesStoreAccess $stores) {}

    /**
     * @param array{query?: string|null, status?: string|null, customer_id?: int|null, store_id?: int|null, date_from?: string|null, date_to?: string|null, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters): array
    {
        $queryText = trim((string) ($filters['query'] ?? ''));
        $status = $filters['status'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($status !== null && QuotationStatus::tryFrom((string) $status) === null) throw new InvalidArgumentException('invalid_status');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) throw new InvalidArgumentException('invalid_date');
        }

        $storeIds = $this->stores->storeIds();
        if (isset($filters['store_id'])) {
            $storeIds = in_array((int) $filters['store_id'], $storeIds, true) ? [(int) $filters['store_id']] : [];
        }

        $quotations = Quotation::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $storeIds)
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('quotation_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)->orWhere('customer_company', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)->orWhere('customer_email', 'like', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(isset($filters['customer_id']), fn ($query) => $query->where('customer_id', (int) $filters['customer_id']))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('quotation_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('quotation_date', '<=', $dateTo))
            ->with(['store:id,name'])
            ->orderByDesc('quotation_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($quotations->items())->map(fn (Quotation $quotation) => [
                'id' => $quotation->getKey(),
                'quotation_number' => $quotation->quotation_number,
                'status' => $quotation->status->value,
                'revision_number' => (int) $quotation->revision_number,
                'store' => ['id' => $quotation->store_id, 'name' => $quotation->store?->name],
                'customer_id' => $quotation->customer_id,
                'customer_name' => $quotation->customer_name,
                'customer_company' => $quotation->customer_company,
                'quotation_date' => $quotation->quotation_date?->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'currency_code' => $quotation->currency_code,
                'total_incl_tax' => (string) $quotation->total_incl_tax,
            ])->values()->all(),
            'pagination' => [
                'page' => $quotations->currentPage(),
                'per_page' => $quotations->perPage(),
                'total' => $quotations->total(),
                'has_more' => $quotations->hasMorePages(),
                'next_page' => $quotations->hasMorePages() ? $quotations->currentPage() + 1 : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function quotation(Organization $organization, int $quotationId): array
    {
        return $this->present($this->find($organization, $quotationId));
    }

    /** Organisation + accessible-store scoped lookup; anything else is "not found". */
    public function find(Organization $organization, int $quotationId, bool $lock = false): Quotation
    {
        if ($quotationId < 1) throw new InvalidArgumentException('invalid_id');

        $query = Quotation::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('store_id', $this->stores->storeIds())
            ->whereKey($quotationId);
        if ($lock) $query->lockForUpdate();

        // Full store row: line pricing resolves the store default tax rate.
        return $query->with(['lines', 'store'])->first()
            ?? throw (new ModelNotFoundException)->setModel(Quotation::class, [$quotationId]);
    }

    /** Version token covering the header row and every line row. */
    public function version(Quotation $quotation): string
    {
        $lines = $quotation->lines->map(fn (QuotationLine $line) => $line->getKey().'@'.$line->updated_at?->format('Y-m-d H:i:s.u'))->all();

        return hash('sha256', $quotation->getKey().'|'.$quotation->status->value.'|'.$quotation->updated_at?->format('Y-m-d H:i:s.u').'|'.implode(',', $lines));
    }

    /** @return array<string, mixed> */
    public function present(Quotation $quotation): array
    {
        // seller_snapshot (logo data, legal profile) is intentionally omitted.
        return [
            'id' => $quotation->getKey(),
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status->value,
            'editable' => $quotation->status->isEditable(),
            'revision_number' => (int) $quotation->revision_number,
            'store' => ['id' => $quotation->store_id, 'name' => $quotation->store?->name],
            'customer' => [
                'id' => $quotation->customer_id,
                'name' => $quotation->customer_name,
                'company' => $quotation->customer_company,
                'email' => $quotation->customer_email,
                'phone' => $quotation->customer_phone,
                'tax_identifier' => $quotation->customer_tax_identifier,
                'billing_address' => $quotation->billing_address,
            ],
            'quotation_date' => $quotation->quotation_date?->toDateString(),
            'valid_until' => $quotation->valid_until?->toDateString(),
            'is_past_validity' => $quotation->isPastValidity(),
            'currency_code' => $quotation->currency_code,
            'representative_name' => $quotation->representative_name,
            'notes' => $quotation->notes,
            'terms' => $quotation->terms,
            'lines' => $quotation->lines->map(fn (QuotationLine $line) => $this->line($line))->values()->all(),
            'totals' => [
                'subtotal_excl_tax' => (string) $quotation->subtotal_excl_tax,
                'discount_total' => (string) $quotation->discount_total,
                'tax_total' => (string) $quotation->tax_total,
                'total_incl_tax' => (string) $quotation->total_incl_tax,
            ],
            'version' => $this->version($quotation),
            'created_at' => $quotation->created_at?->toIso8601String(),
            'updated_at' => $quotation->updated_at?->toIso8601String(),
        ];
    }

    /** @param QuotationLine|array<string, mixed> $line @return array<string, mixed> */
    public function line(QuotationLine|array $line): array
    {
        $value = fn (string $key) => data_get($line, $key);
        $enum = fn (string $key) => ($v = $value($key)) instanceof \BackedEnum ? $v->value : $v;
        $decimal = fn (string $key) => $value($key) === null ? null : (string) $value($key);

        return [
            'line_id' => $line instanceof QuotationLine ? $line->getKey() : ($line['line_id'] ?? null),
            'position' => $value('position'),
            'line_type' => $enum('line_type'),
            'product_variant_id' => $value('product_variant_id'),
            'non_stock_item_id' => $value('non_stock_item_id'),
            'product_name' => $value('product_name'),
            'variant_name' => $value('variant_name'),
            'sku' => $value('sku'),
            'reference' => $value('reference'),
            'unit_label' => $value('unit_label'),
            'quantity' => $decimal('quantity'),
            'price_input_mode' => $enum('price_input_mode'),
            'unit_price_excl_tax' => $decimal('unit_price_excl_tax'),
            'unit_price_incl_tax' => $decimal('unit_price_incl_tax'),
            'discount_type' => $enum('discount_type'),
            'discount_value' => $decimal('discount_value'),
            'subtotal_excl_tax' => $decimal('subtotal_excl_tax'),
            'discount_amount' => $decimal('discount_amount'),
            'taxable_amount' => $decimal('taxable_amount'),
            'tax_name' => $value('tax_name'),
            'tax_rate' => $decimal('tax_rate'),
            'tax_amount' => $decimal('tax_amount'),
            'total_incl_tax' => $decimal('total_incl_tax'),
        ];
    }
}
