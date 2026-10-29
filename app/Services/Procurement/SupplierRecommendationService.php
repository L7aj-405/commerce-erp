<?php

namespace App\Services\Procurement;

use App\Enums\QuotationLineType;
use App\Enums\QuotationSupplierAvailabilityStatus as Availability;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Supplier;
use App\Services\QuotationSourcing;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Phase B2: explainable, advisory supplier recommendations for a variant and a
 * requested quantity. Read-only: it never selects a supplier, never creates an
 * availability request, a relation or a purchase order.
 *
 * Tiers (conservative, explicit):
 *   A  preferred — the active preferred relation of the variant;
 *   B  strong    — an active relation with evidence: an explicit priority, a
 *                  confirmed availability or a purchase within the windows
 *                  below, or complete terms (price + lead time); never when the
 *                  latest recent answer was "unavailable";
 *   C  linked    — every other active relation;
 *   D  legacy    — product-level suppliers remembered by quotation sourcing
 *                  (product_suppliers) without a variant relation: lower
 *                  confidence, shown last, never migrated silently.
 * Inactive relations and inactive suppliers are excluded.
 *
 * Inside a tier the order is fixed (see compare()): priority, MOQ/multiple
 * compatibility, recent availability evidence, lead time, reliability,
 * quality, comparable price (same currency as the organization only — no FX),
 * most recent purchase, supplier name, supplier id. Same data + same quantity
 * + same viewer rights always gives the same ranking. Purchase prices take part
 * (price tie-break, "complete terms" evidence) ONLY for a viewer allowed to see
 * them; for any other viewer prices are ignored entirely, so neither the order,
 * the tier nor a reason can reveal relative costs.
 *
 * Queries are batched per call (relations, PO history, availability history,
 * legacy suppliers) whatever the number of lines or relations.
 */
final class SupplierRecommendationService
{
    /** Supplier answers older than this are ignored: no permanent reputation. */
    public const AVAILABILITY_LOOKBACK_DAYS = 180;

    /** A purchase order more recent than this counts as "recently purchased". */
    public const RECENT_PURCHASE_DAYS = 365;

    public const HIGH_RATING = 4;

    private const TIERS = ['A' => 'preferred', 'B' => 'strong', 'C' => 'linked', 'D' => 'legacy'];

    public function __construct(
        private readonly SupplierCatalogQuery $catalog,
        private readonly QuotationSourcing $sourcing,
    ) {}

