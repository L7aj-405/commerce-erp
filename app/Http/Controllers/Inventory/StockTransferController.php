<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateStockTransferAction;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Support\InventoryQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [StockTransfer::class, $organization]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $transfers = StockTransfer::query()
            ->where('organization_id', $organization->getKey())
            ->with(['sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code', 'performedBy:id,name'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('transfer_number', 'like', "%{$search}%")
                        ->orWhereHas('sourceWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('destinationWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('transferred_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => $transfers,
            'filters' => $filters,
            'can' => [
                'create' => $request->user()->can('create', [StockTransfer::class, $organization]),
            ],
        ]);
    }

    public function create(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [StockTransfer::class, $organization]);

        $filters = $request->validate([
            'source_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
            'destination_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
            'search' => ['nullable', 'string', 'max:255'],
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
        ]);

        $warehouses = Warehouse::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $sourceId = $filters['source_warehouse_id'] ?? null;
        $destinationId = $filters['destination_warehouse_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        $searchResults = collect();

        if ($sourceId) {
            $searchResults = ProductVariant::query()
                ->where('organization_id', $organization->getKey())
                ->where('status', 'active')
                ->with(['product:id,name,brand_id,default_category_id', 'product.brand:id,name', 'product.defaultCategory:id,name'])
                ->with(['inventoryBalances' => fn ($query) => $query
                    ->where('organization_id', $organization->getKey())
                    ->where('warehouse_id', $sourceId)])
                ->whereHas('inventoryBalances', fn ($query) => $query
                    ->where('organization_id', $organization->getKey())
                    ->where('warehouse_id', $sourceId)
                    ->whereRaw('(on_hand - reserved) > 0'))
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('sku', 'like', "%{$search}%")
                            ->orWhere('reference', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%")
                            ->orWhereHas('product', fn ($product) => $product
                                ->where('name', 'like', "%{$search}%")
                                ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', "%{$search}%")));
                    });
                })
                ->orderBy('sku')
                ->limit(12)
                ->get()
                ->map(function (ProductVariant $variant) {
                    $balance = $variant->inventoryBalances->first();

                    return [
                        'id' => $variant->getKey(),
                        'label' => $variant->label,
                        'sku' => $variant->sku,
                        'reference' => $variant->reference,
                        'barcode' => $variant->barcode,
                        'product' => [
                            'id' => $variant->product->getKey(),
                            'name' => $variant->product->name,
                            'brand' => $variant->product->brand?->only(['id', 'name']),
                            'category' => $variant->product->defaultCategory?->only(['id', 'name']),
                        ],
                        'availability' => [
                            'on_hand' => $balance?->on_hand ?? InventoryQuantity::ZERO,
                            'reserved' => $balance?->reserved ?? InventoryQuantity::ZERO,
                            'available' => $balance?->available ?? InventoryQuantity::ZERO,
                        ],
                    ];
                });
        }

        $preselectedVariant = null;

        if ($filters['variant_id'] ?? null) {
            $variant = ProductVariant::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($filters['variant_id'])
                ->with(['product:id,name,brand_id', 'product.brand:id,name'])
                ->first();

            if ($variant) {
                $preselectedVariant = [
                    'id' => $variant->getKey(),
                    'label' => $variant->label,
                    'sku' => $variant->sku,
                    'reference' => $variant->reference,
                    'product' => [
                        'id' => $variant->product->getKey(),
                        'name' => $variant->product->name,
                        'brand' => $variant->product->brand?->only(['id', 'name']),
                    ],
                ];
            }
        }

        return Inertia::render('Inventory/Transfers/Create', [
            'filters' => $filters,
            'warehouses' => $warehouses,
            'searchResults' => $searchResults,
            'preselectedVariant' => $preselectedVariant,
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, CreateStockTransferAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [StockTransfer::class, $organization]);

        $data = $request->validate([
            'source_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
            'destination_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
            'reason' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $source = Warehouse::query()->where('organization_id', $organization->getKey())->whereKey($data['source_warehouse_id'])->firstOrFail();
        $destination = Warehouse::query()->where('organization_id', $organization->getKey())->whereKey($data['destination_warehouse_id'])->firstOrFail();
        $variants = ProductVariant::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', collect($data['lines'])->pluck('product_variant_id'))
            ->get()
            ->keyBy('id');

        $transfer = $action->execute(
            $request->user(),
            $organization,
            $source,
            $destination,
            collect($data['lines'])->map(fn (array $line) => [
                'variant' => $variants[$line['product_variant_id']],
                'quantity' => $line['quantity'],
            ])->values()->all(),
            $data['reason'],
        );

        return redirect()
            ->route('inventory.transfers.show', $transfer)
            ->with('success', 'Transfert effectue avec succes.');
    }

    public function show(StockTransfer $transfer): Response
    {
        $this->authorize('view', $transfer);

        return Inertia::render('Inventory/Transfers/Show', [
            'transfer' => $transfer->load([
                'sourceWarehouse:id,name,code',
                'destinationWarehouse:id,name,code',
                'performedBy:id,name',
                'lines.productVariant:id,product_id,label,sku',
                'lines.productVariant.product:id,name',
            ]),
        ]);
    }
}
