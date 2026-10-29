<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\ProductVariantSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single writer of supplier catalog relations (Phase B1), shared by the
 * web forms and MCP.
 *
 * - One canonical relation per supplier + variant: linking an already linked
 *   active pair is refused, linking an inactive pair reactivates that row.
 * - At most one preferred supplier per variant: marking a relation preferred
 *   unsets the previous one in the same transaction, under a lock on the
 *   variant row (backed by the pvs_org_preferred_uq unique key).
 * - Relations are deactivated, never deleted; an inactive relation is never
 *   preferred.
 * - The supplier price never touches product_variants.purchase_price.
 *
 * plan() evaluates a request without writing, so previews (MCP) show exactly
 * what execute() will do.
 */
class SaveProductVariantSupplierAction
{
    use AuthorizesProcurementAction;

    public const FIELDS = [
        'supplier_sku', 'supplier_reference', 'supplier_product_name',
        'purchase_price', 'currency_code', 'minimum_order_quantity', 'order_multiple', 'lead_time_days',
        'is_preferred', 'priority', 'status', 'quality_rating', 'reliability_rating', 'notes',
    ];

    private const DECIMALS = ['purchase_price', 'minimum_order_quantity', 'order_multiple'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'supplier_sku' => ['nullable', 'string', 'max:255'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'supplier_product_name' => ['nullable', 'string', 'max:255'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/\A[A-Za-z]{3}\z/'],
            'minimum_order_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'order_multiple' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_preferred' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
            'status' => ['required', 'in:active,inactive'],
            'quality_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'reliability_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Creates (supplier_id + product_variant_id in $data) or updates
     * ($relation) a relation. Omitted fields keep their current value.
     *
     * @param array<string, mixed> $data
     */
    public function execute(User $actor, Organization $organization, array $data, ?ProductVariantSupplier $relation = null): ProductVariantSupplier
    {
        $this->authorizeProcurement($actor, $organization, 'supplier_catalog.manage');

        return DB::transaction(function () use ($actor, $organization, $data, $relation) {
            $plan = $this->plan($organization, $data, $relation, lock: true);
            $row = $plan['relation'] ?? new ProductVariantSupplier;
            if ($plan['mode'] !== 'create' && $plan['changed_fields'] === []) return $row;

            $context = $this->context($plan['supplier'], $plan['variant']);
            $unset = $plan['preferred']['unset'];
            if ($unset !== null) {
                // Before saving the new preferred row: the unique key allows one preferred relation per variant.
                ProductVariantSupplier::query()->where('organization_id', $organization->getKey())->whereKey($unset['relation_id'])
                    ->update(['is_preferred' => false, 'preferred_variant_id' => null, 'updated_by_user_id' => $actor->getKey(), 'updated_at' => now()]);
            }

            if ($plan['mode'] === 'create') {
                $row->organization_id = $organization->getKey();
                $row->supplier_id = $plan['supplier']->getKey();
                $row->product_variant_id = $plan['variant']->getKey();
                $row->created_by_user_id = $actor->getKey();
            }
            foreach ($plan['proposed'] as $field => $value) $row->{$field} = $value;
            $row->preferred_variant_id = $plan['proposed']['is_preferred'] ? $plan['variant']->getKey() : null;
            $row->updated_by_user_id = $actor->getKey();
            $row->save();

            $this->recordAudit($actor, $organization, $row, $plan, $context);

            return $row->refresh();
        });
    }

    /**
     * Evaluates a create/update without writing. Throws ValidationException for
     * invalid requests (cross-organization ids resolve to "not found").
     *
     * @param array<string, mixed> $data
     * @return array{mode: string, relation: ProductVariantSupplier|null, supplier: Supplier, variant: ProductVariant, current: array<string, mixed>|null, proposed: array<string, mixed>, changed_fields: list<string>, preferred: array<string, mixed>}
     */
    public function plan(Organization $organization, array $data, ?ProductVariantSupplier $relation = null, bool $lock = false): array
    {
        $unknown = array_values(array_diff(array_keys($data), [...self::FIELDS, 'supplier_id', 'product_variant_id']));
        if ($unknown !== []) throw ValidationException::withMessages(['fields' => 'Champs non pris en charge : '.implode(', ', $unknown).'.']);
        $orgId = (int) $organization->getKey();

        if ($relation !== null) {
            if ((int) $relation->organization_id !== $orgId) throw (new ModelNotFoundException)->setModel(ProductVariantSupplier::class);
            [$supplierId, $variantId] = [(int) $relation->supplier_id, (int) $relation->product_variant_id];
            foreach (['supplier_id' => $supplierId, 'product_variant_id' => $variantId] as $key => $id) {
                if (array_key_exists($key, $data) && (int) $data[$key] !== $id) {
                    throw ValidationException::withMessages([$key => 'Le fournisseur et la variante d’une relation ne peuvent pas être changés : créez une autre relation.']);
                }
            }
        } else {
            Validator::make($data, [
                'supplier_id' => ['required', 'integer', 'min:1'],
                'product_variant_id' => ['required', 'integer', 'min:1'],
            ])->validate();
            [$supplierId, $variantId] = [(int) $data['supplier_id'], (int) $data['product_variant_id']];
        }

        // Lock order: variant, then its relations — serializes preferred changes per variant.
        $variant = ProductVariant::query()->where('organization_id', $orgId)->whereKey($variantId)->with('product:id,name')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw ValidationException::withMessages(['product_variant_id' => 'La variante est introuvable dans cette organisation.']);
        $supplier = Supplier::query()->where('organization_id', $orgId)->whereKey($supplierId)->first()
            ?? throw ValidationException::withMessages(['supplier_id' => 'Le fournisseur est introuvable dans cette organisation.']);
        $existing = ProductVariantSupplier::query()->where('organization_id', $orgId)
            ->when($relation !== null, fn ($query) => $query->whereKey($relation->getKey()), fn ($query) => $query->where('supplier_id', $supplierId)->where('product_variant_id', $variantId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($relation !== null && $existing === null) throw (new ModelNotFoundException)->setModel(ProductVariantSupplier::class);
        if ($relation === null && $existing?->status === ProductVariantSupplier::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['supplier_id' => 'Ce fournisseur est déjà lié à cette variante : modifiez la relation existante.']);
        }

        $mode = $existing === null ? 'create' : ($relation === null ? 'reactivate' : 'update');
        $current = $existing ? $this->values($existing) : null;
        $input = array_intersect_key($data, array_flip(self::FIELDS));
        if ($mode === 'reactivate') $input['status'] ??= ProductVariantSupplier::STATUS_ACTIVE;
        $candidate = [...($current ?? $this->defaults()), ...$input];

        $validated = Validator::make($candidate, self::rules())->validate();
        $proposed = $this->normalize($validated);
        if ($proposed['status'] === ProductVariantSupplier::STATUS_INACTIVE) {
            if (($input['is_preferred'] ?? false) === true) {
                throw ValidationException::withMessages(['is_preferred' => 'Une relation inactive ne peut pas être le fournisseur préféré.']);
            }
            // Deactivating a preferred relation clears the preference.
            $proposed['is_preferred'] = false;
        }

        $activating = $proposed['status'] === ProductVariantSupplier::STATUS_ACTIVE && ($current['status'] ?? null) !== ProductVariantSupplier::STATUS_ACTIVE;
        $becomesPreferred = $proposed['is_preferred'] && ! ($current['is_preferred'] ?? false);
        if (! $supplier->active && ($activating || $becomesPreferred)) {
            throw ValidationException::withMessages(['supplier_id' => 'Le fournisseur est inactif : réactivez-le avant de le lier ou de le rendre préféré.']);
        }

        $changed = $current === null
            ? array_keys(array_filter($proposed, fn ($value) => $value !== null && $value !== false))
            : array_keys(array_filter($proposed, fn ($value, $field) => $value !== $current[$field], ARRAY_FILTER_USE_BOTH));

        $currentPreferred = ProductVariantSupplier::query()->where('organization_id', $orgId)->where('product_variant_id', $variantId)
            ->where('is_preferred', true)->with('supplier:id,name')->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $replaced = $becomesPreferred && $currentPreferred !== null && $currentPreferred->getKey() !== $existing?->getKey() ? $currentPreferred : null;

        return [
            'mode' => $mode,
            'relation' => $existing,
            'supplier' => $supplier,
            'variant' => $variant,
            'current' => $current,
            'proposed' => $proposed,
            'changed_fields' => array_values($changed),
            'preferred' => [
                'current' => $currentPreferred ? $this->preferredSummary($currentPreferred) : null,
                'becomes_preferred' => $becomesPreferred,
                'loses_preferred' => ($current['is_preferred'] ?? false) && ! $proposed['is_preferred'],
                'unset' => $replaced ? $this->preferredSummary($replaced) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function values(ProductVariantSupplier $relation): array
    {
        return $this->normalize($relation->only(self::FIELDS));
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [...array_fill_keys(self::FIELDS, null), 'is_preferred' => false, 'status' => ProductVariantSupplier::STATUS_ACTIVE];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function normalize(array $values): array
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;
            $normalized[$field] = match (true) {
                $field === 'is_preferred' => (bool) $value,
                $field === 'status' => (string) ($value ?? ProductVariantSupplier::STATUS_ACTIVE),
                $field === 'currency_code' => $value === null || trim((string) $value) === '' ? strtoupper((string) config('platform.currency_code', 'MAD')) : strtoupper(trim((string) $value)),
                $value === null || (is_string($value) && trim($value) === '') => null,
                in_array($field, self::DECIMALS, true) => Decimal::nonNegative((string) $value, $field),
                in_array($field, ['lead_time_days', 'priority', 'quality_rating', 'reliability_rating'], true) => (int) $value,
                default => trim((string) $value),
            };
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function preferredSummary(ProductVariantSupplier $relation): array
    {
        return ['relation_id' => (int) $relation->getKey(), 'supplier_id' => (int) $relation->supplier_id, 'supplier_name' => $relation->supplier?->name];
    }

    /** @return array<string, mixed> */
    private function context(Supplier $supplier, ProductVariant $variant): array
    {
        return [
            'supplier_id' => (int) $supplier->getKey(), 'supplier_name' => $supplier->name,
            'product_variant_id' => (int) $variant->getKey(), 'sku' => $variant->sku,
            'product_id' => (int) $variant->product_id, 'product_name' => $variant->product?->name,
        ];
    }

    /**
     * One event per kind of change: created, activated/deactivated,
     * preferred_changed, and updated for the remaining commercial fields.
     *
     * @param array<string, mixed> $plan @param array<string, mixed> $context
     */
    private function recordAudit(User $actor, Organization $organization, ProductVariantSupplier $row, array $plan, array $context): void
    {
        $record = fn (string $event, array $old, array $new) => $this->audit->record($event, $actor, $organization, auditable: $row, oldValues: $old, newValues: [...$context, ...$new]);
        $preferred = $plan['preferred'];
        $preferredEvent = fn () => $record('product_variant_supplier.preferred_changed',
            ['preferred' => $preferred['current']],
            ['preferred' => $plan['proposed']['is_preferred'] ? ['relation_id' => (int) $row->getKey(), 'supplier_id' => (int) $row->supplier_id, 'supplier_name' => $plan['supplier']->name] : null]);

        if ($plan['mode'] === 'create') {
            $record('product_variant_supplier.created', [], array_filter($plan['proposed'], fn ($value) => $value !== null));
            if ($preferred['becomes_preferred'] && $preferred['unset'] !== null) $preferredEvent();

            return;
        }

        $changed = $plan['changed_fields'];
        if (in_array('status', $changed, true)) {
            $event = $plan['proposed']['status'] === ProductVariantSupplier::STATUS_ACTIVE ? 'activated' : 'deactivated';
            $record('product_variant_supplier.'.$event, ['status' => $plan['current']['status']], ['status' => $plan['proposed']['status']]);
        }
        if (in_array('is_preferred', $changed, true)) $preferredEvent();
        $other = array_values(array_diff($changed, ['status', 'is_preferred']));
        if ($other !== []) {
            $record('product_variant_supplier.updated', array_intersect_key($plan['current'], array_flip($other)), array_intersect_key($plan['proposed'], array_flip($other)));
        }
    }
}
