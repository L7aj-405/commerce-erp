<?php

namespace App\Services\Contacts;

use App\Enums\ContactSystemRole;
use App\Models\ContactRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\NameNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * System roles (global, protected) and organization custom roles (reusable, case-insensitively unique per organization).
 * A role is a label: it never grants ERP access, a permission or a financial privilege.
 */
class ContactRoleRegistry
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function system(ContactSystemRole $role): ContactRole
    {
        return ContactRole::query()->where('organization_scope', 0)->where('system_key', $role->value)->firstOrFail();
    }

    /** A role visible to the organization: a system role or one of its own custom roles; otherwise 404-equivalent. */
    public function findForOrganization(int $roleId, Organization $organization): ContactRole
    {
        return ContactRole::query()->whereKey($roleId)
            ->whereIn('organization_scope', [0, (int) $organization->getKey()])->where('active', true)->firstOrFail();
    }

    /** @return list<ContactRole> active system + organization roles */
    public function available(Organization $organization): array
    {
        return ContactRole::query()->whereIn('organization_scope', [0, (int) $organization->getKey()])->where('active', true)
            ->orderByDesc('is_system')->orderBy('name')->get()->all();
    }

    public function find(Organization $organization, string $name): ?ContactRole
    {
        return $this->existing($organization, NameNormalizer::normalize($name));
    }

    /** Strict creation: a duplicate (any case / accent / spacing) of a system or existing custom role is rejected. */
    public function createCustom(User $actor, Organization $organization, string $name): ContactRole
    {
        [$clean, $normalized] = $this->clean($name);

        return DB::transaction(function () use ($actor, $organization, $clean, $normalized) {
            if ($this->existing($organization, $normalized) !== null) {
                throw ValidationException::withMessages(['name' => 'Ce rôle existe déjà.']);
            }

            return $this->insert($actor, $organization, $clean, $normalized);
        });
    }

    /** "Autre" workflow: reuse the existing role when the name matches, otherwise persist a new reusable one. */
    public function findOrCreateCustom(User $actor, Organization $organization, string $name): ContactRole
    {
        [$clean, $normalized] = $this->clean($name);

        return DB::transaction(function () use ($actor, $organization, $clean, $normalized) {
            $existing = $this->existing($organization, $normalized);
            if ($existing !== null) {
                if (! $existing->active) {
                    $existing->active = true;
                    $existing->save();
                }

                return $existing;
            }

            try {
                return $this->insert($actor, $organization, $clean, $normalized);
            } catch (UniqueConstraintViolationException) {
                return $this->existing($organization, $normalized) ?? throw new \LogicException('Role vanished after a unique violation.');
            }
        });
    }

    public function deactivateCustom(User $actor, Organization $organization, ContactRole $role): void
    {
        abort_if($role->is_system || (int) $role->organization_scope !== (int) $organization->getKey(), 403);
        $role->active = false;
        $role->save();
        $this->audit->record('contact_role.deactivated', $actor, $organization, auditable: $role, newValues: ['name' => $role->name]);
    }

    private function existing(Organization $organization, string $normalized): ?ContactRole
    {
        return ContactRole::query()->whereIn('organization_scope', [0, (int) $organization->getKey()])->where('normalized_name', $normalized)->first();
    }

    private function insert(User $actor, Organization $organization, string $name, string $normalized): ContactRole
    {
        $role = new ContactRole;
        $role->organization_id = $organization->getKey();
        $role->organization_scope = $organization->getKey();
        $role->name = $name;
        $role->normalized_name = $normalized;
        $role->is_system = false;
        $role->active = true;
        $role->created_by_user_id = $actor->getKey();
        $role->save();
        $this->audit->record('contact_role.created', $actor, $organization, auditable: $role, newValues: ['name' => $name]);

        return $role;
    }

    /** @return array{0: string, 1: string} */
    private function clean(string $name): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));
        $normalized = NameNormalizer::normalize($clean);
        if ($normalized === '' || mb_strlen($clean) > 80) {
            throw ValidationException::withMessages(['name' => 'Le nom du rôle est obligatoire (80 caractères maximum).']);
        }

        return [$clean, $normalized];
    }
}
