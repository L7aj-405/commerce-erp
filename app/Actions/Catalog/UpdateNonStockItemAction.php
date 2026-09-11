<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\PriceInputMode;
use App\Models\NonStockItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PriceCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateNonStockItemAction
{
    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, NonStockItem $item, array $data): NonStockItem
    {
        return DB::transaction(function () use ($actor, $item, $data) {
            $item = NonStockItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
                $item->name = trim((string) $data['name']);
            }
            if (array_key_exists('reference', $data)) {
                $item->reference = filled($data['reference']) ? trim((string) $data['reference']) : null;
            }
            if (array_key_exists('unit_label', $data)) {
                $item->unit_label = filled($data['unit_label']) ? trim((string) $data['unit_label']) : null;
            }
            if (array_key_exists('status', $data)) {
                $item->status = CatalogStatus::from($data['status']);
            }

            $priceTouched = array_key_exists('unit_price', $data) || array_key_exists('price_input_mode', $data) || array_key_exists('tax_rate_id', $data);
            if ($priceTouched) {
                [$rate, $name] = array_key_exists('tax_rate_id', $data)
                    ? $this->tax($item, $data['tax_rate_id'])
                    : [Decimal::normalize((string) $item->tax_rate), $item->tax_name];
                $mode = PriceInputMode::from($data['price_input_mode'] ?? $item->price_input_mode->value);
                $entered = array_key_exists('unit_price', $data) && $data['unit_price'] !== null && $data['unit_price'] !== ''
                    ? Decimal::nonNegative((string) $data['unit_price'], 'unit_price')
                    : null;

                if ($entered !== null) {
                    if ($mode === PriceInputMode::Ttc) {
                        $item->default_price_incl_tax = $entered;
                        $item->default_price_excl_tax = $this->prices->exclusive($entered, $rate);
                    } else {
                        $item->default_price_excl_tax = $entered;
                        $item->default_price_incl_tax = $this->prices->inclusive($entered, $rate);
                    }
                }
                $item->price_input_mode = $mode;
                $item->tax_rate = $rate;
                $item->tax_name = $name;
                if (array_key_exists('tax_rate_id', $data)) {
                    $item->tax_rate_id = $rate === '0.0000' ? null : $data['tax_rate_id'];
                }
            }

            $item->save();

            $this->audit->record('non_stock_item.updated', $actor, $item->organization, null, $item, newValues: [
                'updated_fields' => array_keys($data),
                'status' => $item->status->value,
            ]);

            return $item;
        });
    }

    /** @return array{string, string|null} */
    private function tax(NonStockItem $item, mixed $taxRateId): array
    {
        if (! $taxRateId) {
            return ['0.0000', null];
        }
        $rate = TaxRate::query()->where('organization_id', $item->organization_id)->whereKey($taxRateId)->firstOrFail();
        if ($rate->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['tax_rate_id' => 'Seul un taux de TVA actif peut être sélectionné.']);
        }

        return [Decimal::normalize((string) $rate->rate), $rate->name];
    }
}
