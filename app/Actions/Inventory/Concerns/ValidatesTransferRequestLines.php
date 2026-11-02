<?php

namespace App\Actions\Inventory\Concerns;

use App\Enums\CatalogStatus;
use App\Enums\WarehouseStatus;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\ShowroomReplenishmentPlanner;
use App\Support\InventoryQuantity;
use Illuminate\Validation\ValidationException;

/**
 * Server-side validation shared by manual creation, line editing and
 * preparation review. Quantities are always re-checked against the CURRENT
 * source stock under the caller's planning lock — never trusted from the
 * client screen, which may be minutes old.
 *
 * @property ShowroomReplenishmentPlanner $planner
 */
trait ValidatesTransferRequestLines
{
    /** @return array{Warehouse, Warehouse} */
    private function resolveRoute(Organization $organization, int $sourceId, int $destinationId): array
    {
        if ($sourceId === $destinationId) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'La destination doit être différente de la source.']);
        }

        $warehouses = Warehouse::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', [$sourceId, $destinationId])
            ->get()->keyBy('id');

        $source = $warehouses->get($sourceId);
        $destination = $warehouses->get($destinationId);
        abort_unless($source && $destination, 404);

        if ($source->status !== WarehouseStatus::Active || $destination->status !== WarehouseStatus::Active) {
            throw ValidationException::withMessages(['source_warehouse_id' => 'Les deux emplacements doivent être actifs.']);
        }

        return [$source, $destination];
    }

    /**
     * Normalise `[{product_variant_id, quantity}]` into `variant id => qty`.
     * Rejects duplicates, non-positive quantities and foreign / inactive
     * variants (hidden as 404-equivalent validation, never leaked).
     *
     * @param  list<array{product_variant_id: int|string, quantity: int|float|string}>  $lines
     * @return array<int, string>
     */
    private function normaliseLines(Organization $organization, array $lines, bool $allowEmpty = false): array
    {
        if ($lines === [] && ! $allowEmpty) {
            throw ValidationException::withMessages(['lines' => 'Ajoutez au moins un article.']);
        }

        $out = [];
        foreach (array_values($lines) as $index => $line) {
            $variantId = (int) ($line['product_variant_id'] ?? 0);
            if (isset($out[$variantId])) {
                throw ValidationException::withMessages(["lines.{$index}.product_variant_id" => 'Cet article figure déjà dans la demande.']);
            }
            $out[$variantId] = InventoryQuantity::positive((string) ($line['quantity'] ?? '0'), "lines.{$index}.quantity");
        }

        if ($out !== []) {
            $valid = ProductVariant::query()
                ->where('organization_id', $organization->getKey())
                ->where('status', CatalogStatus::Active->value)
                ->whereIn('id', array_keys($out))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach (array_keys($out) as $index => $variantId) {
                if (! in_array($variantId, $valid, true)) {
                    throw ValidationException::withMessages(["lines.{$index}.product_variant_id" => 'Article introuvable ou inactif.']);
                }
            }
        }

        return $out;
    }

    /**
     * Every quantity must fit in the source's transferable stock (available
     * minus other active requests' promises). `$excludeRequestId` lets a
     * request being edited ignore its own previous promise.
     *
     * @param  array<int, string>  $quantities  variant id => qty
     * @param  array<int, int>  $indexByVariant  variant id => submitted line index (for error keys)
     */
    private function assertTransferable(Organization $organization, Warehouse $source, array $quantities, ?int $excludeRequestId = null, array $indexByVariant = []): void
    {
        if ($quantities === []) {
            return;
        }

        $stock = $this->planner->transferableMap($organization->getKey(), $source->getKey(), array_keys($quantities), $excludeRequestId);
        $errors = [];
        $position = 0;
        foreach ($quantities as $variantId => $quantity) {
            $free = $stock[$variantId]['transferable'] ?? InventoryQuantity::ZERO;
            if (InventoryQuantity::compare($quantity, $free) > 0) {
                $index = $indexByVariant[$variantId] ?? $position;
                $errors["lines.{$index}.quantity"] = 'Stock transférable insuffisant à la source : '.rtrim(rtrim($free, '0'), '.').' disponible(s).';
            }
            $position++;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
