<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Rules\ValidWhatsAppUsername;
use App\Services\AuditLogger;
use App\Services\Contacts\PartyIdentitySynchronizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Create / update the canonical identity. Only display_name and kind are required: every other field is optional so a
 * simple contact never needs fiscal or financial data. An update is mirrored to the linked legacy rows in the same
 * transaction (PartyIdentitySynchronizer) and never touches issued documents or financial records.
 */
class SavePartyAction
{
    use AuthorizesContactAction;

    private const FISCAL = ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\-\/]*$/'];

    public function __construct(private readonly AuditLogger $audit, private readonly PartyIdentitySynchronizer $sync) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(Party::KINDS)],
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'whatsapp' => ['nullable', 'string', 'max:64'],
            'whatsapp_username' => ['nullable', 'string', new ValidWhatsAppUsername],
            'address' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'ice' => self::FISCAL,
            'tax_identifier' => ['nullable', 'string', 'max:128'],
            'rc_number' => self::FISCAL,
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data, ?Party $party = null): Party
    {
        $this->authorizeContact($actor, $organization, $party === null ? 'contacts.create' : 'contacts.update');

        return DB::transaction(function () use ($actor, $organization, $data, $party) {
            if ($party !== null) {
                $this->assertPartyInOrganization($party, $organization);
                $party = Party::query()->where('organization_id', $organization->getKey())->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
                if ($party->isArchived()) {
                    $this->refuse('party', 'Un contact archivé doit être restauré avant modification.');
                }
                $old = $party->only(['kind', 'display_name', 'email', 'phone']);
                $this->sync->apply($party, $data);
                $this->audit->record('party.updated', $actor, $organization, auditable: $party, oldValues: $old, newValues: $party->only(['kind', 'display_name', 'email', 'phone']));

                return $party;
            }

            $party = new Party;
            $party->organization_id = $organization->getKey();
            $party->origin = 'native';
            $party->status = Party::STATUS_ACTIVE;
            $party->created_by_user_id = $actor->getKey();
            $this->sync->fill($party, $data);
            $party->save();
            $this->audit->record('party.created', $actor, $organization, auditable: $party, newValues: $party->only(['kind', 'display_name', 'email', 'phone']));

            return $party;
        });
    }
}
