<?php

namespace App\Services\Mcp\Procurement;

use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Models\ProductVariant;
use App\Models\ProductVariantSupplier;
use App\Models\Supplier;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Models\QuotationLine;
use App\Services\Mcp\Sales\QuotationReadService;
use App\Services\Procurement\SupplierCatalogQuery;
use App\Services\Procurement\SupplierRecommendationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Supplier catalog (B1) over MCP: reads through SupplierCatalogQuery, writes
 * through SaveProductVariantSupplierAction (plan() for the preview, execute()
 * for the save), so web and MCP share every rule.
 *
 * A preview fingerprint is single-use (McpPartyPreviews) and bound to the
 * request, the relation's current state and the variant's current preferred
 * supplier: anything that changed in between makes the save stale.
 */
final class McpSupplierCatalogService
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SupplierCatalogQuery $catalog,
        private readonly SaveProductVariantSupplierAction $save,
        private readonly McpPartyPreviews $previews,
        private readonly QuotationReadService $quotations,
        private readonly SupplierRecommendationService $recommendations,
    ) {}

    /**
     * Advisory ranked suppliers for one quotation line (B2), for its current
     * shortage. Read-only: no request, relation or purchase order is created.
     *
     * @return array<string, mixed>
     */
    public function recommendForQuotationLine(int $quotationId, int $lineId): array
    {
        $this->authorizeView();
        $this->context->authorize('quotation_sourcing.view');
        $quotation = $this->quotations->find($this->context->organization(), $quotationId);
        $quotation->lines->firstWhere('id', $lineId) ?? throw (new ModelNotFoundException)->setModel(QuotationLine::class, [$lineId]);
        $note = 'Advisory only: the ranking uses known ERP data (supplier catalog terms, purchase orders, recent availability answers) and does not guarantee availability. '
            .'It reserves nothing and creates no purchase order. Explain the top suppliers, let the user choose, then call create_supplier_availability_request for the chosen supplier only — '
            .'never for every recommendation. Issuing the quotation still requires a confirmed availability when the line needs sourcing.';
        if (! $quotation->status->isEditable()) {
            return ['quotation_id' => $quotationId, 'quotation_line_id' => $lineId, 'evaluated' => false,
                'message' => 'Recommendations are computed for draft quotations only.', 'note' => $note];
        }

        return [
            'quotation_id' => $quotationId,
            'evaluated' => true,
            ...$this->recommendations->forQuotation($quotation, $this->withPrices(), $lineId)[$lineId],
            'prices_visible' => $this->withPrices(),
            'ranking' => 'Tiers: A preferred, B linked with evidence (priority, recent confirmation or purchase, complete terms), C other linked, D legacy (remembered from past quotations, no catalog terms). '
                .'Within a tier: priority, MOQ/multiple fit, recent availability, lead time, reliability, quality, price in '.strtoupper((string) config('platform.currency_code', 'MAD')).' only, latest purchase, name.',
            'other_suppliers' => 'Any other active supplier can still be asked: use search_suppliers, then create_supplier_availability_request.',
            'note' => $note,
        ];
    }

    /** @return array<string, mixed> */
    public function variantSuppliers(?int $variantId, ?string $sku, bool $includeInactive): array
    {
        $this->authorizeView();
        $variant = $this->variant($variantId, $sku);
        $relations = $this->catalog->forVariants($this->organizationId(), [(int) $variant->getKey()], $this->withPrices(), $includeInactive)[$variant->getKey()] ?? [];

        return [
            'variant' => ['id' => (int) $variant->getKey(), 'sku' => $variant->sku, 'label' => $variant->label, 'reference' => $variant->reference,
                'status' => $variant->status->value, 'product' => ['id' => (int) $variant->product_id, 'name' => $variant->product?->name]],
            'preferred_supplier' => collect($relations)->firstWhere('is_preferred', true)['supplier'] ?? null,
            'suppliers' => $relations,
            'prices_visible' => $this->withPrices(),
            'note' => 'purchase_price is the known supplier-specific price (not the variant cost). last_purchase is derived from purchase orders. No ranking: do not infer a recommendation beyond the preferred flag.',
        ];
    }

    /** @return array<string, mixed> */
    public function supplierProducts(int $supplierId, ?string $status, ?string $search, int $page, int $perPage): array
    {
        $this->authorizeView();
        $supplier = Supplier::query()->where('organization_id', $this->organizationId())->whereKey($supplierId)->firstOrFail();
        $paginator = $this->catalog->forSupplier($this->organizationId(), (int) $supplier->getKey(), ['status' => $status, 'search' => $search], $this->withPrices(), $perPage, $page);

        return [
            'supplier' => ['id' => (int) $supplier->getKey(), 'name' => $supplier->name, 'active' => (bool) $supplier->active],
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
            'products' => $paginator->items(),
            'prices_visible' => $this->withPrices(),
        ];
    }

    /**
     * @param array<string, mixed> $relation
     * @return array{supplier_id: int, product_variant_id: int, changes: array<string, mixed>}
     */
    public function normalize(int $supplierId, int $variantId, array $relation): array
    {
        if ($supplierId < 1 || $variantId < 1) throw new InvalidArgumentException('invalid_id');
        if (array_diff(array_keys($relation), SaveProductVariantSupplierAction::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        if (array_key_exists('purchase_price', $relation) && ! $this->withPrices()) throw new InvalidArgumentException('price_access_required');
        ksort($relation);

        return ['supplier_id' => $supplierId, 'product_variant_id' => $variantId, 'changes' => $relation];
    }

    /** @param array{supplier_id: int, product_variant_id: int, changes: array<string, mixed>} $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $this->authorizeManage();
        try {
            $plan = $this->plan($payload, lock: false);
        } catch (ValidationException $exception) {
            return [
                'mode' => null, 'valid' => false, 'issues' => $this->issues($exception), 'has_changes' => false,
                'preview_fingerprint' => null, 'expires_at' => null,
            ];
        }
        $hasChanges = $plan['mode'] !== 'update' || $plan['changed_fields'] !== [];
        [$fingerprint, $expiresAt] = $hasChanges ? $this->previews->issue('supplier-catalog', $this->binding($payload, $plan)) : [null, null];
        $preferred = $plan['preferred'];

        return [
            'mode' => $plan['mode'],
            'relation_id' => $plan['relation']?->getKey(),
            'supplier' => ['id' => (int) $plan['supplier']->getKey(), 'name' => $plan['supplier']->name, 'active' => (bool) $plan['supplier']->active],
            'variant' => ['id' => (int) $plan['variant']->getKey(), 'sku' => $plan['variant']->sku, 'product_name' => $plan['variant']->product?->name],
            'current' => $plan['current'] === null ? null : $this->present($plan['current']),
            'proposed' => $this->present($plan['proposed']),
            'changed_fields' => $plan['changed_fields'],
            'has_changes' => $hasChanges,
            'preferred_change' => [
                'current_preferred' => $preferred['current'],
                'new_preferred' => $plan['proposed']['is_preferred'] ? ['supplier_id' => (int) $plan['supplier']->getKey(), 'supplier_name' => $plan['supplier']->name] : ($preferred['loses_preferred'] ? null : $preferred['current']),
                'will_unset' => $preferred['unset'],
                'loses_preferred' => $preferred['loses_preferred'],
            ],
            'valid' => true,
            'issues' => [],
            'required_scopes' => ['supplier_catalog.manage'],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => match ($plan['mode']) {
                'create' => 'Creates the supplier relation for this variant. It never changes the variant purchase cost.',
                'reactivate' => 'This supplier was linked before and deactivated: the existing relation is reactivated and updated, not duplicated.',
                default => 'Updates the existing relation. Omitted fields keep their value; status "inactive" deactivates it (relations are never deleted).',
            },
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper): the plan
     * locks the variant and relation rows before the binding is compared.
     *
     * @param array{supplier_id: int, product_variant_id: int, changes: array<string, mixed>} $payload
     */
    public function save(array $payload, string $fingerprint): ProductVariantSupplier
    {
        $this->authorizeManage();
        try {
            $plan = $this->plan($payload, lock: true);
        } catch (ValidationException) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        if ($plan['mode'] === 'update' && $plan['changed_fields'] === []) throw new InvalidArgumentException('stale_or_invalid_preview');
        $binding = $this->binding($payload, $plan);
        $this->previews->consume('supplier-catalog', $fingerprint, $binding);

        try {
            return $this->save->execute($this->context->user(), $this->context->organization(), $this->actionData($payload, $plan), $plan['mode'] === 'update' ? $plan['relation'] : null);
        } catch (Throwable $exception) {
            $this->previews->release('supplier-catalog', $fingerprint, $binding);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function relation(ProductVariantSupplier $relation): array
    {
        return $this->catalog->one($relation, $this->withPrices());
    }

    /** @param array{supplier_id: int, product_variant_id: int, changes: array<string, mixed>} $payload @return array<string, mixed> */
    private function plan(array $payload, bool $lock): array
    {
        $existing = ProductVariantSupplier::query()->where('organization_id', $this->organizationId())
            ->where('supplier_id', $payload['supplier_id'])->where('product_variant_id', $payload['product_variant_id'])->first();
        // An active pair is edited in place; an inactive or missing one goes through create (which reactivates).
        $update = $existing?->status === ProductVariantSupplier::STATUS_ACTIVE;

        return $this->save->plan($this->context->organization(), $this->actionData($payload, ['mode' => $update ? 'update' : 'create']), $update ? $existing : null, $lock);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $plan @return array<string, mixed> */
    private function actionData(array $payload, array $plan): array
    {
        return $plan['mode'] === 'update'
            ? $payload['changes']
            : [...$payload['changes'], 'supplier_id' => $payload['supplier_id'], 'product_variant_id' => $payload['product_variant_id']];
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $plan @return array<string, mixed> */
    private function binding(array $payload, array $plan): array
    {
        $relation = $plan['relation'];

        return [
            'entity' => 'supplier-catalog',
            'mode' => $plan['mode'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $this->previews->hash([
                'relation' => $relation ? [(int) $relation->getKey(), $relation->updated_at?->format('Y-m-d\TH:i:s.uP'), $plan['current']] : null,
                'preferred' => $plan['preferred']['current'],
                'supplier_active' => (bool) $plan['supplier']->active,
            ]),
            'proposed_hash' => $this->previews->hash($plan['proposed']),
            'required_scopes' => ['supplier_catalog.manage'],
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function present(array $values): array
    {
        if (! $this->withPrices()) unset($values['purchase_price']);

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function issues(ValidationException $exception): array
    {
        $issues = [];
        foreach (collect($exception->errors())->take(20) as $field => $messages) {
            $issues[] = ['path' => 'relation.'.$field, 'code' => $this->issueCode($field, (string) $messages[0]), 'message' => mb_substr((string) $messages[0], 0, 300)];
        }

        return $issues;
    }

    private function issueCode(string $field, string $message): string
    {
        return match (true) {
            in_array($field, ['supplier_id', 'product_variant_id'], true) && str_contains($message, 'introuvable') => 'not_found',
            $field === 'supplier_id' && str_contains($message, 'déjà lié') => 'duplicate_relation',
            $field === 'supplier_id' && str_contains($message, 'inactif') => 'supplier_inactive',
            default => 'invalid',
        };
    }

    private function variant(?int $variantId, ?string $sku): ProductVariant
    {
        if (($variantId === null) === ($sku === null || trim($sku) === '')) throw new InvalidArgumentException('variant_locator_required');

        return ProductVariant::query()->where('organization_id', $this->organizationId())
            ->when($variantId !== null, fn ($query) => $query->whereKey($variantId), fn ($query) => $query->where('sku', trim((string) $sku)))
            ->with('product:id,name')->firstOrFail();
    }

    /** Prices are cost data: scope ceiling (cost or purchase-order view) ∩ the ERP permission of the web pages. */
    private function withPrices(): bool
    {
        return ($this->context->allows('product_cost.view') || $this->context->allows('purchase_orders.view'))
            && SupplierCatalogQuery::canViewPrices($this->context->user(), $this->organizationId());
    }

    private function authorizeView(): void
    {
        $this->context->authorize('supplier_catalog.view');
        // Relations expose variants: the catalog view permission is required too (never widened by the scope).
        if (! $this->context->user()->hasPermission($this->organizationId(), 'products.view')) throw new AuthorizationException;
    }

    private function authorizeManage(): void
    {
        $this->authorizeView();
        $this->context->authorize('supplier_catalog.manage');
    }

    private function organizationId(): int
    {
        return (int) $this->context->organization()->getKey();
    }
}
