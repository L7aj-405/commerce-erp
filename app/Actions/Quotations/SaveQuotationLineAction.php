<?php

namespace App\Actions\Quotations;

use App\Actions\Catalog\CreateNonStockItemAction;
use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Enums\CatalogStatus;
use App\Enums\QuotationLineType;
use App\Models\NonStockItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\QuotationLinePricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add or update ONE Devis line. Handles all three article sources:
 *   - catalog  : a real ProductVariant
 *   - non_stock: an existing NonStockItem from the reusable library
 *   - non_stock: a brand-new article, which is persisted to the library first
 *
 * Every money field is (re)calculated server-side by QuotationLinePricing. The
 * browser's line/quotation totals are never trusted.
 */
class SaveQuotationLineAction
{
    use MutatesQuotation;

    public function __construct(
        private readonly QuotationLinePricing $pricing,
        private readonly CreateNonStockItemAction $createNonStock,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Quotation $quotation, array $data, ?QuotationLine $line = null): QuotationLine
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.update');

        return DB::transaction(function () use ($actor, $quotation, $data, $line) {
            $quotation = $this->lockDraft($quotation);
            if ($line) {
                $line = $this->lockLine($quotation, $line);
            }

            $type = QuotationLineType::from($data['line_type']);
            $previousNonStockId = $line?->non_stock_item_id;

            if ($type === QuotationLineType::Catalog) {
                $variant = ProductVariant::query()
                    ->where('organization_id', $quotation->organization_id)
                    ->whereKey($data['product_variant_id'])
                    ->with(['product.defaultUnit', 'taxRate'])
                    ->firstOrFail();
                if ($variant->status !== CatalogStatus::Active || $variant->product->status !== CatalogStatus::Active) {
                    throw ValidationException::withMessages(['product_variant_id' => 'Seuls les produits actifs du catalogue peuvent être ajoutés.']);
                }
                $columns = $this->pricing->forCatalog($variant, $quotation->store, $data);
                $usedNonStock = null;
            } else {
                $item = $this->resolveNonStockItem($actor, $quotation, $data);
                $columns = $this->pricing->forNonStockItem($item, $data);
                $usedNonStock = $item;
            }

            $line ??= new QuotationLine;
            $line->organization_id = $quotation->organization_id;
            $line->quotation_id = $quotation->getKey();
            $line->position ??= ((int) $quotation->lines()->max('position')) + 1;
            foreach ($columns as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();

            $this->syncUsageCounts($previousNonStockId, $usedNonStock);
            $this->reconcileTotals($quotation);

            $this->audit->record('quotation.updated', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                'line_saved' => $line->getKey(),
                'line_type' => $type->value,
                'quantity' => $line->quantity,
                'unit_price_excl_tax' => $line->unit_price_excl_tax,
                'total_incl_tax' => $line->total_incl_tax,
                'quotation_total_incl_tax' => $quotation->total_incl_tax,
            ]);

            return $line->load(['productVariant', 'nonStockItem']);
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveNonStockItem(User $actor, Quotation $quotation, array $data): NonStockItem
    {
        if (! empty($data['non_stock_item_id'])) {
            $item = NonStockItem::query()
                ->where('organization_id', $quotation->organization_id)
                ->whereKey($data['non_stock_item_id'])
                ->firstOrFail();
            if ($item->status !== CatalogStatus::Active) {
                throw ValidationException::withMessages(['non_stock_item_id' => 'Cet article hors stock est désactivé.']);
            }

            return $item;
        }

        // Brand-new article: persist to the reusable library first, so it does
        // not disappear after this one Devis.
        return $this->createNonStock->execute($actor, $quotation->organization, [
            'name' => $data['name'] ?? $data['description'] ?? '',
            'reference' => $data['reference'] ?? null,
            'unit_label' => $data['unit_label'] ?? null,
            'price_input_mode' => $data['price_input_mode'] ?? 'ht',
            'unit_price' => $data['unit_price'] ?? null,
            'tax_rate_id' => $data['tax_rate_id'] ?? null,
        ]);
    }

    private function syncUsageCounts(?int $previousNonStockId, ?NonStockItem $used): void
    {
        if ($previousNonStockId === ($used?->getKey())) {
            return;
        }
        if ($previousNonStockId) {
            NonStockItem::query()->whereKey($previousNonStockId)->where('usage_count', '>', 0)->decrement('usage_count');
        }
        if ($used) {
            NonStockItem::query()->whereKey($used->getKey())->increment('usage_count');
        }
    }
}
