<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentSnapshotVerifier;
use App\Services\DocumentTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateFullInvoiceFromSalesOrderAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DocumentSnapshotVerifier $verifier,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentTemplateRegistry $templates,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, array $data = []): Invoice
    {
        $this->authorizeOrderDocument($actor, $order, 'invoices.create');
        $invoiceDate = $data['invoice_date'] ?? now()->toDateString();
        $this->authorizeBusinessDate($actor, $order->organization, $invoiceDate, 'invoices.backdate');

        return DB::transaction(function () use ($actor, $order, $data, $invoiceDate) {
            $order = SalesOrder::query()
                ->where('organization_id', $order->organization_id)->where('store_id', $order->store_id)
                ->whereKey($order->getKey())->lockForUpdate()->with(['lines', 'customer', 'organization', 'store'])->firstOrFail();
            if ($order->status !== SalesOrderStatus::Confirmed) {
                throw ValidationException::withMessages(['order' => 'Only a confirmed Sales Order can be invoiced.']);
            }
            if ($order->lines->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'A Sales Order must have lines before it can be invoiced.']);
            }
            if (Invoice::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
                ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value])->exists()) {
                throw ValidationException::withMessages(['order' => 'This Sales Order already has an active full Invoice.']);
            }

            $invoice = new Invoice;
            $invoice->organization_id = $order->organization_id;
            $invoice->store_id = $order->store_id;
            $invoice->sales_order_id = $order->getKey();
            $invoice->customer_id = $order->customer_id;
            $invoice->invoice_number = null;
            $invoice->status = InvoiceStatus::Draft;
            $invoice->currency_code = $order->currency_code;
            $invoice->invoice_date = $invoiceDate;
            $invoice->customer_name = $order->customer_name;
            $invoice->customer_company = $order->customer_company;
            $invoice->customer_email = $order->customer_email;
            $invoice->customer_phone = $order->customer_phone;
            $invoice->customer_tax_identifier = $order->customer?->tax_identifier;
            $invoice->billing_address = $order->customer?->billing_address;
            $invoice->subtotal_excl_tax = $order->subtotal_excl_tax;
            $invoice->discount_total = $order->discount_total;
            $invoice->tax_total = $order->tax_total;
            $invoice->total_incl_tax = $order->total_incl_tax;
            $invoice->notes = $data['notes'] ?? null;
            $invoice->seller_snapshot = $this->sellerProfile->snapshot($order->organization, $order->store);
            $invoice->template_version = $this->templates->currentInvoiceVersion();
            $invoice->save();

            foreach ($order->lines as $source) {
                $line = new InvoiceLine;
                $line->organization_id = $order->organization_id;
                $line->invoice_id = $invoice->getKey();
                $line->sales_order_line_id = $source->getKey();
                $line->product_variant_id = $source->product_variant_id;
                $line->position = $source->position;
                $line->line_type = $source->line_type;
                $line->description = $source->product_name;
                foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'quantity', 'unit_price_excl_tax', 'discount_type', 'discount_value', 'subtotal_excl_tax', 'discount_amount', 'taxable_amount', 'tax_name', 'tax_rate', 'tax_amount', 'total_incl_tax'] as $field) {
                    $line->{$field} = $source->{$field};
                }
                $line->save();
            }

            $this->verifier->verifyInvoice($invoice);
            $this->audit->record('invoice.draft_created', $actor, $order->organization, $order->store, $invoice, newValues: [
                'sales_order_number' => $order->order_number, 'invoice_date' => $invoiceDate,
                'status' => InvoiceStatus::Draft->value, 'total_incl_tax' => $invoice->total_incl_tax,
            ]);

            return $invoice->load(['lines', 'salesOrder']);
        });
    }
}
