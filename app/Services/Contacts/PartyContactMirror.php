<?php

namespace App\Services\Contacts;

use App\Models\OrganizationContact;
use App\Models\Party;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the legacy organization_contacts table usable for its existing consumers (document e-mail recipient lookup, the
 * carrier / internal pickers) while the Party is canonical: a party that has an e-mail, or an internal / carrier role,
 * gets ONE linked legacy row (created once, then kept in step by PartyIdentitySynchronizer::mirror()).
 */
class PartyContactMirror
{
    private const TYPE_BY_ROLE = ['internal' => 'internal', 'carrier' => 'transport', 'client' => 'client', 'supplier' => 'supplier'];

    public function ensure(Party $party): ?OrganizationContact
    {
        $existing = OrganizationContact::query()->where('organization_id', $party->organization_id)->where('party_id', $party->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }
        $keys = DB::table('party_roles as pr')->join('contact_roles as r', 'r.id', '=', 'pr.contact_role_id')
            ->where('pr.organization_id', $party->organization_id)->where('pr.party_id', $party->getKey())->where('pr.status', 'active')->pluck('r.system_key')->filter()->all();
        if (blank($party->email) && array_intersect($keys, ['internal', 'carrier']) === []) {
            return null;
        }
        $type = 'other';
        foreach (['internal', 'carrier', 'client', 'supplier'] as $key) {
            if (in_array($key, $keys, true)) {
                $type = self::TYPE_BY_ROLE[$key];
                break;
            }
        }
        $contact = new OrganizationContact;
        $contact->organization_id = $party->organization_id;
        $contact->party_id = $party->getKey();
        $contact->full_name = $party->display_name;
        $contact->company_name = $party->kind === Party::KIND_ORGANIZATION ? ($party->legal_name ?? $party->display_name) : null;
        $contact->email = $party->email;
        $contact->phone = $party->phone;
        $contact->whatsapp = $party->whatsapp;
        $contact->whatsapp_username = $party->whatsapp_username;
        $contact->contact_type = $type;
        $contact->active = ! $party->isArchived();
        $contact->save();

        return $contact;
    }
}
