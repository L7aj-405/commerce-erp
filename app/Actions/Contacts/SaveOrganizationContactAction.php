<?php

namespace App\Actions\Contacts;

use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\User;
use App\Rules\ValidWhatsAppUsername;
use App\Services\AuditLogger;
use App\Support\WhatsAppUsername;
use Illuminate\Validation\Rule;

class SaveOrganizationContactAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Authoritative contact form rules, shared by the web contact form and MCP.
     * Linked customers/suppliers must belong to the contact's organization.
     *
     * @return array<string, mixed>
     */
    public static function rules(int $organizationId): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'whatsapp' => ['nullable', 'string', 'max:64'],
            'whatsapp_username' => ['nullable', 'string', new ValidWhatsAppUsername],
            'contact_type' => ['required', Rule::in(OrganizationContact::TYPES)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('organization_id', $organizationId)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data, ?OrganizationContact $contact = null): OrganizationContact
    {
        $contact ??= new OrganizationContact;
        $isNew = ! $contact->exists;

        $contact->organization_id = $organization->getKey();
        $contact->customer_id = $data['customer_id'] ?? null;
        $contact->supplier_id = $data['supplier_id'] ?? null;
        $contact->full_name = trim((string) $data['full_name']);
        $contact->company_name = filled($data['company_name'] ?? null) ? trim((string) $data['company_name']) : null;
        $contact->job_title = filled($data['job_title'] ?? null) ? trim((string) $data['job_title']) : null;
        $contact->email = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;
        $contact->phone = filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null;
        $contact->whatsapp = filled($data['whatsapp'] ?? null) ? trim((string) $data['whatsapp']) : null;
        $contact->whatsapp_username = WhatsAppUsername::normalize($data['whatsapp_username'] ?? null);
        $contact->contact_type = (string) ($data['contact_type'] ?? OrganizationContact::TYPE_OTHER);
        $contact->notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;
        $contact->active = (bool) ($data['active'] ?? true);
        if ($contact->active) {
            $contact->archived_at = null;
        }
        $contact->save();

        $this->audit->record(
            $isNew ? 'contact.created' : 'contact.updated',
            $actor,
            $organization,
            auditable: $contact,
            newValues: [
                'full_name' => $contact->full_name,
                'email' => $contact->email,
                'whatsapp_username' => $contact->whatsapp_username,
                'contact_type' => $contact->contact_type,
                'active' => $contact->active,
            ],
        );

        return $contact;
    }
}
