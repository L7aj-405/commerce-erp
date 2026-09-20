<?php

namespace App\Actions\Returns;

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CreditNoteNumberGenerator;
use App\Services\InventoryBalanceLocker;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveCustomerReturnAction
{
    public function __construct(
        private readonly InventoryBalanceLocker $balances,
        private readonly CreditNoteNumberGenerator $numbers,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, CustomerReturn $customerReturn): CustomerReturn
    {
        abort_unless($actor->active_organization_id === $customerReturn->organization_id && $actor->active_store_id === $customerReturn->store_id, 404);
        abort_unless($customerReturn->store()->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->id))->exists(), 404);
        abort_unless($actor->hasPermission($customerReturn->organization_id, 'sales_returns.receive'), 403);

        return DB::transaction(function () use ($actor, $customerReturn) {
            $customerReturn = CustomerReturn::query()->where('organization_id', $customerReturn->organization_id)->where('store_id', $customerReturn->store_id)
                ->whereKey($customerReturn->id)->lockForUpdate()->with(['organization', 'store', 'warehouse', 'lines.productVariant', 'creditNotes.lines', 'creditNotes.invoice'])->firstOrFail();
            if ($customerReturn->status === 'received') return $customerReturn;
            if ($customerReturn->status !== 'draft') throw ValidationException::withMessages(['return' => 'Seul un retour brouillon peut être réceptionné.']);
            if ($customerReturn->creditNotes->isNotEmpty()) abort_unless($actor->hasPermission($customerReturn->organization_id, 'credit_notes.issue'), 403);
            if ($customerReturn->creditNotes->contains(fn (CreditNote $note) => $note->invoice?->status !== InvoiceStatus::Issued)) {
                throw ValidationException::withMessages([
                    'credit_note' => 'La facture ciblée par cet avoir n’est plus la version officielle courante. Annulez ce retour brouillon et recréez-le depuis la facture actuelle.',
                ]);
            }

            if ($customerReturn->disposition === 'restock') {
                foreach ($customerReturn->lines as $line) {
                    $balance = $this->balances->lock($customerReturn->organization, $customerReturn->warehouse, $line->productVariant);
                    $before = $balance->on_hand;
                    $after = Decimal::add($before, $line->quantity);
                    $movement = new InventoryMovement;
                    $movement->organization_id = $customerReturn->organization_id;
                    $movement->warehouse_id = $customerReturn->warehouse_id;
                    $movement->product_variant_id = $line->product_variant_id;
                    $movement->movement_type = InventoryMovementType::CustomerReturn;
                    $movement->quantity = $line->quantity;
                    $movement->quantity_before = $before;
                    $movement->quantity_after = $after;
                    $movement->reference_type = CustomerReturn::class;
                    $movement->reference_id = $customerReturn->id;
                    $movement->reference = $customerReturn->return_number;
                    $movement->reason = $customerReturn->reason;
                    $movement->metadata = ['customer_return_line_id' => $line->id, 'disposition' => 'restock'];
                    $movement->performed_by_user_id = $actor->id;
                    $movement->save();
                    $balance->on_hand = $after;
                    $balance->save();
                }
            }

            $customerReturn->status = 'received';
            $customerReturn->received_at = now();
            $customerReturn->received_by_user_id = $actor->id;
            $customerReturn->save();
            $this->payments->recalculate($customerReturn->salesOrder);
            $this->audit->record('sales_return.received', $actor, $customerReturn->organization, $customerReturn->store, $customerReturn, oldValues: ['status' => 'draft'], newValues: ['status' => 'received', 'disposition' => $customerReturn->disposition, 'stock_reintroduced' => $customerReturn->disposition === 'restock']);

            foreach ($customerReturn->creditNotes as $note) $this->issueCreditNote($actor, $note, $customerReturn);

            return $customerReturn->fresh(['lines', 'creditNotes.lines', 'refunds']);
        }, 3);
    }

    private function issueCreditNote(User $actor, CreditNote $note, CustomerReturn $customerReturn): void
    {
        $note = CreditNote::query()->where('organization_id', $customerReturn->organization_id)->whereKey($note->id)->lockForUpdate()->with(['organization', 'invoice'])->firstOrFail();
        if ($note->status === 'issued') return;
        if ($note->status !== 'draft') throw ValidationException::withMessages(['credit_note' => 'Seul un avoir brouillon peut être émis.']);
        $note->credit_note_date = now()->toDateString();
        $note->credit_note_number = $this->numbers->next($note->organization, (int) $note->credit_note_date->format('Y'));
        $note->status = 'issued';
        $note->issued_at = now();
        $note->issued_by_user_id = $actor->id;
        $note->save();
        $this->audit->record('credit_note.issued', $actor, $customerReturn->organization, $customerReturn->store, $note, newValues: [
            'credit_note_number' => $note->credit_note_number,
            'credit_note_date' => $note->credit_note_date->toDateString(),
            'invoice_id' => $note->invoice_id,
            'invoice_number' => $note->invoice->invoice_number,
            'invoice_version' => $note->invoice->version,
            'order_number' => $customerReturn->salesOrder->order_number,
            'return_number' => $customerReturn->return_number,
            'total_incl_tax' => $note->total_incl_tax,
        ]);
    }
}
