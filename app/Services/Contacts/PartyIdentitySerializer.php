<?php

namespace App\Services\Contacts;

use App\Models\Organization;
use App\Models\Party;
use App\Models\User;

/**
 * The ONLY general-purpose shape of a party for UI / MCP. Whitelisted: it never carries banking data (IBAN stays on the
 * supplier profile behind its own permission) and exposes fiscal identity only to viewers who may see contacts.
 */
class PartyIdentitySerializer
{
    /** @return array<string, mixed> */
    public function toArray(Party $party, User $viewer, Organization $organization): array
    {
        $party->loadMissing(['customer:id,party_id', 'supplier:id,party_id']);
        $data = [
            'id' => $party->getKey(), 'kind' => $party->kind, 'display_name' => $party->display_name, 'legal_name' => $party->legal_name,
            'email' => $party->email, 'phone' => $party->phone, 'whatsapp' => $party->whatsapp, 'whatsapp_username' => $party->whatsapp_username,
            'address' => $party->address, 'city' => $party->city, 'country_code' => $party->country_code, 'notes' => $party->notes,
            'status' => $party->status,
            'customer_id' => $viewer->hasPermission($organization, 'customers.view') ? $party->customer?->getKey() : null,
            'supplier_id' => $viewer->hasPermission($organization, 'suppliers.view') ? $party->supplier?->getKey() : null,
            'roles' => $party->partyRoles()->where('status', 'active')->with('role:id,name,system_key,is_system')->get()
                ->map(fn ($row) => ['id' => $row->role->id, 'name' => $row->role->name, 'system_key' => $row->role->system_key])->values()->all(),
        ];
        if ($viewer->hasPermission($organization, 'contacts.view')) {
            $data += ['ice' => $party->ice, 'tax_identifier' => $party->tax_identifier, 'rc_number' => $party->rc_number];
        }

        return $data;
    }
}