    /**
     * Recommendations for each line of a draft quotation that needs supplier
     * sourcing, keyed by line id. The requested quantity is the line's current
     * shortage — the quantity an availability request defaults to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forQuotation(Quotation $quotation, bool $withPrices, ?int $lineId = null): array
    {
        $evaluation = $this->sourcing->evaluate($quotation);
        if ($lineId !== null) $evaluation = array_intersect_key($evaluation, [$lineId => true]);

        $items = [];
        foreach ($evaluation as $id => $line) {
            if (! $line['requires_sourcing'] || $line['line_type'] !== QuotationLineType::Catalog->value || ! $line['product_variant_id']) continue;
            $items[$id] = [
                'product_variant_id' => (int) $line['product_variant_id'],
                'requested_quantity' => $line['shortage'],
                // The canonical request action refuses a second pending request to the same supplier for a line.
                'pending_supplier_ids' => collect($line['requests'])->where('status', Availability::Pending->value)->pluck('supplier_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }
        $recommended = $this->recommendForVariants((int) $quotation->organization_id, $items, $withPrices);

        $result = [];
        foreach ($evaluation as $id => $line) {
            $result[$id] = [
                'quotation_line_id' => (int) $id,
                'article' => $line['article'],
                'product_variant_id' => $line['product_variant_id'],
                'line_type' => $line['line_type'],
                'shortage' => $line['shortage'],
                'covered_quantity' => $line['covered_quantity'],
                'resolved' => $line['resolved'],
                'recommendation_needed' => isset($items[$id]),
                ...($recommended[$id] ?? [
                    'requested_quantity' => $line['shortage'],
                    'recommendations' => [],
                    'reference_currency' => $this->referenceCurrency(),
                    'availability_lookback_days' => self::AVAILABILITY_LOOKBACK_DAYS,
                ]),
            ];
        }

        return $result;
    }

    /**
     * @param array<array-key, array{product_variant_id: int, requested_quantity: string, pending_supplier_ids?: list<int>}> $items
     * @return array<array-key, array<string, mixed>>
     */
    public function recommendForVariants(int $organizationId, array $items, bool $withPrices): array
    {
        if ($items === []) return [];
        $variantIds = array_values(array_unique(array_map(fn (array $item) => (int) $item['product_variant_id'], $items)));
        // Without price visibility the relations carry no price at all: nothing downstream can rank or explain by cost.
        $relations = $this->catalog->forVariants($organizationId, $variantIds, $withPrices, includeInactive: false);
        $availability = $this->availability($organizationId, $variantIds);
        $legacy = $this->legacySuppliers($organizationId, $variantIds, $relations);
        $now = CarbonImmutable::now();

        $result = [];
        foreach ($items as $key => $item) {
            $variantId = (int) $item['product_variant_id'];
            $quantity = Decimal::positive((string) $item['requested_quantity'], 'requested_quantity');
            $pending = array_flip($item['pending_supplier_ids'] ?? []);
            $candidates = [];
            foreach ($relations[$variantId] ?? [] as $relation) {
                if (! $relation['supplier']['active']) continue;
                $candidates[] = $this->candidate($relation, $quantity, $availability[$relation['supplier']['id'].':'.$variantId] ?? null, isset($pending[$relation['supplier']['id']]), $now, $withPrices);
            }
            foreach ($legacy[$variantId] ?? [] as $supplier) {
                $candidates[] = $this->legacyCandidate($supplier, $quantity, $availability[$supplier['id'].':'.$variantId] ?? null, isset($pending[$supplier['id']]));
            }

            usort($candidates, fn (array $a, array $b) => $this->compare($a, $b));
            $result[$key] = [
                'requested_quantity' => $quantity,
                'reference_currency' => $this->referenceCurrency(),
                'availability_lookback_days' => self::AVAILABILITY_LOOKBACK_DAYS,
                'recommendations' => $this->finalize($candidates, $withPrices),
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $relation @param array<string, mixed>|null $history @return array<string, mixed> */
    private function candidate(array $relation, string $quantity, ?array $history, bool $pendingForLine, CarbonImmutable $now, bool $withPrices): array
    {
        $latest = $history['latest'] ?? null;
        $lastPurchase = $relation['last_purchase'];
        $recentPurchase = $lastPurchase !== null && CarbonImmutable::parse($lastPurchase['order_date'])->greaterThanOrEqualTo($now->subDays(self::RECENT_PURCHASE_DAYS)->startOfDay());
        $confirmed = in_array($latest['status'] ?? null, [Availability::Available->value, Availability::Partial->value], true);
        $unavailable = ($latest['status'] ?? null) === Availability::Unavailable->value;
        $price = $withPrices ? $relation['purchase_price'] : null;
        $completeTerms = $price !== null && $relation['lead_time_days'] !== null;
        $tier = match (true) {
            $relation['is_preferred'] => 'A',
            ! $unavailable && ($relation['priority'] !== null || $confirmed || $recentPurchase || $completeTerms) => 'B',
            default => 'C',
        };

        return [
            'tier' => $tier,
            'source' => 'catalog',
            'supplier' => ['id' => $relation['supplier']['id'], 'name' => $relation['supplier']['name']],
            'relation' => $relation,
            'quantity' => $this->quantity($quantity, $relation['minimum_order_quantity'], $relation['order_multiple']),
            'availability' => $this->availabilitySummary($history),
            'recent_purchase' => $recentPurchase,
            'pending_for_line' => $pendingForLine,
            'comparable_price' => $price !== null && $relation['currency_code'] === $this->referenceCurrency() ? $price : null,
        ];
    }

    /** @param array{id: int, name: string} $supplier @param array<string, mixed>|null $history @return array<string, mixed> */
    private function legacyCandidate(array $supplier, string $quantity, ?array $history, bool $pendingForLine): array
    {
        return [
            'tier' => 'D', 'source' => 'legacy', 'supplier' => $supplier, 'relation' => null,
            'quantity' => $this->quantity($quantity, null, null),
            'availability' => $this->availabilitySummary($history),
            'recent_purchase' => false, 'pending_for_line' => $pendingForLine, 'comparable_price' => null,
        ];
    }

    /**
     * Deterministic order; every step is explainable to the user.
     *
     * @param array<string, mixed> $a @param array<string, mixed> $b
     */
    private function compare(array $a, array $b): int
    {
        $relA = $a['relation'] ?? [];
        $relB = $b['relation'] ?? [];
        $evidence = fn (array $c) => match ($c['availability']['latest_status'] ?? null) {
            Availability::Available->value => 0, Availability::Partial->value => 1, null, Availability::Pending->value => 2, default => 3,
        };
        $steps = [
            fn () => strcmp($a['tier'], $b['tier']),
            fn () => ($relA['priority'] ?? 1000) <=> ($relB['priority'] ?? 1000),
            fn () => ($a['quantity']['moq_compatible'] && $a['quantity']['multiple_compatible'] ? 0 : 1) <=> ($b['quantity']['moq_compatible'] && $b['quantity']['multiple_compatible'] ? 0 : 1),
            fn () => $evidence($a) <=> $evidence($b),
            fn () => ($relA['lead_time_days'] ?? PHP_INT_MAX) <=> ($relB['lead_time_days'] ?? PHP_INT_MAX),
            fn () => ($relB['reliability_rating'] ?? 0) <=> ($relA['reliability_rating'] ?? 0),
            fn () => ($relB['quality_rating'] ?? 0) <=> ($relA['quality_rating'] ?? 0),
            fn () => match (true) {
                $a['comparable_price'] === null && $b['comparable_price'] === null => 0,
                $a['comparable_price'] === null => 1,
                $b['comparable_price'] === null => -1,
                default => Decimal::compare($a['comparable_price'], $b['comparable_price']),
            },
            fn () => strcmp((string) ($relB['last_purchase']['order_date'] ?? ''), (string) ($relA['last_purchase']['order_date'] ?? '')),
            fn () => strcmp(mb_strtolower((string) $a['supplier']['name']), mb_strtolower((string) $b['supplier']['name'])),
            fn () => $a['supplier']['id'] <=> $b['supplier']['id'],
        ];
        foreach ($steps as $step) {
            $result = $step();
            if ($result !== 0) return $result;
        }

        return 0;
    }

    /** @param list<array<string, mixed>> $candidates @return list<array<string, mixed>> */
    private function finalize(array $candidates, bool $withPrices): array
    {
        $linked = array_values(array_filter($candidates, fn ($c) => $c['relation'] !== null));
        $leadTimes = array_values(array_filter(array_map(fn ($c) => $c['relation']['lead_time_days'], $linked), fn ($v) => $v !== null));
        $prices = array_values(array_filter(array_map(fn ($c) => $c['comparable_price'], $linked), fn ($v) => $v !== null));
        $fastest = count($leadTimes) >= 2 ? min($leadTimes) : null;
        $cheapest = count($prices) >= 2 ? array_reduce($prices, fn ($min, $p) => $min === null || Decimal::compare($p, $min) < 0 ? $p : $min) : null;

        $out = [];
        foreach ($candidates as $index => $c) {
            $relation = $c['relation'];
            [$reasons, $warnings] = $this->explain($c, $fastest, $cheapest, $withPrices);
            $lastPurchase = $relation['last_purchase'] ?? null;
            if ($lastPurchase !== null && ! $withPrices) $lastPurchase['unit_price'] = null;
            $eligible = ! $c['pending_for_line'];

            $out[] = [
                'rank' => $index + 1,
                'tier' => $c['tier'],
                'tier_label' => self::TIERS[$c['tier']],
                'source' => $c['source'],
                'supplier' => $c['supplier'],
                'relation_id' => $relation['id'] ?? null,
                'is_preferred' => (bool) ($relation['is_preferred'] ?? false),
                'priority' => $relation['priority'] ?? null,
                'supplier_sku' => $relation['supplier_sku'] ?? null,
                'supplier_reference' => $relation['supplier_reference'] ?? null,
                'purchase_price' => $withPrices ? ($relation['purchase_price'] ?? null) : null,
                'currency_code' => $relation['currency_code'] ?? null,
                'prices_visible' => $withPrices,
                'lead_time_days' => $relation['lead_time_days'] ?? null,
                'quality_rating' => $relation['quality_rating'] ?? null,
                'reliability_rating' => $relation['reliability_rating'] ?? null,
                'quantity' => $c['quantity'],
                'last_purchase' => $lastPurchase,
                'availability' => $c['availability'],
                'reasons' => $reasons,
                'warnings' => $warnings,
                'eligible_for_request' => $eligible,
                'ineligible_reason' => $eligible ? null : 'request_pending_for_line',
                'explanation' => $this->sentence($c, $reasons, $warnings),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $c @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function explain(array $c, ?int $fastest, ?string $cheapest, bool $withPrices): array
    {
        $relation = $c['relation'];
        $reasons = [];
        $warnings = [];
        $add = function (array &$list, string $code, array $params = []) { $list[] = ['code' => $code, 'params' => $params]; };
        $availability = $c['availability'];

        if ($relation === null) {
            $add($warnings, 'legacy_suggestion');
        } else {
            if ($relation['is_preferred']) $add($reasons, 'preferred_supplier');
            if ($relation['priority'] !== null) $add($reasons, 'explicit_priority', ['priority' => $relation['priority']]);
            if ($relation['lead_time_days'] !== null) {
                $fastest !== null && $relation['lead_time_days'] === $fastest
                    ? $add($reasons, 'fastest_lead_time', ['days' => $relation['lead_time_days']])
                    : $add($reasons, 'known_lead_time', ['days' => $relation['lead_time_days']]);
            } else {
                $add($warnings, 'missing_lead_time');
            }
            // Price reasons and warnings only for a viewer allowed to see purchase prices: even "no price known" is cost information.
            if ($withPrices) {
                if ($relation['purchase_price'] === null) {
                    $add($warnings, 'missing_price');
                } elseif ($c['comparable_price'] === null) {
                    $add($warnings, 'price_currency_not_comparable', ['currency' => $relation['currency_code'], 'reference_currency' => $this->referenceCurrency()]);
                } elseif ($cheapest !== null && Decimal::compare($c['comparable_price'], $cheapest) === 0) {
                    $add($reasons, 'best_known_price', ['price' => $c['comparable_price'], 'currency' => $relation['currency_code']]);
                }
            }
            if (($relation['reliability_rating'] ?? 0) >= self::HIGH_RATING) $add($reasons, 'high_reliability', ['rating' => $relation['reliability_rating']]);
            if (($relation['quality_rating'] ?? 0) >= self::HIGH_RATING) $add($reasons, 'high_quality', ['rating' => $relation['quality_rating']]);
            if ($c['recent_purchase']) {
                $add($reasons, 'recently_purchased', ['date' => $relation['last_purchase']['order_date'], 'purchase_order_number' => $relation['last_purchase']['purchase_order_number']]);
            }
        }

        match ($availability['latest_status']) {
            Availability::Available->value => $add($reasons, 'recently_confirmed_available', ['date' => $availability['latest_at']]),
            Availability::Partial->value => $add($reasons, 'recently_partially_available', ['date' => $availability['latest_at']]),
            Availability::Unavailable->value => $add($warnings, 'recently_unavailable', ['date' => $availability['latest_at']]),
            default => null,
        };
        if ($c['pending_for_line']) {
            $add($warnings, 'request_pending_for_line');
        } elseif ($availability['pending_count'] > 0) {
            $add($warnings, 'availability_request_pending', ['count' => $availability['pending_count']]);
        }

        $quantity = $c['quantity'];
        if (! $quantity['moq_compatible']) $add($warnings, 'moq_not_met', ['minimum' => $quantity['minimum_order_quantity'], 'requested' => $quantity['requested_quantity']]);
        if ($quantity['suggested_order_quantity'] !== null) {
            $add($warnings, 'order_multiple_adjustment', ['suggested' => $quantity['suggested_order_quantity'], 'multiple' => $quantity['order_multiple']]);
        }

        return [$reasons, $warnings];
    }

    /**
     * MOQ / order-multiple fit of the requested quantity. The quotation
     * quantity is never changed: suggested_order_quantity is information.
     *
     * @return array<string, mixed>
     */
    private function quantity(string $requested, ?string $minimum, ?string $multiple): array
    {
        $moqOk = $minimum === null || Decimal::compare($requested, $minimum) >= 0;
        $target = $moqOk ? $requested : Decimal::normalize($minimum);
        $multipleOk = true;
        if ($multiple !== null && Decimal::compare($multiple, '0') > 0) {
            $steps = bcdiv($target, $multiple, 0);
            if (bccomp(bcmul($steps, $multiple, 4), $target, 4) < 0) $steps = bcadd($steps, '1', 0);
            $rounded = Decimal::normalize(bcmul($steps, $multiple, 4));
            $multipleOk = bccomp(bcmod($requested, $multiple, 4), '0', 4) === 0;
            $target = $rounded;
        }
        $suggested = Decimal::compare($target, $requested) === 0 ? null : $target;

        return [
            'requested_quantity' => $requested,
            'minimum_order_quantity' => $minimum,
            'order_multiple' => $multiple,
            'moq_compatible' => $moqOk,
            'multiple_compatible' => $multipleOk,
            'suggested_order_quantity' => $suggested,
        ];
    }

    /**
     * Supplier answers per "supplier:variant" within the lookback window: the
     * latest answer, counts per outcome and pending requests. One query.
     *
     * @param list<int> $variantIds
     * @return array<string, array<string, mixed>>
     */
    private function availability(int $organizationId, array $variantIds): array
    {
        $since = CarbonImmutable::now()->subDays(self::AVAILABILITY_LOOKBACK_DAYS);
        $rows = QuotationSupplierAvailabilityRequest::query()->where('organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)->whereNotNull('supplier_id')
            ->whereIn('status', [Availability::Pending->value, Availability::Available->value, Availability::Partial->value, Availability::Unavailable->value])
            ->where('requested_at', '>=', $since)
            ->orderBy('id')
            ->get(['id', 'supplier_id', 'product_variant_id', 'status', 'requested_at', 'resolved_at']);

        $history = [];
        foreach ($rows as $row) {
            $key = $row->supplier_id.':'.$row->product_variant_id;
            $history[$key] ??= ['latest' => null, 'counts' => ['available' => 0, 'partial' => 0, 'unavailable' => 0, 'pending' => 0]];
            $history[$key]['counts'][$row->status->value]++;
            if ($row->status === Availability::Pending) continue;
            $at = $row->resolved_at ?? $row->requested_at;
            $latest = $history[$key]['latest'];
            if ($latest === null || $at->greaterThan($latest['at']) || ($at->equalTo($latest['at']) && $row->id > $latest['id'])) {
                $history[$key]['latest'] = ['id' => (int) $row->id, 'status' => $row->status->value, 'at' => $at];
            }
        }

        return $history;
    }

    /** @param array<string, mixed>|null $history @return array<string, mixed> */
    private function availabilitySummary(?array $history): array
    {
        return [
            'latest_status' => $history['latest']['status'] ?? null,
            'latest_at' => isset($history['latest']) ? $history['latest']['at']->toIso8601String() : null,
            'confirmed_count' => $history['counts']['available'] ?? 0,
            'partial_count' => $history['counts']['partial'] ?? 0,
            'unavailable_count' => $history['counts']['unavailable'] ?? 0,
            'pending_count' => $history['counts']['pending'] ?? 0,
            'lookback_days' => self::AVAILABILITY_LOOKBACK_DAYS,
        ];
    }

    /**
     * Legacy product-level suppliers with no variant relation, per variant.
     *
     * @param list<int> $variantIds @param array<int, list<array<string, mixed>>> $relations
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function legacySuppliers(int $organizationId, array $variantIds, array $relations): array
    {
        $products = ProductVariant::query()->where('organization_id', $organizationId)->whereIn('id', $variantIds)->pluck('product_id', 'id');
        $rows = ProductSupplier::query()->where('organization_id', $organizationId)->whereIn('product_id', $products->unique()->values())
            ->orderBy('supplier_id')->get(['product_id', 'supplier_id']);
        $legacy = $rows->groupBy('product_id');
        $suppliers = Supplier::query()->where('organization_id', $organizationId)->where('active', true)
            ->whereIn('id', $rows->pluck('supplier_id')->unique()->values())->get(['id', 'name'])->keyBy('id');

        $result = [];
        foreach ($products as $variantId => $productId) {
            $linked = array_flip(array_map(fn ($relation) => $relation['supplier']['id'], $relations[$variantId] ?? []));
            foreach ($legacy[$productId] ?? [] as $row) {
                $supplier = $suppliers[$row->supplier_id] ?? null;
                if ($supplier === null || isset($linked[$supplier->id])) continue;
                $result[(int) $variantId][] = ['id' => (int) $supplier->id, 'name' => $supplier->name];
            }
        }

        return $result;
    }

    /**
     * One English sentence for AI clients, built only from the reason codes.
     *
     * @param array<string, mixed> $c @param list<array<string, mixed>> $reasons @param list<array<string, mixed>> $warnings
     */
    private function sentence(array $c, array $reasons, array $warnings): string
    {
        $because = array_filter(array_map(fn (array $reason) => match ($reason['code']) {
            'preferred_supplier' => 'it is the preferred supplier',
            'explicit_priority' => "it has priority {$reason['params']['priority']}",
            'fastest_lead_time' => "it has the shortest known lead time ({$reason['params']['days']} days)",
            'known_lead_time' => "its known lead time is {$reason['params']['days']} days",
            'best_known_price' => 'it has the best known price in '.$reason['params']['currency'],
            'high_reliability' => "its reliability is rated {$reason['params']['rating']}/5",
            'high_quality' => "its quality is rated {$reason['params']['rating']}/5",
            'recently_purchased' => "it was last ordered on {$reason['params']['date']}",
            'recently_confirmed_available' => 'it recently confirmed availability',
            'recently_partially_available' => 'it recently confirmed partial availability',
            default => null,
        }, $reasons));
        $cautions = array_filter(array_map(fn (array $warning) => match ($warning['code']) {
            'moq_not_met' => "its minimum order is {$warning['params']['minimum']}",
            'order_multiple_adjustment' => "it sells by multiples of {$warning['params']['multiple']} (order {$warning['params']['suggested']})",
            'recently_unavailable' => 'it recently answered unavailable',
            'missing_price' => 'no price is known',
            'missing_lead_time' => 'no lead time is known',
            'price_currency_not_comparable' => "its price is in {$warning['params']['currency']} and is not compared",
            'request_pending_for_line' => 'a request to it is already pending for this line',
            'legacy_suggestion' => 'it is only remembered from earlier quotations for this product (no catalog terms)',
            default => null,
        }, $warnings));

        $text = "{$c['supplier']['name']} (tier {$c['tier']})";
        $text .= $because === [] ? ' is a linked supplier' : ' is recommended because '.implode(', ', $because);
        if ($cautions !== []) $text .= '; note: '.implode(', ', $cautions);

        return $text.'.';
    }

    private function referenceCurrency(): string
    {
        return strtoupper((string) config('platform.currency_code', 'MAD'));
    }
}
