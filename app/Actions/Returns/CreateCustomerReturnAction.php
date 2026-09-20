<?php

namespace App\Actions\Returns;

use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerReturnNumberGenerator;
use App\Services\ReturnPolicyService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCustomerReturnAction
{
    public function __construct(
        private readonly ReturnPolicyService $policies,
        private readonly CustomerReturnNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{sales_order_line_id:int,quantity:string|int|float}> $requestedLines */
    public function execute(User $actor, SalesOrder $order, array $requestedLines, string $reason, string $disposition, string $operationId, bool $override = false, ?string $overrideReason = null): CustomerReturn
    {
        abort_unless($actor->active_organization_id === $order->organization_id && $actor->active_store_id === $order->store_id, 404);
        abort_unless($order->store()->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->id))->exists(), 404);
        abort_unless($actor->hasPermission($order->organization_id, 'sales_returns.create'), 403);

        return DB::transaction(function () use ($actor, $order, $requestedLines, $reason, $disposition, $operationId, $override, $overrideReason) {
            $order = SalesOrder::query()->where('organization_id', $order->organization_id)->where('store_id', $order->store_id)
                ->whereKey($order->id)->lockForUpdate()->with(['organization', 'store', 'lines'])->firstOrFail();
            if ($order->fulfillment_status !== SalesOrderFulfillmentStatus::Fulfilled || ! $order->fulfilled_at) {
                throw ValidationException::withMessages(['order' => 'Seule une commande physiquement livrée peut faire l’objet d’un retour.']);
            }
            if (! in_array($disposition, ['restock', 'damaged'], true)) {
                throw ValidationException::withMessages(['disposition' => 'Disposition de stock invalide.']);
            }
            $policy = $this->policies->evaluate($order);
            if (! $policy['enabled']) throw ValidationException::withMessages(['order' => 'Les retours sont désactivés.']);
            $overrideUsed = ! $policy['within_policy'];
            if ($overrideUsed) {
                if (! $override || ! $policy['manager_override_allowed'] || ! $actor->hasPermission($order->organization_id, 'sales_returns.override_policy')) {
                    throw ValidationException::withMessages(['order' => 'Délai de retour dépassé.']);
                }
                if (trim((string) $overrideReason) === '') throw ValidationException::withMessages(['override_reason' => 'Le motif du dépassement est obligatoire.']);
            }
            if ($policy['require_reason'] && trim($reason) === '') throw ValidationException::withMessages(['reason' => 'Le motif du retour est obligatoire.']);
            if ($requestedLines === []) throw ValidationException::withMessages(['lines' => 'Sélectionnez au moins un article.']);

            $existing = CustomerReturn::query()->where('organization_id', $order->organization_id)->where('store_id', $order->store_id)->where('client_operation_id', $operationId)->first();
            if ($existing) {
                if ($existing->sales_order_id !== $order->id) {
                    throw ValidationException::withMessages(['client_operation_id' => 'Cet identifiant d’opération a déjà été utilisé pour une autre commande.']);
                }

                return $existing;
            }

            $requested = collect($requestedLines)->keyBy('sales_order_line_id');
            $lines = SalesOrderLine::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->id)
                ->whereIn('id', $requested->keys())->orderBy('id')->lockForUpdate()->get();
            if ($lines->count() !== $requested->count()) abort(404);

            $allRemaining = true;
            $prepared = [];
            foreach ($lines as $line) {
                if (! $line->product_variant_id) throw ValidationException::withMessages(['lines' => 'Un article non stocké ne peut pas être reçu en retour physique.']);
                $quantity = Decimal::positive($requested[$line->id]['quantity'], 'lines.quantity');
                $previousLines = CustomerReturnLine::query()->where('organization_id', $order->organization_id)->where('sales_order_line_id', $line->id)
                    ->whereHas('customerReturn', fn ($query) => $query->whereIn('status', ['draft', 'received']))
                    ->lockForUpdate()->get(['quantity', 'subtotal_excl_tax', 'discount_amount', 'taxable_amount', 'tax_amount', 'total_incl_tax']);
                $already = $previousLines->reduce(fn (string $sum, CustomerReturnLine $returnLine) => Decimal::add($sum, $returnLine->quantity), '0.0000');
                $remaining = Decimal::subtract($line->quantity, $already);
                if (Decimal::compare($quantity, $remaining) > 0) throw ValidationException::withMessages(['lines' => "La quantité retournée dépasse le solde pour {$line->product_name}."]);
                if (Decimal::compare($quantity, $remaining) < 0) $allRemaining = false;
                $previousAmounts = collect(['subtotal_excl_tax', 'discount_amount', 'taxable_amount', 'tax_amount', 'total_incl_tax'])
                    ->mapWithKeys(fn (string $field) => [$field => $previousLines->reduce(
                        fn (string $sum, CustomerReturnLine $returnLine) => Decimal::add($sum, $returnLine->{$field}),
                        '0.0000',
                    )])->all();
                $prepared[] = [$line, $quantity, $this->amounts($line, $quantity, $remaining, $previousAmounts)];
            }
            $fullOrderReturn = $allRemaining && $this->allOrderQuantitiesSelected($order, $prepared);
            if (! $policy['allow_partial'] && ! $fullOrderReturn) throw ValidationException::withMessages(['lines' => 'Les retours partiels sont désactivés.']);
            if (! $policy['allow_full'] && $fullOrderReturn) throw ValidationException::withMessages(['lines' => 'Les retours complets sont désactivés.']);

            $customerReturn = new CustomerReturn;
            $customerReturn->organization_id = $order->organization_id;
            $customerReturn->store_id = $order->store_id;
            $customerReturn->sales_order_id = $order->id;
            $customerReturn->warehouse_id = $order->pos_warehouse_id;
            if (! $customerReturn->warehouse_id) throw ValidationException::withMessages(['order' => 'Aucun emplacement de retour n’est associé à cette commande.']);
            $customerReturn->return_number = $this->numbers->next($order->organization, now()->year);
            $customerReturn->client_operation_id = $operationId;
            $customerReturn->status = 'draft';
            $customerReturn->disposition = $disposition;
            $customerReturn->reason = trim($reason);
            $customerReturn->currency_code = $order->currency_code;
            $customerReturn->policy_snapshot = [...$policy, 'override_used' => $overrideUsed, 'override_actor_id' => $overrideUsed ? $actor->id : null, 'override_reason' => $overrideUsed ? trim((string) $overrideReason) : null];
            $customerReturn->created_by_user_id = $actor->id;
            foreach (['subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax'] as $field) $customerReturn->{$field} = '0.0000';
            $customerReturn->save();

            foreach ($prepared as $position => [$line, $quantity, $amounts]) {
                $returnLine = new CustomerReturnLine;
                $returnLine->organization_id = $order->organization_id;
                $returnLine->customer_return_id = $customerReturn->id;
                $returnLine->sales_order_line_id = $line->id;
                $returnLine->product_variant_id = $line->product_variant_id;
                $returnLine->position = $position + 1;
                foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'unit_price_excl_tax', 'unit_price_incl_tax', 'tax_name', 'tax_rate'] as $field) $returnLine->{$field} = $line->{$field};
                $returnLine->quantity = $quantity;
                foreach ($amounts as $field => $amount) $returnLine->{$field} = $amount;
                $returnLine->save();
                foreach (['subtotal_excl_tax', 'discount_amount', 'tax_amount', 'total_incl_tax'] as $field) {
                    $target = match ($field) {
                        'discount_amount' => 'discount_total',
                        'tax_amount' => 'tax_total',
                        default => $field,
                    };
                    $customerReturn->{$target} = Decimal::add($customerReturn->{$target}, $amounts[$field]);
                }
            }
            $customerReturn->save();

            Invoice::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->id)->where('status', InvoiceStatus::Draft->value)
                ->update(['status' => InvoiceStatus::Cancelled->value, 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id, 'cancellation_reason' => 'Brouillon devenu obsolète après création du retour '.$customerReturn->return_number]);
            $this->createCreditNoteDrafts($actor, $customerReturn, $order);
            $this->audit->record('sales_return.created', $actor, $order->organization, $order->store, $customerReturn, newValues: ['return_number' => $customerReturn->return_number, 'order_number' => $order->order_number, 'total_incl_tax' => $customerReturn->total_incl_tax, 'policy_snapshot' => $customerReturn->policy_snapshot]);
            if ($overrideUsed) $this->audit->record('sales_return.policy_overridden', $actor, $order->organization, $order->store, $customerReturn, newValues: ['original_deadline' => $policy['deadline'], 'reason' => trim((string) $overrideReason)]);

            return $customerReturn->load(['lines', 'creditNotes.lines']);
        }, 3);
    }

    /** @return array<string,string> */
    private function amounts(SalesOrderLine $line, string $quantity, string $remainingQuantity, array $previousAmounts): array
    {
        // The last accepted partial return absorbs the exact residual for each
        // historical amount. Repeated proportional returns can otherwise leave
        // a 0.0001 rounding drift and fail to reconcile to the source line.
        $completesLine = Decimal::compare($quantity, $remainingQuantity) === 0;
        $allocate = fn (string $field) => $completesLine
            ? Decimal::subtract($line->{$field}, $previousAmounts[$field])
            : Decimal::multiply(Decimal::divide($line->{$field}, $line->quantity), $quantity);

        return ['subtotal_excl_tax' => $allocate('subtotal_excl_tax'), 'discount_amount' => $allocate('discount_amount'), 'taxable_amount' => $allocate('taxable_amount'), 'tax_amount' => $allocate('tax_amount'), 'total_incl_tax' => $allocate('total_incl_tax')];
    }

    private function allOrderQuantitiesSelected(SalesOrder $order, array $prepared): bool
    {
        return count($prepared) === $order->lines->whereNotNull('product_variant_id')->count();
    }

    private function createCreditNoteDrafts(User $actor, CustomerReturn $customerReturn, SalesOrder $order): void
    {
        $returnLines = $customerReturn->lines()->get()->keyBy('sales_order_line_id');
        $invoiceLines = InvoiceLine::query()->where('organization_id', $order->organization_id)->whereIn('sales_order_line_id', $returnLines->keys())
            ->whereHas('invoice', fn ($query) => $query->where('sales_order_id', $order->id)->where('status', InvoiceStatus::Issued->value))->with('invoice')->get()->groupBy('invoice_id');
        foreach ($invoiceLines as $group) {
            $invoice = $group->first()->invoice;
            $note = new CreditNote;
            $note->organization_id = $order->organization_id; $note->store_id = $order->store_id; $note->customer_return_id = $customerReturn->id;
            $note->invoice_id = $invoice->id; $note->sales_order_id = $order->id; $note->status = 'draft'; $note->credit_note_date = now()->toDateString();
            $note->currency_code = $invoice->currency_code; $note->reason = $customerReturn->reason; $note->seller_snapshot = $invoice->seller_snapshot;
            $note->customer_snapshot = ['name' => $invoice->customer_name, 'company' => $invoice->customer_company, 'email' => $invoice->customer_email, 'phone' => $invoice->customer_phone, 'tax_identifier' => $invoice->customer_tax_identifier, 'billing_address' => $invoice->billing_address];
            foreach (['subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax'] as $field) $note->{$field} = '0.0000';
            $note->save();
            foreach ($group as $position => $invoiceLine) {
                $source = $returnLines[$invoiceLine->sales_order_line_id];
                $line = new CreditNoteLine;
                $line->organization_id = $order->organization_id; $line->credit_note_id = $note->id; $line->customer_return_line_id = $source->id; $line->position = $position + 1;
                $line->description = $source->product_name.($source->variant_name ? ' — '.$source->variant_name : '');
                foreach (['reference', 'unit_label', 'quantity', 'unit_price_excl_tax', 'unit_price_incl_tax', 'subtotal_excl_tax', 'discount_amount', 'taxable_amount', 'tax_name', 'tax_rate', 'tax_amount', 'total_incl_tax'] as $field) $line->{$field} = $source->{$field};
                $line->save();
                $note->subtotal_excl_tax = Decimal::add($note->subtotal_excl_tax, $line->subtotal_excl_tax);
                $note->discount_total = Decimal::add($note->discount_total, $line->discount_amount);
                $note->tax_total = Decimal::add($note->tax_total, $line->tax_amount);
                $note->total_incl_tax = Decimal::add($note->total_incl_tax, $line->total_incl_tax);
            }
            $note->save();
            $this->audit->record('credit_note.created', $actor, $order->organization, $order->store, $note, newValues: [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_version' => $invoice->version,
                'order_number' => $order->order_number,
                'return_number' => $customerReturn->return_number,
                'total_incl_tax' => $note->total_incl_tax,
            ]);
        }
    }
}
