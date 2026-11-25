<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Enums\ContactSystemRole;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Contacts\ContactRoleRegistry;
use App\Services\Contacts\PartyRoleWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Client and/or Fournisseur commercial profile to an EXISTING identity. Never duplicates the identity.
 *
 * Transactional + idempotent + race-safe: the party row is locked, an existing profile is reused, and the unique
 * (organization_id, party_id) key on customers / suppliers is the last line of defence (the loser re-reads the winner).
 * Customer / supplier ids are stable: a profile is created once and never recreated or deleted.
 */
class ActivateCommercialProfileAction
{
    use AuthorizesContactAction;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContactRoleRegistry $roles,
        private readonly PartyRoleWriter $writer,
    ) {}

    public function customer(User $actor, Organization $organization, Party $party): Customer
    {
        $this->authorizeContact($actor, $organization, 'contacts.update', 'customers.create');

        return $this->activate($party, $organization, function (Party $locked) use ($actor, $organization): Customer {
            $find = fn () => Customer::query()->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())->first();
            $existing = $find();
            if ($existing !== null) {
                return $existing;
            }
            $customer = new Customer;
            $customer->organization_id = $organization->getKey();
            $customer->party_id = $locked->getKey();
            $customer->type = $locked->kind === Party::KIND_ORGANIZATION ? CustomerType::Company : CustomerType::Individual;
            $customer->display_name = $locked->display_name;
            $customer->company_name = $locked->kind === Party::KIND_ORGANIZATION ? ($locked->legal_name ?? $locked->display_name) : null;
            $customer->email = $locked->email;
            $customer->phone = $locked->phone;
            $customer->whatsapp_username = $locked->whatsapp_username;
            $customer->tax_identifier = $locked->ice ?? $locked->tax_identifier;
            $customer->billing_address = $locked->address;
            $customer->notes = $locked->notes;
            $customer->status = CustomerStatus::Active;
            try {
                $customer->save();
            } catch (UniqueConstraintViolationException) {
                return $find() ?? throw new \LogicException('Customer profile vanished after a unique violation.');
            }
            $this->audit->record('party.customer_profile_created', $actor, $organization, auditable: $locked, newValues: ['customer_id' => $customer->getKey()]);

            return $customer;
        }, $actor, ContactSystemRole::Client);
    }

    public function supplier(User $actor, Organization $organization, Party $party): Supplier
    {
        $this->authorizeContact($actor, $organization, 'contacts.update', 'suppliers.manage');

        return $this->activate($party, $organization, function (Party $locked) use ($actor, $organization): Supplier {
            $find = fn () => Supplier::query()->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())->first();
            $existing = $find();
            if ($existing !== null) {
                return $existing;
            }
            $supplier = new Supplier;
            $supplier->organization_id = $organization->getKey();
            $supplier->party_id = $locked->getKey();
            $supplier->name = $locked->display_name;
            $supplier->phone = $locked->phone;
            $supplier->email = $locked->email;
            $supplier->address = $locked->address;
            $supplier->notes = $locked->notes;
            $supplier->ice = $locked->ice;
            $supplier->tax_identifier = $locked->tax_identifier !== null ? mb_substr($locked->tax_identifier, 0, 32) : null;
            $supplier->rc_number = $locked->rc_number;
            $supplier->active = true;
            try {
                $supplier->save();
            } catch (UniqueConstraintViolationException) {
                return $find() ?? throw new \LogicException('Supplier profile vanished after a unique violation.');
            }
            $this->audit->record('party.supplier_profile_created', $actor, $organization, auditable: $locked, newValues: ['supplier_id' => $supplier->getKey()]);

            return $supplier;
        }, $actor, ContactSystemRole::Supplier);
    }

    /**
     * @template T
     *
     * @param  callable(Party): T  $profile
     * @return T
     */
    private function activate(Party $party, Organization $organization, callable $profile, User $actor, ContactSystemRole $role): mixed
    {
        $this->assertPartyInOrganization($party, $organization);

        return DB::transaction(function () use ($party, $organization, $profile, $actor, $role) {
            $locked = Party::query()->where('organization_id', $organization->getKey())->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isArchived()) {
                $this->refuse('party', 'Un contact archivé doit être restauré avant d’activer un rôle commercial.');
            }
            $result = $profile($locked);
            $this->writer->attach($actor, $locked, $this->roles->system($role));

            return $result;
        });
    }
}
