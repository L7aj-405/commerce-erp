<?php

namespace App\Services\Contacts;

use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Server-side directory behind every contacts view (all / clients / suppliers / internal / external / partners).
 *
 * Semantics (documented in PHASE_2_IMPLEMENTATION.md):
 *  - roles[]       : the party holds AT LEAST ONE of the listed active roles (system key or role id);
 *  - scope=internal: the party holds the active "Interne" role; scope=external: it does not;
 *  - group=partners: the party holds an active Partenaire, Prestataire or Transporteur role;
 *  - status        : active (default) | archived | all.
 * Every query is organization-scoped; commercial profile ids are only disclosed to viewers of those modules.
 */
class PartyDirectory
{
    public const PARTNER_ROLES = ['partner', 'provider', 'carrier'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(Organization $organization, User $viewer, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $orgId = (int) $organization->getKey();
        $query = Party::query()->where('organization_id', $orgId);

        $status = $filters['status'] ?? 'active';
        if (in_array($status, [Party::STATUS_ACTIVE, Party::STATUS_ARCHIVED], true)) {
            $query->where('status', $status);
        }
        if (in_array($filters['kind'] ?? null, Party::KINDS, true)) {
            $query->where('kind', $filters['kind']);
        }
        if (filled($filters['search'] ?? null)) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $filters['search'])).'%';
            $query->where(fn ($q) => $q->where('display_name', 'like', $like)->orWhere('legal_name', 'like', $like)->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)->orWhere('whatsapp', 'like', $like)->orWhere('whatsapp_username', 'like', $like)
                ->orWhere('ice', 'like', $like)->orWhere('rc_number', 'like', $like)->orWhere('tax_identifier', 'like', $like));
        }

        $keys = array_values(array_filter((array) ($filters['roles'] ?? []), 'is_string'));
        $ids = array_values(array_map('intval', array_filter((array) ($filters['role_ids'] ?? []), 'is_numeric')));
        if ($keys !== [] || $ids !== []) {
            $query->whereExists($this->holds($orgId, $keys, $ids));
        }
        if (($filters['scope'] ?? null) === 'internal') {
            $query->whereExists($this->holds($orgId, ['internal'], []));
        } elseif (($filters['scope'] ?? null) === 'external') {
            $query->whereNotExists($this->holds($orgId, ['internal'], []));
        }
        if (($filters['group'] ?? null) === 'partners') {
            $query->whereExists($this->holds($orgId, self::PARTNER_ROLES, []));
        }

        $page = $query->orderByRaw("status = 'active' desc")->orderBy('display_name')->orderBy('id')->paginate($perPage)->withQueryString();
        $rolesByParty = $this->rolesFor($orgId, $page->getCollection()->pluck('id')->all());
        $canCustomers = $viewer->hasPermission($organization, 'customers.view');
        $canSuppliers = $viewer->hasPermission($organization, 'suppliers.view');
        $profiles = $this->profileIds($orgId, $page->getCollection()->pluck('id')->all());

        return $page->through(fn (Party $party) => [
            'id' => $party->getKey(), 'display_name' => $party->display_name, 'legal_name' => $party->legal_name, 'kind' => $party->kind,
            'status' => $party->status, 'phone' => $party->phone, 'email' => $party->email,
            'roles' => $rolesByParty[$party->getKey()] ?? [],
            'customer_id' => $canCustomers ? ($profiles['customers'][$party->getKey()] ?? null) : null,
            'supplier_id' => $canSuppliers ? ($profiles['suppliers'][$party->getKey()] ?? null) : null,
        ]);
    }

    /** @param list<string> $keys  @param list<int> $ids */
    private function holds(int $orgId, array $keys, array $ids): \Closure
    {
        return function ($sub) use ($orgId, $keys, $ids) {
            $sub->select(DB::raw(1))->from('party_roles as pr')->join('contact_roles as r', 'r.id', '=', 'pr.contact_role_id')
                ->whereColumn('pr.party_id', 'parties.id')->where('pr.organization_id', $orgId)->where('pr.status', 'active')
                ->where(function ($q) use ($keys, $ids) {
                    if ($keys !== []) {
                        $q->whereIn('r.system_key', $keys);
                    }
                    if ($ids !== []) {
                        $keys === [] ? $q->whereIn('r.id', $ids) : $q->orWhereIn('r.id', $ids);
                    }
                });
        };
    }

    /**
     * @param  list<int>  $partyIds
     * @return array<int, list<array{id: int, name: string, system_key: ?string}>>
     */
    public function rolesFor(int $orgId, array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('party_roles as pr')->join('contact_roles as r', 'r.id', '=', 'pr.contact_role_id')
            ->where('pr.organization_id', $orgId)->whereIn('pr.party_id', $partyIds)->where('pr.status', 'active')
            ->orderByDesc('r.is_system')->orderBy('r.name')->get(['pr.party_id', 'r.id', 'r.name', 'r.system_key']) as $row) {
            $out[(int) $row->party_id][] = ['id' => (int) $row->id, 'name' => $row->name, 'system_key' => $row->system_key];
        }

        return $out;
    }

    /**
     * @param  list<int>  $partyIds
     * @return array{customers: array<int, int>, suppliers: array<int, int>}
     */
    private function profileIds(int $orgId, array $partyIds): array
    {
        if ($partyIds === []) {
            return ['customers' => [], 'suppliers' => []];
        }

        return [
            'customers' => DB::table('customers')->where('organization_id', $orgId)->whereIn('party_id', $partyIds)->pluck('id', 'party_id')->map(fn ($v) => (int) $v)->all(),
            'suppliers' => DB::table('suppliers')->where('organization_id', $orgId)->whereIn('party_id', $partyIds)->pluck('id', 'party_id')->map(fn ($v) => (int) $v)->all(),
        ];
    }
}
