<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Enums\ContactSystemRole;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Contacts\PartyContactMirror;
use Illuminate\Support\Facades\DB;

/**
 * The ONE workflow behind the shared contact form: identity + roles + commercial profiles + payment terms + an optional
 * interlocutor, in a single transaction. It only orchestrates the Phase 1 actions (each of which authorizes and audits its
 * own step), so a forbidden or failing step rolls the whole contact back: nothing partial is ever left behind.
 */
class CreateContactAction
{
    use AuthorizesContactAction;

    public function __construct(
        private readonly SavePartyAction $parties,
        private readonly ManagePartyRolesAction $roles,
        private readonly LinkPartyRelationshipAction $relationships,
        private readonly ManagePaymentTermsAction $terms,
        private readonly PartyContactMirror $mirror,
    ) {}

    /**
     * @param  array<string, mixed>  $data  identity fields + roles (system keys) + role_ids + custom_role + customer/supplier conditions
     *                                      + contact_person + relationship + reuse_party_id
     * @return array{party: Party, customer: ?Customer, supplier: ?Supplier, reused: bool}
     */
    public function execute(User $actor, Organization $organization, array $data): array
    {
        return DB::transaction(function () use ($actor, $organization, $data) {
            $reuse = ! empty($data['reuse_party_id']);
            if ($reuse) {
                $party = Party::query()->where('organization_id', $organization->getKey())->findOrFail((int) $data['reuse_party_id']);
                $this->authorizeContact($actor, $organization, 'contacts.update');
            } else {
                $party = $this->parties->execute($actor, $organization, $data);
            }

            $roles = $this->requestedRoles($data);
            if ($roles !== []) {
                $this->roles->add($actor, $organization, $party, $roles);
            }

            $customer = Customer::query()->where('organization_id', $organization->getKey())->where('party_id', $party->getKey())->first();
            $supplier = Supplier::query()->where('organization_id', $organization->getKey())->where('party_id', $party->getKey())->first();
            if ($customer && ! empty($data['customer']['payment_term_id'])) {
                $this->terms->assignToCustomer($actor, $customer, $this->term($organization, (int) $data['customer']['payment_term_id']));
            }
            if ($supplier && ! empty($data['supplier']['payment_term_id'])) {
                $this->terms->assignToSupplier($actor, $supplier, $this->term($organization, (int) $data['supplier']['payment_term_id']));
            }
            if ($supplier && ! empty($data['supplier']['default_currency_code'])) {
                $this->authorizeContact($actor, $organization, 'suppliers.manage');
                DB::table('suppliers')->where('organization_id', $organization->getKey())->where('id', $supplier->getKey())
                    ->update(['default_currency_code' => strtoupper((string) $data['supplier']['default_currency_code']), 'updated_at' => now()]);
            }

            // An organization may be created together with its first interlocutor (a distinct person, linked, never merged).
            if (! $reuse && $party->kind === Party::KIND_ORGANIZATION && filled($data['contact_person'] ?? null)) {
                $person = $this->parties->execute($actor, $organization, ['kind' => Party::KIND_PERSON, 'display_name' => trim((string) $data['contact_person']), 'phone' => $data['phone'] ?? null]);
                $this->roles->add($actor, $organization, $person, [ContactSystemRole::Interlocutor]);
                $this->relationships->execute($actor, $organization, $person, $party, 'interlocutor');
            }
            // A person created from an organization page: attach the interlocutor to that existing organization.
            if (! empty($data['relationship']['related_party_id'])) {
                $company = Party::query()->where('organization_id', $organization->getKey())->findOrFail((int) $data['relationship']['related_party_id']);
                $this->roles->add($actor, $organization, $party, [ContactSystemRole::Interlocutor]);
                $this->relationships->execute($actor, $organization, $party, $company, (string) ($data['relationship']['type'] ?? 'interlocutor'), $data['relationship']['job_title'] ?? null);
            }

            $this->mirror->ensure($party->fresh());

            return ['party' => $party->fresh(), 'customer' => $customer?->fresh(), 'supplier' => $supplier?->fresh(), 'reused' => $reuse];
        });
    }

    /** @return list<ContactSystemRole|int|string> */
    private function requestedRoles(array $data): array
    {
        $roles = [];
        foreach ((array) ($data['roles'] ?? []) as $key) {
            $role = ContactSystemRole::tryFrom((string) $key);
            if ($role !== null) {
                $roles[] = $role;
            }
        }
        foreach ((array) ($data['role_ids'] ?? []) as $id) {
            $roles[] = (int) $id;
        }
        if (filled($data['custom_role'] ?? null)) {
            $roles[] = trim((string) $data['custom_role']);
        }

        return $roles;
    }

    private function term(Organization $organization, int $id): PaymentTerm
    {
        return PaymentTerm::query()->where('organization_id', $organization->getKey())->where('active', true)->findOrFail($id);
    }
}
