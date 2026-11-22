<?php

namespace App\Actions\SupplierInvoices;

use App\Actions\SupplierInvoices\Concerns\AuthorizesSupplierInvoiceAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceCostNature;
use App\Enums\SupplierInvoiceStatus;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceInstallment;
use App\Models\SupplierInvoiceLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceNumberGenerator;
use App\Services\SupplierInvoices\SupplierInvoiceCalculator;
use App\Services\SupplierInvoices\SupplierInvoiceDuplicateGuard;
use App\Services\SupplierInvoices\SupplierInvoiceScheduleBuilder;
use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use App\Services\SupplierInvoices\SupplierInvoiceWarnings;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a supplier invoice DRAFT in one transaction. A draft creates no payable: obligations
 * only appear when the invoice is approved (ApproveSupplierInvoiceAction). Soft warnings are returned,
 * never thrown; the hard duplicate rule is the unique (organization, supplier, dedupe_key) key.
 */
class SaveSupplierInvoiceDraftAction
{
    use AuthorizesSupplierInvoiceAction;

    public function __construct(
        private readonly FinanceNumberGenerator $numbers,
        private readonly SupplierInvoiceCalculator $calculator,
        private readonly SupplierInvoiceDuplicateGuard $duplicates,
        private readonly SupplierInvoiceScheduleBuilder $schedules,
        private readonly SupplierInvoiceSettings $settings,
        private readonly SupplierInvoiceWarnings $warnings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  store_id, supplier_id, purchase_order_id?, invoice_number, invoice_date (Y-m-d), currency_code,
     *                                      notes?, rounding_adjustment?, control_total_ttc?, client_operation_id? (create), row_version (update),
     *                                      lines[], installments[]?
     * @return array{invoice: SupplierInvoice, warnings: list<array{code: string, message: string}>, created: bool}
     */
    public function execute(User $actor, Organization $organization, array $data, ?SupplierInvoice $invoice = null): array
    {
        $this->assertInvoiceAccess($actor, $organization, 'supplier_invoices.manage');
        if ($invoice !== null && (int) $invoice->organization_id !== (int) $organization->getKey()) {
            abort(404);
        }

        $store = $invoice?->store ?? Store::query()->where('organization_id', $organization->getKey())->whereKey($data['store_id'] ?? 0)->first();
        if (! $store) {
            throw ValidationException::withMessages(['store_id' => 'Le magasin sélectionné est invalide.']);
        }
        $this->assertStoreAccess($actor, $store, $invoice ? 'canEdit' : 'canCreate');
        if ($invoice !== null && (int) ($data['store_id'] ?? $store->getKey()) !== (int) $store->getKey()) {
            throw ValidationException::withMessages(['store_id' => 'Le magasin d’une facture ne peut pas être modifié.']);
        }

        try {
            return DB::transaction(fn () => $this->save($actor, $organization, $store, $data, $invoice), 3);
        } catch (UniqueConstraintViolationException $exception) {
            $operationId = $data['client_operation_id'] ?? null;
            if ($invoice === null && $operationId) {
                $existing = SupplierInvoice::query()->where('organization_id', $organization->getKey())->where('client_operation_id', $operationId)->first();
                if ($existing) {
                    return $this->idempotentResult($actor, $existing);
                }
            }

            throw ValidationException::withMessages(['invoice_number' => 'Une facture de ce fournisseur avec ce numéro existe déjà pour cette année.']);
        }
    }

    /** @return array{invoice: SupplierInvoice, warnings: list<array{code: string, message: string}>, created: bool} */
    private function save(User $actor, Organization $organization, Store $store, array $data, ?SupplierInvoice $invoice): array
    {
        $operationId = $data['client_operation_id'] ?? null;
        if ($invoice === null && $operationId) {
            $existing = SupplierInvoice::query()->where('organization_id', $organization->getKey())->where('client_operation_id', $operationId)->first();
            if ($existing) {
                return $this->idempotentResult($actor, $existing);
            }
        }

        $old = [];
        if ($invoice !== null) {
            $invoice = SupplierInvoice::query()->where('organization_id', $organization->getKey())->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($invoice->status !== SupplierInvoiceStatus::Draft || $invoice->approved_at !== null) {
                throw ValidationException::withMessages(['invoice' => 'Seul un brouillon peut être modifié.']);
            }
            if ((int) ($data['row_version'] ?? 0) !== (int) $invoice->row_version) {
                throw ValidationException::withMessages(['row_version' => 'Cette facture a été modifiée entre-temps. Rechargez la page avant d’enregistrer.']);
            }
            $old = $this->auditValues($invoice);
        }

        $supplier = Supplier::query()->where('organization_id', $organization->getKey())->whereKey($data['supplier_id'] ?? 0)->first();
        if (! $supplier || ! $supplier->active) {
            throw ValidationException::withMessages(['supplier_id' => 'Le fournisseur sélectionné est invalide ou inactif.']);
        }

        $currency = strtoupper((string) ($data['currency_code'] ?? ''));
        $purchaseOrder = $this->purchaseOrder($organization, $supplier, $store, $currency, $data['purchase_order_id'] ?? null);
        $lines = $this->lines($organization, $purchaseOrder, $data['lines'] ?? []);

        $totals = $this->calculator->calculate(
            array_map(fn (array $line) => ['quantity' => $line['quantity'], 'unit_price_ht' => $line['unit_price_ht'], 'tax_rate_percent' => $line['tax_rate_percent']], $lines),
            (string) ($data['rounding_adjustment'] ?? '0'),
            $this->settings->roundingTolerance(),
        );
        $control = ($data['control_total_ttc'] ?? null) === null || $data['control_total_ttc'] === '' ? null : Decimal::nonNegative((string) $data['control_total_ttc'], 'control_total_ttc');

        $invoiceDate = (string) ($data['invoice_date'] ?? '');
        $number = trim((string) ($data['invoice_number'] ?? ''));
        if (mb_strlen($number) > 64) {
            throw ValidationException::withMessages(['invoice_number' => 'Le numéro de facture ne peut pas dépasser 64 caractères.']);
        }
        $installments = ($data['installments'] ?? []) === [] ? [] : $this->schedules->validate($invoiceDate, $totals['total_ttc'], $data['installments']);
        $this->assertAccounts($organization, $installments);

        $dedupeKey = $this->duplicates->key($number, $invoiceDate);
        if (strlen($dedupeKey) > 100) {
            throw ValidationException::withMessages(['invoice_number' => 'Le numéro de facture est trop long.']);
        }
        $duplicate = SupplierInvoice::query()->where('organization_id', $organization->getKey())->where('supplier_id', $supplier->getKey())
            ->where('dedupe_key', $dedupeKey)->when($invoice, fn ($query) => $query->where('id', '!=', $invoice->getKey()))->first(['id', 'internal_number']);
        if ($duplicate) {
            throw ValidationException::withMessages(['invoice_number' => "Une facture n° {$number} existe déjà pour ce fournisseur cette année ({$duplicate->internal_number})."]);
        }

        $created = $invoice === null;
        if ($created) {
            $invoice = new SupplierInvoice;
            $invoice->organization_id = $organization->getKey();
            $invoice->store_id = $store->getKey();
            $invoice->internal_number = $this->numbers->nextSupplierInvoice($organization, (int) substr($invoiceDate, 0, 4));
            $invoice->client_operation_id = $operationId ?: null;
            $invoice->created_by_user_id = $actor->getKey();
            $invoice->status = SupplierInvoiceStatus::Draft;
            $invoice->row_version = 1;
        } else {
            $invoice->row_version = (int) $invoice->row_version + 1;
        }
        $invoice->supplier_id = $supplier->getKey();
        $invoice->purchase_order_id = $purchaseOrder?->getKey();
        $invoice->invoice_number = $number;
        $invoice->dedupe_key = $dedupeKey;
        $invoice->invoice_date = $invoiceDate;
        $invoice->currency_code = $currency;
        $invoice->subtotal_ht = $totals['subtotal_ht'];
        $invoice->total_tva = $totals['total_tva'];
        $invoice->rounding_adjustment = $totals['rounding_adjustment'];
        $invoice->total_ttc = $totals['total_ttc'];
        $invoice->control_total_ttc = $control;
        $invoice->notes = ($data['notes'] ?? null) ?: null;
        $invoice->last_saved_by_user_id = $actor->getKey();
        $invoice->save();

        if (! $created) {
            SupplierInvoiceLine::query()->where('organization_id', $organization->getKey())->where('supplier_invoice_id', $invoice->getKey())->delete();
            SupplierInvoiceInstallment::query()->where('organization_id', $organization->getKey())->where('supplier_invoice_id', $invoice->getKey())->delete();
        }
        foreach ($lines as $position => $line) {
            $row = new SupplierInvoiceLine;
            $row->organization_id = $organization->getKey();
            $row->supplier_invoice_id = $invoice->getKey();
            $row->position = $position + 1;
            $row->cost_nature = $line['cost_nature'];
            $row->product_variant_id = $line['product_variant_id'];
            $row->purchase_order_line_id = $line['purchase_order_line_id'];
            $row->description = $line['description'];
            $row->quantity = $line['quantity'];
            $row->unit_price_ht = $line['unit_price_ht'];
            $row->tax_rate_id = $line['tax_rate_id'];
            $row->tax_rate_percent = $line['tax_rate_percent'];
            $row->tax_name_snapshot = $line['tax_name'];
            $row->line_ht = $totals['lines'][$position]['line_ht'];
            $row->line_tva = $totals['lines'][$position]['line_tva'];
            $row->line_ttc = $totals['lines'][$position]['line_ttc'];
            $row->save();
        }
        foreach ($installments as $installment) {
            $row = new SupplierInvoiceInstallment;
            $row->organization_id = $organization->getKey();
            $row->supplier_invoice_id = $invoice->getKey();
            $row->position = $installment['position'];
            $row->due_date = $installment['due_date'];
            $row->amount = $installment['amount'];
            $row->planned_payment_method = ($installment['planned_payment_method'] ?? null) ?: null;
            $row->preferred_financial_account_id = $installment['preferred_financial_account_id'] ?? null;
            $row->save();
        }

        $invoice = $invoice->fresh(['lines', 'installments', 'supplier', 'store']);
        $new = $this->auditValues($invoice) + ['line_count' => count($lines), 'installment_count' => count($installments)];
        $this->audit->record($created ? 'supplier_invoice.draft_created' : 'supplier_invoice.draft_updated', $actor, $organization, $store, $invoice, $old, $new);

        return ['invoice' => $invoice, 'warnings' => $this->warnings->forInvoice($invoice), 'created' => $created];
    }

    /** @return array{invoice: SupplierInvoice, warnings: list<array{code: string, message: string}>, created: bool} */
    private function idempotentResult(User $actor, SupplierInvoice $existing): array
    {
        if ((int) $existing->created_by_user_id !== (int) $actor->getKey()) {
            throw ValidationException::withMessages(['client_operation_id' => 'Cet identifiant d’opération est déjà utilisé.']);
        }
        $existing->load(['lines', 'installments', 'supplier', 'store']);

        return ['invoice' => $existing, 'warnings' => $this->warnings->forInvoice($existing), 'created' => false];
    }

    private function purchaseOrder(Organization $organization, Supplier $supplier, Store $store, string $currency, mixed $id): ?PurchaseOrder
    {
        if ($id === null || $id === '') {
            return null;
        }
        $order = PurchaseOrder::query()->where('organization_id', $organization->getKey())->whereKey($id)->first();
        if (! $order) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Le bon de commande sélectionné est invalide.']);
        }
        if ((int) $order->supplier_id !== (int) $supplier->getKey() || (int) $order->store_id !== (int) $store->getKey()) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Le bon de commande doit concerner le même fournisseur et le même magasin que la facture.']);
        }
        if (in_array($order->status, [PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Draft], true)) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Le bon de commande est annulé ou n’a pas été passé.']);
        }
        if ($order->currency_code !== $currency) {
            throw ValidationException::withMessages(['currency_code' => 'La devise doit correspondre à celle du bon de commande.']);
        }

        return $order;
    }

    /**
     * Resolve and validate every line reference; returns the lines with the tax snapshot.
     *
     * @param  list<array<string, mixed>>  $input
     * @return list<array<string, mixed>>
     */
    private function lines(Organization $organization, ?PurchaseOrder $purchaseOrder, array $input): array
    {
        if ($input === []) {
            throw ValidationException::withMessages(['lines' => 'Ajoutez au moins une ligne.']);
        }
        $input = array_values($input);
        $orgId = $organization->getKey();
        $variantIds = array_values(array_filter(array_column($input, 'product_variant_id')));
        $variants = ProductVariant::query()->where('organization_id', $orgId)->whereIn('id', $variantIds)->pluck('id')->flip();
        $taxRates = TaxRate::query()->where('organization_id', $orgId)->whereIn('id', array_filter(array_column($input, 'tax_rate_id')))->get()->keyBy('id');
        $poLineIds = array_values(array_filter(array_column($input, 'purchase_order_line_id')));
        $poLines = $purchaseOrder
            ? PurchaseOrderLine::query()->where('organization_id', $orgId)->where('purchase_order_id', $purchaseOrder->getKey())->whereIn('id', $poLineIds)->get()->keyBy('id')
            : collect();

        $out = [];
        foreach ($input as $index => $line) {
            $field = "lines.{$index}";
            $nature = SupplierInvoiceCostNature::tryFrom((string) ($line['cost_nature'] ?? 'inventory'));
            if (! $nature) {
                throw ValidationException::withMessages(["{$field}.cost_nature" => 'La nature du coût est invalide.']);
            }
            $variantId = ($line['product_variant_id'] ?? null) ?: null;
            $poLineId = ($line['purchase_order_line_id'] ?? null) ?: null;
            $variantFromPurchaseOrder = false;
            if ($poLineId !== null) {
                $poLine = $poLines->get((int) $poLineId);
                if (! $poLine) {
                    throw ValidationException::withMessages(["{$field}.purchase_order_line_id" => 'La ligne du bon de commande n’appartient pas au bon de commande de la facture.']);
                }
                if ($variantId !== null && (int) $variantId !== (int) $poLine->product_variant_id) {
                    throw ValidationException::withMessages(["{$field}.product_variant_id" => 'L’article ne correspond pas à la ligne du bon de commande.']);
                }
                $variantId = $poLine->product_variant_id;
                $variantFromPurchaseOrder = true; // a PO line of this organization: its variant is already trusted
            }
            if ($nature === SupplierInvoiceCostNature::Inventory && $variantId === null) {
                throw ValidationException::withMessages(["{$field}.product_variant_id" => 'Un coût de stock doit référencer un article.']);
            }
            if ($nature === SupplierInvoiceCostNature::Expense && $variantId !== null) {
                throw ValidationException::withMessages(["{$field}.product_variant_id" => 'Une charge ne doit pas référencer un article de stock.']);
            }
            if ($variantId !== null && ! $variantFromPurchaseOrder && ! $variants->has((int) $variantId)) {
                throw ValidationException::withMessages(["{$field}.product_variant_id" => 'L’article sélectionné est invalide.']);
            }
            $tax = $taxRates->get((int) ($line['tax_rate_id'] ?? 0));
            if (! $tax || $tax->status->value !== 'active') {
                throw ValidationException::withMessages(["{$field}.tax_rate_id" => 'Le taux de TVA sélectionné est invalide ou inactif.']);
            }
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages(["{$field}.description" => 'La désignation est obligatoire.']);
            }

            $out[] = [
                'cost_nature' => $nature->value,
                'product_variant_id' => $variantId !== null ? (int) $variantId : null,
                'purchase_order_line_id' => $poLineId !== null ? (int) $poLineId : null,
                'description' => $description,
                'quantity' => $line['quantity'] ?? null,
                'unit_price_ht' => $line['unit_price_ht'] ?? null,
                'tax_rate_id' => $tax->getKey(),
                'tax_rate_percent' => (string) $tax->rate,
                'tax_name' => $tax->name,
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $installments */
    private function assertAccounts(Organization $organization, array $installments): void
    {
        $ids = array_values(array_unique(array_filter(array_column($installments, 'preferred_financial_account_id'))));
        if ($ids === []) {
            return;
        }
        $found = FinancialAccount::query()->where('organization_id', $organization->getKey())->whereIn('id', $ids)->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['installments' => 'Le compte financier prévu n’appartient pas à cette organisation.']);
        }
    }

    /** @return array<string, mixed> */
    private function auditValues(SupplierInvoice $invoice): array
    {
        return [
            'internal_number' => $invoice->internal_number,
            'invoice_number' => $invoice->invoice_number,
            'supplier_id' => $invoice->supplier_id,
            'store_id' => $invoice->store_id,
            'purchase_order_id' => $invoice->purchase_order_id,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'currency_code' => $invoice->currency_code,
            'subtotal_ht' => (string) $invoice->subtotal_ht,
            'total_tva' => (string) $invoice->total_tva,
            'rounding_adjustment' => (string) $invoice->rounding_adjustment,
            'total_ttc' => (string) $invoice->total_ttc,
            'control_total_ttc' => $invoice->control_total_ttc === null ? null : (string) $invoice->control_total_ttc,
            'row_version' => $invoice->row_version,
        ];
    }
}
