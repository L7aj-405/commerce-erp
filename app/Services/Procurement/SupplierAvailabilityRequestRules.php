<?php

namespace App\Services\Procurement;

use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The business rules of a pre-sale supplier availability request, shared by
 * every context that gathers that evidence (draft Devis line, POS cart line).
 * The callers own the transaction, the row locks of their context, their
 * authorization and their audit trail; this class never decides who may act.
 *
 * - creating: an active supplier of the organisation, a positive quantity that
 *   defaults to the current shortage and never exceeds the line quantity, no
 *   second PENDING request to the same supplier for the same line/article;
 * - recording: the status is derived from the confirmed quantity versus the
 *   requested quantity (full → available, some → partial, none → unavailable);
 * - cancelling: a request is withdrawn, never deleted.
 */
final class SupplierAvailabilityRequestRules
{
    public function activeSupplier(int $organizationId, mixed $supplierId): Supplier
    {
        $supplier = Supplier::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->whereKey($supplierId)
            ->first();
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Fournisseur introuvable ou inactif.']);
        }

        return $supplier;
    }

    /** @param array{requested_quantity?: string|int|float|null} $data */
    public function requestedQuantity(array $data, string $shortage, string $lineQuantity): string
    {
        $quantity = filled($data['requested_quantity'] ?? null)
            ? Decimal::positive((string) $data['requested_quantity'], 'requested_quantity')
            : Decimal::normalize($shortage);
        if (Decimal::compare($quantity, $lineQuantity) > 0) {
            throw ValidationException::withMessages(['requested_quantity' => 'La quantité demandée ne peut pas dépasser la quantité de la ligne.']);
        }

        return $quantity;
    }

    /** @param Collection<int, QuotationSupplierAvailabilityRequest> $lineRequests every request of the line (any status) */
    public function assertNoPendingDuplicate(Collection $lineRequests, Supplier $supplier, ?int $productVariantId, ?int $nonStockItemId): void
    {
        $duplicate = $lineRequests->contains(fn (QuotationSupplierAvailabilityRequest $r) => (int) $r->supplier_id === (int) $supplier->getKey()
            && $r->status === Status::Pending
            && $r->matchesArticle($productVariantId, $nonStockItemId));
        if ($duplicate) {
            throw ValidationException::withMessages(['supplier_id' => 'Une demande est déjà en attente auprès de ce fournisseur pour cette ligne.']);
        }
    }

    /**
     * A new PENDING request. `$context` sets the line it belongs to
     * (organization_id, store_id and quotation_* or sales_order_* ids).
     *
     * @param  array<string, int|null>  $context
     */
    public function newPendingRequest(User $actor, array $context, Supplier $supplier, ?int $productVariantId, ?int $nonStockItemId, string $quantity, ?string $note): QuotationSupplierAvailabilityRequest
    {
        $request = new QuotationSupplierAvailabilityRequest;
        foreach ($context as $column => $value) {
            $request->{$column} = $value;
        }
        $request->supplier_id = $supplier->getKey();
        $request->product_variant_id = $productVariantId;
        $request->non_stock_item_id = $nonStockItemId;
        $request->requested_quantity = $quantity;
        $request->status = Status::Pending;
        $request->requested_by_user_id = $actor->getKey();
        $request->requested_at = now();
        $request->note = filled($note) ? trim((string) $note) : null;
        $request->save();

        if ($productVariantId !== null) {
            $this->rememberProductSupplier($actor, (int) $context['organization_id'], $productVariantId, $supplier);
        }

        return $request;
    }

    /**
     * Apply a supplier answer to a locked request. Returns the previous status.
     *
     * @param  array{outcome: string, confirmed_quantity?: string|int|float|null, note?: string|null}  $data
     */
    public function recordOutcome(User $actor, QuotationSupplierAvailabilityRequest $request, array $data): string
    {
        if (! $request->status->isResolvable()) {
            throw ValidationException::withMessages(['status' => 'Cette demande ne peut plus être modifiée.']);
        }

        if ($data['outcome'] === 'unavailable') {
            $confirmed = '0.0000';
        } else {
            $confirmed = filled($data['confirmed_quantity'] ?? null)
                ? Decimal::nonNegative((string) $data['confirmed_quantity'], 'confirmed_quantity')
                : Decimal::normalize((string) $request->requested_quantity);
        }

        $status = match (true) {
            Decimal::compare($confirmed, '0') <= 0 => Status::Unavailable,
            Decimal::compare($confirmed, (string) $request->requested_quantity) >= 0 => Status::Available,
            default => Status::Partial,
        };

        $previous = $request->status->value;
        $request->status = $status;
        $request->confirmed_quantity = $confirmed;
        $request->resolved_by_user_id = $actor->getKey();
        $request->resolved_at = now();
        if (array_key_exists('note', $data)) {
            $request->note = filled($data['note']) ? trim((string) $data['note']) : $request->note;
        }
        $request->save();

        return $previous;
    }

    /** Withdraw a locked request. Returns the previous status. */
    public function cancel(User $actor, QuotationSupplierAvailabilityRequest $request): string
    {
        if ($request->status === Status::Cancelled) {
            throw ValidationException::withMessages(['status' => 'Cette demande est déjà annulée.']);
        }

        $previous = $request->status->value;
        $request->status = Status::Cancelled;
        $request->resolved_by_user_id = $actor->getKey();
        $request->resolved_at = now();
        $request->save();

        return $previous;
    }

    /** A supplier asked for a catalogue article becomes one of its known suppliers. */
    private function rememberProductSupplier(User $actor, int $organizationId, int $productVariantId, Supplier $supplier): void
    {
        $productId = ProductVariant::query()->where('organization_id', $organizationId)->whereKey($productVariantId)->value('product_id');
        if (! $productId) {
            return;
        }

        ProductSupplier::query()->insertOrIgnore([
            'organization_id' => $organizationId,
            'product_id' => $productId,
            'supplier_id' => $supplier->getKey(),
            'created_by_user_id' => $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
