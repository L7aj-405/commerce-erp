<?php

namespace App\Services\SupplierInvoices;

use App\Enums\SupplierInvoiceStatus;
use App\Models\FinanceObligation;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\Finance\FinanceObligationCalculator;
use Illuminate\Support\Carbon;

/**
 * Inertia-ready arrays for supplier invoices (the A2 <-> A4 data contract). Money is always a decimal string;
 * nothing sensitive (supplier IBAN) is ever exposed here.
 */
final class SupplierInvoicePresenter
{
    public function __construct(
        private readonly SupplierInvoiceMatchingService $matching,
        private readonly FinanceObligationCalculator $obligations,
    ) {}

    /** @return array<string, mixed> */
    public function row(SupplierInvoice $invoice, int $warningsCount): array
    {
        return [
            'id' => $invoice->id,
            'internal_number' => $invoice->internal_number,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'status' => $invoice->status->value,
            'supplier' => ['id' => $invoice->supplier->id, 'name' => $invoice->supplier->name, 'ice' => $invoice->supplier->ice],
            'store' => ['id' => $invoice->store->id, 'name' => $invoice->store->name, 'code' => $invoice->store->code],
            'currency_code' => $invoice->currency_code,
            'total_ttc' => (string) $invoice->total_ttc,
            'match_status' => $invoice->match_status_at_approval?->value ?? $this->matching->status($invoice),
            'has_attachments' => ($invoice->attachments_count ?? 0) > 0,
            'warnings_count' => $warningsCount,
        ];
    }

    /** Requires lines, installments, attachments, supplier, store, purchaseOrder, users and variants loaded. @return array<string, mixed> */
    public function detail(SupplierInvoice $invoice): array
    {
        $obligations = $this->obligationsByInstallment($invoice);

        return [
            'id' => $invoice->id,
            'internal_number' => $invoice->internal_number,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'status' => $invoice->status->value,
            'supplier' => [
                'id' => $invoice->supplier->id, 'name' => $invoice->supplier->name, 'ice' => $invoice->supplier->ice,
                'tax_identifier' => $invoice->supplier->tax_identifier, 'rc_number' => $invoice->supplier->rc_number,
            ],
            'supplier_snapshot' => $invoice->approved_at === null ? null : [
                'name' => $invoice->supplier_name_snapshot, 'ice' => $invoice->supplier_ice_snapshot,
                'tax_identifier' => $invoice->supplier_tax_identifier_snapshot, 'rc_number' => $invoice->supplier_rc_snapshot,
            ],
            'store' => ['id' => $invoice->store->id, 'name' => $invoice->store->name, 'code' => $invoice->store->code],
            'purchase_order' => $invoice->purchaseOrder ? [
                'id' => $invoice->purchaseOrder->id, 'number' => $invoice->purchaseOrder->purchase_order_number,
            ] : null,
            'currency_code' => $invoice->currency_code,
            'subtotal_ht' => (string) $invoice->subtotal_ht,
            'total_tva' => (string) $invoice->total_tva,
            'rounding_adjustment' => (string) $invoice->rounding_adjustment,
            'total_ttc' => (string) $invoice->total_ttc,
            'control_total_ttc' => $invoice->control_total_ttc === null ? null : (string) $invoice->control_total_ttc,
            'notes' => $invoice->notes,
            'row_version' => $invoice->row_version,
            'created_by' => $invoice->createdBy?->name,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'lines' => $invoice->lines->map(fn ($line) => [
                'id' => $line->id,
                'position' => $line->position,
                'cost_nature' => $line->cost_nature->value,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price_ht' => (string) $line->unit_price_ht,
                'tax_rate_id' => $line->tax_rate_id,
                'tax_rate_percent' => (string) $line->tax_rate_percent,
                'tax_name' => $line->tax_name_snapshot,
                'line_ht' => (string) $line->line_ht,
                'line_tva' => (string) $line->line_tva,
                'line_ttc' => (string) $line->line_ttc,
                'product_variant' => $line->productVariant ? [
                    'id' => $line->productVariant->id, 'sku' => $line->productVariant->sku,
                    'label' => $line->productVariant->label, 'product_name' => $line->productVariant->product?->name,
                ] : null,
                'purchase_order_line_id' => $line->purchase_order_line_id,
            ])->values()->all(),
            'installments' => $invoice->installments->map(fn ($installment) => [
                'id' => $installment->id,
                'position' => $installment->position,
                'due_date' => $installment->due_date->toDateString(),
                'amount' => (string) $installment->amount,
                'planned_payment_method' => $installment->planned_payment_method?->value,
                'preferred_financial_account' => $installment->preferredFinancialAccount
                    ? $installment->preferredFinancialAccount->only(['id', 'name', 'code']) : null,
                'obligation' => $this->obligation($obligations->get($installment->id)),
            ])->values()->all(),
            'attachments' => $invoice->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime' => $attachment->mime,
                'size_bytes' => $attachment->size_bytes,
                'url' => route('finance.supplier-invoices.attachments.show', [$invoice, $attachment]),
            ])->values()->all(),
            'approval' => $invoice->approved_at === null ? null : [
                'approved_at' => $invoice->approved_at->toIso8601String(),
                'approved_by' => $invoice->approvedBy?->name,
                'match_status_at_approval' => $invoice->match_status_at_approval?->value,
                'match_note' => $invoice->match_note,
            ],
            'void' => $invoice->voided_at === null ? null : [
                'voided_at' => $invoice->voided_at->toIso8601String(),
                'voided_by' => $invoice->voidedBy?->name,
                'reason_code' => $invoice->void_reason_code?->value,
                'reason' => $invoice->void_reason,
            ],
        ];
    }

    /** @return array{status: string, lines: list<array{line_id: int, flags: list<string>}>} */
    public function matching(SupplierInvoice $invoice): array
    {
        return $this->matching->match($invoice);
    }

    /** `can.attach`: manage permission + membership of an active store, invoice not void. */
    public function canAttach(User $user, SupplierInvoice $invoice): bool
    {
        return $invoice->status !== SupplierInvoiceStatus::Void
            && $user->hasPermission($invoice->organization_id, 'supplier_invoices.manage')
            && app(SupplierInvoiceStoreAccess::class)->canEdit($user, $invoice->store);
    }

    /** One query for all the invoice's obligations (generated at approval; none while a draft). @return \Illuminate\Support\Collection<int, FinanceObligation> */
    private function obligationsByInstallment(SupplierInvoice $invoice)
    {
        $ids = $invoice->installments->pluck('id');
        if ($invoice->approved_at === null || $ids->isEmpty()) {
            return collect();
        }

        return FinanceObligation::query()->where('organization_id', $invoice->organization_id)
            ->whereIn('supplier_invoice_installment_id', $ids)->get()->keyBy('supplier_invoice_installment_id');
    }

    /** @return array{id: int, number: string, planned: string, paid: string, remaining: string, status: string}|null */
    private function obligation(?FinanceObligation $obligation): ?array
    {
        if ($obligation === null) {
            return null;
        }
        $values = $this->obligations->values($obligation, Carbon::today());

        return [
            'id' => $obligation->id, 'number' => $obligation->obligation_number,
            'planned' => $values['planned'], 'paid' => $values['paid'], 'remaining' => $values['remaining'], 'status' => $values['status'],
        ];
    }
}
