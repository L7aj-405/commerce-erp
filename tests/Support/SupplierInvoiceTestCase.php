<?php

namespace Tests\Support;

use App\Models\Organization;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceInstallment;
use App\Models\SupplierInvoiceLine;
use App\Models\User;
use App\Services\Finance\FinanceNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared fixtures for the supplier invoice schema / model / registry tests. */
abstract class SupplierInvoiceTestCase extends PaymentTestCase
{
    /** @return array{User, Organization, Store, Supplier} */
    protected function invoiceFixture(string $name = 'Org'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, $name);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store, $this->makeSupplier($organization)];
    }

    protected function makeSupplier(Organization $organization, string $name = 'Fournisseur'): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = $name;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }

    /** @param array<string,mixed> $overrides */
    protected function makeInvoice(Organization $organization, Store $store, Supplier $supplier, array $overrides = []): SupplierInvoice
    {
        $invoice = new SupplierInvoice;
        $invoice->organization_id = $organization->id;
        $invoice->store_id = $store->id;
        $invoice->supplier_id = $supplier->id;
        $invoice->internal_number = $overrides['internal_number'] ?? DB::transaction(fn () => app(FinanceNumberGenerator::class)->nextSupplierInvoice($organization, 2026));
        $invoice->invoice_number = $overrides['invoice_number'] ?? 'F-'.Str::upper(Str::random(6));
        $invoice->dedupe_key = array_key_exists('dedupe_key', $overrides) ? $overrides['dedupe_key'] : Str::upper(Str::random(8)).'|2026';
        $invoice->invoice_date = '2026-11-20';
        $invoice->currency_code = 'MAD';
        $invoice->subtotal_ht = '100.0000';
        $invoice->total_tva = '20.0000';
        $invoice->rounding_adjustment = '0.0000';
        $invoice->total_ttc = '120.0000';
        $invoice->status = $overrides['status'] ?? 'draft';
        $invoice->created_by_user_id = $overrides['created_by_user_id'] ?? null;
        $invoice->save();

        return $invoice->fresh();
    }

    protected function makeLine(SupplierInvoice $invoice, int $position = 1): SupplierInvoiceLine
    {
        $line = new SupplierInvoiceLine;
        $line->organization_id = $invoice->organization_id;
        $line->supplier_invoice_id = $invoice->id;
        $line->position = $position;
        $line->cost_nature = 'inventory';
        $line->description = 'Article';
        $line->quantity = '2.0000';
        $line->unit_price_ht = '50.0000';
        $line->tax_rate_percent = '20.0000';
        $line->line_ht = '100.0000';
        $line->line_tva = '20.0000';
        $line->line_ttc = '120.0000';
        $line->save();

        return $line->fresh();
    }

    protected function makeInstallment(SupplierInvoice $invoice, int $position = 1): SupplierInvoiceInstallment
    {
        $installment = new SupplierInvoiceInstallment;
        $installment->organization_id = $invoice->organization_id;
        $installment->supplier_invoice_id = $invoice->id;
        $installment->position = $position;
        $installment->due_date = '2026-12-20';
        $installment->amount = '120.0000';
        $installment->save();

        return $installment->fresh();
    }

    /** Approve through the raw query builder, as the lifecycle action's compare-and-swap will. */
    protected function forceApproved(SupplierInvoice $invoice): SupplierInvoice
    {
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['status' => 'approved', 'approved_at' => now()]);

        return $invoice->fresh();
    }
}
