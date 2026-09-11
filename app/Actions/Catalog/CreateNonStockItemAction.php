<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\PriceInputMode;
use App\Models\NonStockItem;
use App\Models\Organization;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PriceCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persist a manually quoted article into the organisation-scoped reusable
 * non-stock library. This NEVER creates an Inventory Product, an
 * InventoryBalance, opening stock or a reservation — the item is a commercial
 * catalogue candidate only.
 */
class CreateNonStockItemAction
{
    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, reference?: string|null, unit_label?: string|null, price_input_mode?: string|null, unit_price?: string|null, tax_rate_id?: int|null}  $data
     */
    public function execute(User $actor, Organization $organization, array $data): NonStockItem
    {
        return DB::transaction(function () use ($actor, $organization, $data) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'La désignation est requise.']);
            }

            $existing = NonStockItem::query()
                ->where('organization_id', $organization->getKey())
                ->where('name', $name)
                ->first();
            if ($existing) {
                // Idempotent: quoting the same article name again reuses the row
                // instead of colliding with the (organization_id, name) unique key.
                return $existing;
            }

            [$taxRate, $taxName] = $this->tax($organization, $data['tax_rate_id'] ?? null);
            $mode = PriceInputMode::from($data['price_input_mode'] ?? 'ht');
            $entered = isset($data['unit_price']) && $data['unit_price'] !== null && $data['unit_price'] !== ''
                ? Decimal::nonNegative((string) $data['unit_price'], 'unit_price')
                : null;

            $ht = null;
            $ttc = null;
            if ($entered !== null) {
                if ($mode === PriceInputMode::Ttc) {
                    $ttc = $entered;
                    $ht = $this->prices->exclusive($entered, $taxRate);
                } else {
                    $ht = $entered;
                    $ttc = $this->prices->inclusive($entered, $taxRate);
                }
            }

            $item = new NonStockItem;
            $item->organization_id = $organization->getKey();
            $item->name = $name;
            $item->reference = filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null;
            $item->unit_label = filled($data['unit_label'] ?? null) ? trim((string) $data['unit_label']) : null;
            $item->price_input_mode = $mode;
            $item->default_price_excl_tax = $ht;
            $item->default_price_incl_tax = $ttc;
            $item->tax_rate_id = $taxRate === '0.0000' ? null : ($data['tax_rate_id'] ?? null);
            $item->tax_name = $taxName;
            $item->tax_rate = $taxRate;
            $item->status = CatalogStatus::Active;
            $item->usage_count = 0;
            $item->created_by_user_id = $actor->getKey();
            $item->save();

            $this->audit->record('non_stock_item.created', $actor, $organization, null, $item, newValues: [
                'name' => $item->name, 'reference' => $item->reference,
                'default_price_excl_tax' => $item->default_price_excl_tax,
                'default_price_incl_tax' => $item->default_price_incl_tax,
                'tax_rate' => $item->tax_rate,
            ]);

            return $item;
        });
    }

    /** @return array{string, string|null} [rate, name] */
    private function tax(Organization $organization, mixed $taxRateId): array
    {
        if (! $taxRateId) {
            return ['0.0000', null];
        }
        $rate = TaxRate::query()->where('organization_id', $organization->getKey())->whereKey($taxRateId)->firstOrFail();
        if ($rate->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['tax_rate_id' => 'Seul un taux de TVA actif peut être sélectionné.']);
        }

        return [Decimal::normalize((string) $rate->rate), $rate->name];
    }
}
