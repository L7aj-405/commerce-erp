<?php

namespace App\Services\Mcp\Parties;

use App\Models\Organization;
use App\Models\OrganizationContact;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Organization address-book reads (OrganizationContactController semantics).
 * A contact is not an ERP user; it may be linked to a customer and/or a
 * supplier of the same organization.
 */
final class ContactReadService
{
    /**
     * @param array{query?: string|null, contact_type?: string|null, active?: bool|null, customer_id?: int|null, supplier_id?: int|null, page?: int, per_page?: int} $filters
     * @return array<string, mixed>
     */
    public function search(Organization $organization, array $filters): array
    {
        $queryText = trim((string) ($filters['query'] ?? ''));
        $type = $filters['contact_type'] ?? null;
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);

        if (mb_strlen($queryText) > 255) throw new InvalidArgumentException('query_too_long');
        if ($type !== null && ! in_array($type, OrganizationContact::TYPES, true)) throw new InvalidArgumentException('invalid_contact_type');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) throw new InvalidArgumentException('invalid_pagination');
        foreach (['customer_id', 'supplier_id'] as $link) {
            if (isset($filters[$link]) && (int) $filters[$link] < 1) throw new InvalidArgumentException('invalid_id');
        }

        $contacts = OrganizationContact::query()
            ->where('organization_id', $organization->getKey())
            ->when($queryText !== '', function ($query) use ($queryText) {
                $like = '%'.addcslashes($queryText, '\\%_').'%';
                $query->where(fn ($query) => $query
                    ->where('full_name', 'like', $like)->orWhere('company_name', 'like', $like)
                    ->orWhere('job_title', 'like', $like)->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('whatsapp', 'like', $like)
                    ->orWhere('whatsapp_username', 'like', $like));
            })
            ->when($type !== null, fn ($query) => $query->where('contact_type', $type))
            ->when(isset($filters['active']), fn ($query) => $query->where('active', (bool) $filters['active']))
            ->when(isset($filters['customer_id']), fn ($query) => $query->where('customer_id', (int) $filters['customer_id']))
            ->when(isset($filters['supplier_id']), fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->with(['customer:id,organization_id,display_name,status', 'supplier:id,organization_id,name'])
            ->orderByDesc('active')
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($contacts->items())->map(fn (OrganizationContact $contact) => $this->summary($contact))->values()->all(),
            'pagination' => [
                'page' => $contacts->currentPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
                'has_more' => $contacts->hasMorePages(),
                'next_page' => $contacts->hasMorePages() ? $contacts->currentPage() + 1 : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function contact(Organization $organization, int $contactId): array
    {
        $contact = $this->find($organization, $contactId);
        $contact->load(['customer:id,organization_id,display_name,status', 'supplier:id,organization_id,name']);

        // Same e-mail duplicates the web contact page shows.
        $duplicates = $contact->email
            ? OrganizationContact::query()
                ->where('organization_id', $contact->organization_id)
                ->whereKeyNot($contact->getKey())
                ->whereRaw('lower(email) = ?', [mb_strtolower($contact->email)])
                ->orderBy('id')->limit(10)
                ->get(['id', 'full_name', 'company_name', 'email', 'active'])
                ->map(fn (OrganizationContact $duplicate) => $duplicate->only(['id', 'full_name', 'company_name', 'email', 'active']))
                ->all()
            : [];

        return [
            ...$this->summary($contact),
            'notes' => $contact->notes,
            'archived_at' => $contact->archived_at?->toIso8601String(),
            'can_be_email_recipient' => $contact->active && filled($contact->email),
            'same_email_contacts' => $duplicates,
            'version' => $this->version($contact),
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }

    public function find(Organization $organization, int $contactId, bool $lock = false): OrganizationContact
    {
        if ($contactId < 1) throw new InvalidArgumentException('invalid_id');

        return OrganizationContact::query()->where('organization_id', $organization->getKey())->whereKey($contactId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw (new ModelNotFoundException)->setModel(OrganizationContact::class, [$contactId]);
    }

    /** Version token over every editable field, the archive state and the row timestamp. */
    public function version(OrganizationContact $contact): string
    {
        $fields = $contact->only(['customer_id', 'supplier_id', 'full_name', 'company_name', 'job_title', 'email', 'phone', 'whatsapp', 'whatsapp_username', 'contact_type', 'notes', 'active']);
        $fields['archived_at'] = $contact->archived_at?->toIso8601String();
        ksort($fields);

        return hash('sha256', $contact->getKey().'|'.$contact->updated_at?->format('Y-m-d H:i:s.u').'|'.json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function summary(OrganizationContact $contact): array
    {
        // Relations are re-checked against the organization: a link is never
        // presented across tenants even if the foreign key were inconsistent.
        $customer = $contact->customer && (int) $contact->customer->organization_id === (int) $contact->organization_id ? $contact->customer : null;
        $supplier = $contact->supplier && (int) $contact->supplier->organization_id === (int) $contact->organization_id ? $contact->supplier : null;

        return [
            'id' => $contact->getKey(),
            'full_name' => $contact->full_name,
            'contact_type' => $contact->contact_type,
            'company_name' => $contact->company_name,
            'job_title' => $contact->job_title,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'whatsapp' => $contact->whatsapp,
            'whatsapp_username' => $contact->whatsapp_username,
            'active' => (bool) $contact->active,
            'customer' => $customer ? ['id' => $customer->getKey(), 'display_name' => $customer->display_name, 'status' => $customer->status->value] : null,
            'supplier' => $supplier ? ['id' => $supplier->getKey(), 'name' => $supplier->name] : null,
        ];
    }
}
