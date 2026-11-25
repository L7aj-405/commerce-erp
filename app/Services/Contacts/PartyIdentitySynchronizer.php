<?php

namespace App\Services\Contacts;

use App\Enums\ContactSystemRole;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\OrganizationContact;
use App\Models\Party;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Transitional source-of-truth rule: the Party is authoritative for shared identity. Every write path (the unified
 * lifecycle AND the legacy customer / supplier / contact forms and MCP tools) funnels through here, so the Party and
 * every linked legacy row are rewritten in the same transaction and can never drift apart.
 *
 *  - apply():           Party edited directly -> Party saved -> mirrored to customer / supplier / identity contacts.
 *  - syncFromLegacy():  a legacy row was saved -> its Party is created if missing, updated from it, then mirrored to the
 *                       siblings (the source row is not re-written).
 *
 * Only mutable identity is mirrored. Issued documents keep their own snapshots and financial rows are never touched.
 */
class PartyIdentitySynchronizer
{
    /**
     * A legacy contact saved through the old form / MCP. It always keeps (or gets) ITS OWN party: a customer_id /
     * supplier_id on a contact says "this person belongs to that account", never "this person IS that company", so
     * identities are not merged and the legacy link becomes an interlocutor relationship to the company party.
     */
    public function syncContact(OrganizationContact $contact): Party
    {
        return DB::transaction(function () use ($contact) {
            $party = $this->syncFromLegacy($contact);
            $this->attachLegacyRole($party, $contact->contact_type);
            foreach ([Customer::class => $contact->customer_id, Supplier::class => $contact->supplier_id] as $model => $id) {
                $companyPartyId = $id ? $model::query()->where('organization_id', $contact->organization_id)->whereKey($id)->value('party_id') : null;
                if ($companyPartyId === null || (int) $companyPartyId === (int) $party->getKey()) {
                    continue;
                }
                if (Party::query()->where('organization_id', $contact->organization_id)->whereKey($companyPartyId)->value('kind') !== Party::KIND_ORGANIZATION || $party->kind !== Party::KIND_PERSON) {
                    continue;
                }
                DB::table('party_relationships')->insertOrIgnore([
                    'organization_id' => $contact->organization_id, 'party_id' => $party->getKey(), 'related_party_id' => $companyPartyId,
                    'type' => 'interlocutor', 'job_title' => $contact->job_title, 'is_primary' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $party;
        });
    }

    /**
     * Legacy contact_type -> a role LABEL on the party (no profile is created; profile existence is customers/suppliers.party_id).
     * 'other' maps to no role: the meaning is preserved verbatim in parties.legacy_contact_type.
     */
    public function attachLegacyRole(Party $party, ?string $contactType): void
    {
        $role = ContactSystemRole::fromLegacyType($contactType);
        if ($role === null) {
            if ($contactType !== null && ! DB::table('party_roles')->where('organization_id', $party->organization_id)->where('party_id', $party->getKey())->exists()) {
                DB::table('parties')->where('organization_id', $party->organization_id)->where('id', $party->getKey())->update(['legacy_contact_type' => $contactType]);
            }

            return;
        }
        $roleId = DB::table('contact_roles')->whereNull('organization_id')->where('organization_scope', 0)->where('system_key', $role->value)->value('id');
        DB::table('party_roles')->insertOrIgnore([
            'organization_id' => $party->organization_id, 'party_id' => $party->getKey(), 'contact_role_id' => $roleId,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $identity subset of Party::IDENTITY_FIELDS (+ kind) */
    public function apply(Party $party, array $identity): Party
    {
        $this->fill($party, $identity);
        $party->save();
        $this->mirror($party);

        return $party;
    }

    /** Ensures the legacy row has a Party (creating a 'native' one) and propagates its identity to every sibling. */
    public function syncFromLegacy(Model $legacy): Party
    {
        return DB::transaction(function () use ($legacy) {
            $party = $legacy->party_id ? Party::query()->where('organization_id', $legacy->organization_id)->whereKey($legacy->party_id)->lockForUpdate()->first() : null;
            if ($party === null) {
                $party = new Party;
                $party->organization_id = $legacy->organization_id;
                $party->origin = 'native';
                $party->status = Party::STATUS_ACTIVE;
                $party->kind = $this->kindOf($legacy);
            }
            $this->fill($party, $this->identityFromLegacy($legacy, $party));
            $party->save();

            if (! $legacy->party_id) {
                DB::table($legacy->getTable())->where('id', $legacy->getKey())->update(['party_id' => $party->getKey()]);
                $legacy->party_id = $party->getKey();
                $legacy->syncOriginalAttribute('party_id');
            }
            $this->mirror($party, except: $legacy);
            if ($legacy instanceof Customer || $legacy instanceof Supplier) {
                $this->attachLegacyRole($party, $legacy instanceof Customer ? 'client' : 'supplier');
            }

            return $party;
        });
    }

    /** @param array<string, mixed> $identity */
    public function fill(Party $party, array $identity): void
    {
        if (isset($identity['kind']) && in_array($identity['kind'], Party::KINDS, true)) {
            $party->kind = $identity['kind'];
        }
        foreach (Party::IDENTITY_FIELDS as $field) {
            if (! array_key_exists($field, $identity)) {
                continue;
            }
            $value = $identity[$field];
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;
            if ($field === 'email' && $value !== null) {
                $value = mb_strtolower((string) $value);
            }
            if ($field === 'country_code' && $value !== null) {
                $value = strtoupper((string) $value);
            }
            $party->{$field} = $value;
        }
    }

    public function mirror(Party $party, ?Model $except = null): void
    {
        $org = $party->organization_id;
        $now = now();
        $skip = fn (Model $m) => $except !== null && $except->getTable() === $m->getTable() && $except->getKey() === $m->getKey();

        $customer = Customer::query()->where('organization_id', $org)->where('party_id', $party->getKey())->first();
        if ($customer && ! $skip($customer)) {
            $row = ['display_name' => $party->display_name, 'email' => $party->email, 'phone' => $party->phone, 'whatsapp_username' => $party->whatsapp_username,
                'billing_address' => $party->address, 'notes' => $party->notes, 'updated_at' => $now];
            if ($party->kind === Party::KIND_ORGANIZATION) {
                $row['company_name'] = $party->legal_name ?? $party->display_name;
            }
            DB::table('customers')->where('organization_id', $org)->where('id', $customer->getKey())->update($row);
        }

        $supplier = Supplier::query()->where('organization_id', $org)->where('party_id', $party->getKey())->first();
        if ($supplier && ! $skip($supplier)) {
            DB::table('suppliers')->where('organization_id', $org)->where('id', $supplier->getKey())->update([
                'name' => $party->display_name, 'phone' => $party->phone, 'email' => $party->email, 'address' => $party->address,
                'notes' => $party->notes, 'ice' => $party->ice, 'tax_identifier' => $party->tax_identifier, 'rc_number' => $party->rc_number, 'updated_at' => $now,
            ]);
        }

        OrganizationContact::query()->where('organization_id', $org)->where('party_id', $party->getKey())->get()
            ->reject($skip)
            ->each(fn (OrganizationContact $contact) => DB::table('organization_contacts')->where('organization_id', $org)->where('id', $contact->getKey())->update([
                'full_name' => $party->display_name, 'email' => $party->email, 'phone' => $party->phone,
                'whatsapp' => $party->whatsapp, 'whatsapp_username' => $party->whatsapp_username, 'updated_at' => $now,
            ]));
    }

    private function kindOf(Model $legacy): string
    {
        if ($legacy instanceof Customer) {
            return $legacy->type === CustomerType::Company ? Party::KIND_ORGANIZATION : Party::KIND_PERSON;
        }

        return $legacy instanceof Supplier ? Party::KIND_ORGANIZATION : Party::KIND_PERSON;
    }

    /** @return array<string, mixed> */
    private function identityFromLegacy(Model $legacy, Party $party): array
    {
        if ($legacy instanceof Customer) {
            $hasSupplier = $party->exists && Supplier::query()->where('organization_id', $party->organization_id)->where('party_id', $party->getKey())->exists();

            return [
                'display_name' => $legacy->display_name, 'legal_name' => $legacy->type === CustomerType::Company ? $legacy->company_name : $party->legal_name,
                'email' => $legacy->email, 'phone' => $legacy->phone, 'whatsapp_username' => $legacy->whatsapp_username,
                'address' => $legacy->billing_address, 'notes' => $legacy->notes,
                // customers.tax_identifier has no fixed fiscal meaning: it only seeds the party when no supplier profile
                // (whose ICE / IF columns are exact) shares the identity.
                ...($hasSupplier ? [] : ['tax_identifier' => $legacy->tax_identifier]),
            ];
        }
        if ($legacy instanceof Supplier) {
            return [
                'display_name' => $legacy->name, 'phone' => $legacy->phone, 'email' => $legacy->email, 'address' => $legacy->address,
                'notes' => $legacy->notes, 'ice' => $legacy->ice, 'tax_identifier' => $legacy->tax_identifier, 'rc_number' => $legacy->rc_number,
            ];
        }

        /** @var OrganizationContact $legacy */
        return [
            'display_name' => $legacy->full_name, 'email' => $legacy->email, 'phone' => $legacy->phone,
            'whatsapp' => $legacy->whatsapp, 'whatsapp_username' => $legacy->whatsapp_username,
        ];
    }
}
