<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Enums\QuotationLineType;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\ProductSupplier;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\QuotationSourcing;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record, inside the ERP only, that supplier availability must be checked for
 * one draft Devis line. Sends nothing, orders nothing, reserves nothing.
 */
class CreateQuotationSupplierRequestAction
{
    use MutatesQuotation;

    public function __construct(
        private readonly QuotationSourcing $sourcing,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{supplier_id: int|string, requested_quantity?: string|int|float|null, note?: string|null} $data */
    public function execute(User $actor, Quotation $quotation, QuotationLine $line, array $data): QuotationSupplierAvailabilityRequest
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.update');
        abort_unless($actor->hasPermission($quotation->organization, 'suppliers.view'), 403);

        return DB::transaction(function () use ($actor, $quotation, $line, $data) {
            $quotation = $this->lockDraft($quotation);
            $line = $this->lockLine($quotation, $line);

            $evaluation = $this->sourcing->evaluate($quotation, lockRequests: true)[$line->getKey()];
            if (! $evaluation['requires_sourcing']) {
                throw ValidationException::withMessages(['line' => 'Cette ligne est entièrement couverte par le stock interne.']);
            }

            $supplier = Supplier::query()
                ->where('organization_id', $quotation->organization_id)
                ->where('active', true)
                ->whereKey($data['supplier_id'])
                ->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Fournisseur introuvable ou inactif.']);
            }

            $quantity = filled($data['requested_quantity'] ?? null)
                ? Decimal::positive((string) $data['requested_quantity'], 'requested_quantity')
                : $evaluation['shortage'];
            if (Decimal::compare($quantity, (string) $line->quantity) > 0) {
                throw ValidationException::withMessages(['requested_quantity' => 'La quantité demandée ne peut pas dépasser la quantité de la ligne.']);
            }

            $duplicate = QuotationSupplierAvailabilityRequest::query()
                ->where('organization_id', $quotation->organization_id)
                ->where('quotation_line_id', $line->getKey())
                ->where('supplier_id', $supplier->getKey())
                ->where('status', Status::Pending->value)
                ->get()
                ->contains(fn (QuotationSupplierAvailabilityRequest $r) => $r->matchesLineArticle($line));
            if ($duplicate) {
                throw ValidationException::withMessages(['supplier_id' => 'Une demande est déjà en attente auprès de ce fournisseur pour cette ligne.']);
            }

            $request = new QuotationSupplierAvailabilityRequest;
            $request->organization_id = $quotation->organization_id;
            $request->store_id = $quotation->store_id;
            $request->quotation_id = $quotation->getKey();
            $request->quotation_line_id = $line->getKey();
            $request->supplier_id = $supplier->getKey();
            $request->product_variant_id = $line->product_variant_id;
            $request->non_stock_item_id = $line->non_stock_item_id;
            $request->requested_quantity = $quantity;
            $request->status = Status::Pending;
            $request->requested_by_user_id = $actor->getKey();
            $request->requested_at = now();
            $request->note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;
            $request->save();

            if ($line->line_type === QuotationLineType::Catalog && $line->product_variant_id) {
                $this->rememberProductSupplier($actor, $line, $supplier);
            }

            $this->audit->record('quotation_supplier_availability.requested', $actor, $quotation->organization, $quotation->store, $request, newValues: [
                'quotation_id' => $quotation->getKey(),
                'quotation_line_id' => $line->getKey(),
                'supplier_id' => $supplier->getKey(),
                'requested_quantity' => $request->requested_quantity,
                'shortage' => $evaluation['shortage'],
            ]);

            return $request;
        });
    }

    /** A supplier asked for a catalogue article becomes one of its known suppliers. */
    private function rememberProductSupplier(User $actor, QuotationLine $line, Supplier $supplier): void
    {
        $productId = $line->productVariant()->value('product_id');
        if (! $productId) {
            return;
        }

        ProductSupplier::query()->insertOrIgnore([
            'organization_id' => $line->organization_id,
            'product_id' => $productId,
            'supplier_id' => $supplier->getKey(),
            'created_by_user_id' => $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
