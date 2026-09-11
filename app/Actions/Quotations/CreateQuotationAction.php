<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\AuthorizesQuotationAction;
use App\Enums\CustomerStatus;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentTemplateRegistry;
use App\Services\QuotationDocumentSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateQuotationAction
{
    use AuthorizesQuotationAction;

    public function __construct(
        private readonly QuotationDocumentSettings $settings,
        private readonly DocumentTemplateRegistry $templates,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, Store $store, array $data): Quotation
    {
        $this->authorizeQuotationScope($actor, $organization, $store, 'quotations.create');

        return DB::transaction(function () use ($actor, $organization, $store, $data) {
            $customer = $this->customer($organization, $data['customer_id'] ?? null);
            $devisSettings = $this->settings->settings($organization);

            $quotationDate = $data['quotation_date'] ?? now()->toDateString();
            $validUntil = $data['valid_until']
                ?? Carbon::parse($quotationDate)->addDays($devisSettings['default_validity_days'])->toDateString();

            $quotation = new Quotation;
            $quotation->organization_id = $organization->getKey();
            $quotation->store_id = $store->getKey();
            $quotation->customer_id = $customer?->getKey();
            $quotation->quotation_number = null;
            $quotation->status = QuotationStatus::Draft;
            $quotation->currency_code = strtoupper($data['currency_code'] ?? config('platform.currency_code', 'MAD'));
            $quotation->quotation_date = $quotationDate;
            $quotation->valid_until = $validUntil;

            // Immutable customer snapshot — historical Devis never re-render from
            // live Customer data.
            $quotation->customer_name = $data['customer_name'] ?? $customer?->display_name;
            $quotation->customer_company = $data['customer_company'] ?? $customer?->company_name;
            $quotation->customer_email = $data['customer_email'] ?? $customer?->email;
            $quotation->customer_phone = $data['customer_phone'] ?? $customer?->phone;
            $quotation->customer_tax_identifier = $data['customer_tax_identifier'] ?? $customer?->tax_identifier;
            $quotation->billing_address = $data['billing_address'] ?? $customer?->billing_address;
            $quotation->representative_name = $data['representative_name'] ?? $actor->name;

            $quotation->seller_snapshot = $this->settings->snapshot($organization, $store);
            $quotation->notes = $data['notes'] ?? $devisSettings['default_notes'];
            $quotation->terms = $data['terms'] ?? $devisSettings['default_terms'];
            $quotation->template_version = $this->templates->currentQuotationVersion();

            $quotation->subtotal_excl_tax = '0.0000';
            $quotation->discount_total = '0.0000';
            $quotation->tax_total = '0.0000';
            $quotation->total_incl_tax = '0.0000';
            $quotation->created_by_user_id = $actor->getKey();
            $quotation->save();

            $this->audit->record('quotation.created', $actor, $organization, $store, $quotation, newValues: [
                'customer_id' => $customer?->getKey(),
                'quotation_date' => $quotationDate,
                'valid_until' => $validUntil,
                'currency_code' => $quotation->currency_code,
                'status' => QuotationStatus::Draft->value,
            ]);

            return $quotation->load(['lines', 'customer']);
        });
    }

    private function customer(Organization $organization, mixed $customerId): ?Customer
    {
        if (! $customerId) {
            return null;
        }
        $customer = Customer::query()->where('organization_id', $organization->getKey())->whereKey($customerId)->firstOrFail();
        if ($customer->status !== CustomerStatus::Active) {
            throw ValidationException::withMessages(['customer_id' => 'Seul un client actif peut être associé à un devis.']);
        }

        return $customer;
    }
}
