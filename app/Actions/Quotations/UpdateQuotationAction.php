<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\MutatesQuotation;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Header-only edit of a draft Devis: customer link + billing snapshot, dates,
 * representative, notes, terms. Lines are edited through SaveQuotationLineAction.
 */
class UpdateQuotationAction
{
    use MutatesQuotation;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Quotation $quotation, array $data): Quotation
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.update');

        return DB::transaction(function () use ($actor, $quotation, $data) {
            $quotation = $this->lockDraft($quotation);

            if (array_key_exists('customer_id', $data)) {
                $customer = $this->customer($quotation, $data['customer_id']);
                $quotation->customer_id = $customer?->getKey();
                if ($customer && ($data['sync_customer_snapshot'] ?? false)) {
                    $quotation->customer_name = $customer->display_name;
                    $quotation->customer_company = $customer->company_name;
                    $quotation->customer_email = $customer->email;
                    $quotation->customer_phone = $customer->phone;
                    $quotation->customer_tax_identifier = $customer->tax_identifier;
                    $quotation->billing_address = $customer->billing_address;
                }
            }

            foreach ([
                'customer_name', 'customer_company', 'customer_email', 'customer_phone',
                'customer_tax_identifier', 'billing_address', 'representative_name', 'notes', 'terms',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $quotation->{$field} = $data[$field];
                }
            }
            if (array_key_exists('quotation_date', $data) && $data['quotation_date']) {
                $quotation->quotation_date = $data['quotation_date'];
            }
            if (array_key_exists('valid_until', $data)) {
                $quotation->valid_until = $data['valid_until'];
            }

            $quotation->save();

            $this->audit->record('quotation.updated', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                'updated_fields' => array_keys($data),
                'customer_id' => $quotation->customer_id,
            ]);

            return $quotation->load(['lines', 'customer']);
        });
    }

    private function customer(Quotation $quotation, mixed $customerId): ?Customer
    {
        if (! $customerId) {
            return null;
        }
        $customer = Customer::query()->where('organization_id', $quotation->organization_id)->whereKey($customerId)->firstOrFail();
        if ($customer->status !== CustomerStatus::Active) {
            throw ValidationException::withMessages(['customer_id' => 'Seul un client actif peut être associé à un devis.']);
        }

        return $customer;
    }
}
