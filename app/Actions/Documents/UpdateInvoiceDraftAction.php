<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceDraftAction
{
    use AuthorizesDocumentAction;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Invoice $invoice, array $data): Invoice
    {
        $this->authorizeInvoice($actor, $invoice, 'invoices.update_draft');
        $date = $data['invoice_date'];
        $this->authorizeBusinessDate($actor, $invoice->organization, $date, 'invoices.backdate');

        return DB::transaction(function () use ($actor, $invoice, $data, $date) {
            $invoice = Invoice::query()->where('organization_id', $invoice->organization_id)->where('store_id', $invoice->store_id)
                ->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => 'Only a draft Invoice can be updated.']);
            }
            $oldDate = $invoice->invoice_date->toDateString();
            $updatedFields = [];
            foreach (['customer_name', 'customer_company', 'customer_email', 'customer_phone', 'customer_tax_identifier', 'billing_address', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    if ($invoice->{$field} !== $data[$field]) {
                        $updatedFields[] = $field;
                    }
                    $invoice->{$field} = $data[$field];
                }
            }
            $invoice->invoice_date = $date;
            $invoice->save();
            $this->audit->record('invoice.draft_updated', $actor, $invoice->organization, $invoice->store, $invoice, oldValues: [
                'invoice_date' => $oldDate,
            ], newValues: [
                'invoice_date' => $date, 'updated_fields' => $updatedFields, 'sales_order_number' => $invoice->salesOrder->order_number,
                'status' => InvoiceStatus::Draft->value, 'total_incl_tax' => $invoice->total_incl_tax,
            ]);

            return $invoice;
        });
    }
}
