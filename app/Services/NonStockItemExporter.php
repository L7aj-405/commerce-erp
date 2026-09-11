<?php

namespace App\Services;

use App\Models\NonStockItem;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * CSV export of the non-stock (external) article library for the website /
 * catalogue team: the list of quoted articles that still need to be added to the
 * real Product catalogue. Never mixes in stock quantities — these items have no
 * inventory.
 */
class NonStockItemExporter
{
    public function csv(Organization $organization): string
    {
        $rows = NonStockItem::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('name')
            ->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Désignation', 'Référence', 'Prix HT', 'Prix TTC', 'TVA (%)', 'Unité', 'Devis (utilisations)', 'Statut', 'Créé le']);

        foreach ($rows as $item) {
            fputcsv($handle, $this->line($item));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        // BOM so Excel opens the accents correctly.
        return "\xEF\xBB\xBF".$csv;
    }

    /** @return list<string> */
    private function line(NonStockItem $item): array
    {
        return [
            $item->name,
            $item->reference ?? '',
            $item->default_price_excl_tax !== null ? number_format((float) $item->default_price_excl_tax, 2, '.', '') : '',
            $item->default_price_incl_tax !== null ? number_format((float) $item->default_price_incl_tax, 2, '.', '') : '',
            number_format((float) $item->tax_rate, 2, '.', ''),
            $item->unit_label ?? '',
            (string) $item->usage_count,
            $item->status->value,
            $item->created_at?->toDateString() ?? '',
        ];
    }

    /** @return Collection<int, NonStockItem> */
    public function all(Organization $organization): Collection
    {
        return NonStockItem::query()->where('organization_id', $organization->getKey())->orderBy('name')->get();
    }
}
