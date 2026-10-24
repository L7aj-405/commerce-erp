<?php

namespace App\Services\Mcp\Parties;

use App\Actions\Contacts\ArchiveOrganizationContactAction;
use App\Actions\Contacts\SaveOrganizationContactAction;
use App\Models\Customer;
use App\Models\OrganizationContact;
use App\Models\Supplier;
use App\Services\Mcp\McpExecutionContext;
use App\Support\WhatsAppUsername;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Organization contact create/update/archive over MCP. Candidate data is
 * validated with the web contact form rules (SaveOrganizationContactAction::rules,
 * which also scope customer_id/supplier_id to the organization) and persisted
 * only through SaveOrganizationContactAction / ArchiveOrganizationContactAction.
 * Links are never inferred: only an explicit customer_id/supplier_id is saved.
 */
final class McpContactChangeService
{
    public const FIELDS = ['full_name', 'contact_type', 'company_name', 'job_title', 'email', 'phone', 'whatsapp', 'whatsapp_username', 'customer_id', 'supplier_id', 'notes', 'active'];
    public const SCOPES = ['create' => 'contacts.create', 'update' => 'contacts.update', 'archive' => 'contacts.archive'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly ContactReadService $contacts,
        private readonly SaveOrganizationContactAction $saveContact,
        private readonly ArchiveOrganizationContactAction $archiveContact,
        private readonly McpPartyPreviews $previews,
    ) {}

    public static function requiredScope(string $mode): string
    {
        return self::SCOPES[$mode] ?? throw new InvalidArgumentException('invalid_mode');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(string $mode, array $data, ?int $contactId = null): array
    {
        self::requiredScope($mode);
        if (array_diff(array_keys($data), self::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        if ($mode !== 'create' && ($contactId === null || $contactId < 1)) throw new InvalidArgumentException('invalid_id');
        if ($mode === 'create' && $contactId !== null) throw new InvalidArgumentException('invalid_id');
        if ($mode === 'archive' && $data !== []) throw new InvalidArgumentException('forbidden_fields');
        if ($mode === 'update' && $data === []) throw new InvalidArgumentException('no_changes');

        $changes = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $data);
        ksort($changes);

        return ['mode' => $mode, 'contact_id' => $mode === 'create' ? null : $contactId, 'changes' => $changes];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $contact = $payload['mode'] === 'create' ? null : $this->contacts->find($this->context->organization(), (int) $payload['contact_id']);
        $evaluation = $this->evaluate($payload, $contact);
        $scope = self::requiredScope($payload['mode']);

        [$fingerprint, $expiresAt] = $evaluation['issues'] === [] && $evaluation['has_changes']
            ? $this->previews->issue('contact', $this->binding($payload, $evaluation))
            : [null, null];

        return [
            'mode' => $payload['mode'],
            'contact_id' => $payload['contact_id'],
            'current' => $evaluation['current'] === null ? null : $this->present($evaluation['current']),
            'proposed' => $this->present($evaluation['proposed']),
            'changed_fields' => $evaluation['changed_fields'],
            'has_changes' => $evaluation['has_changes'],
            'valid' => $evaluation['issues'] === [],
            'issues' => $evaluation['issues'],
            'same_email_contacts' => $evaluation['same_email_contacts'],
            'required_scopes' => [$scope],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => match ($payload['mode']) {
                'create' => 'Creates one address-book contact (not an ERP user). No customer or supplier is created; only the explicitly given customer_id/supplier_id is linked. same_email_contacts is informational.',
                'update' => 'Updates this contact only. Links change only to the explicitly given customer_id/supplier_id (null unlinks).',
                default => 'Archives the contact (inactive, kept in history; it is no longer offered as an e-mail recipient). Nothing is deleted; it can be reactivated with update_contact active=true.',
            },
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, string $fingerprint): OrganizationContact
    {
        $evaluation = $this->evaluate($payload, null);

        return $this->apply('create', $payload, $evaluation, $fingerprint,
            fn () => $this->saveContact->execute($this->context->user(), $this->context->organization(), $evaluation['proposed']));
    }

    /**
     * Must run inside the caller's transaction (row lock).
     *
     * @param array<string, mixed> $payload
     */
    public function update(array $payload, string $fingerprint): OrganizationContact
    {
        $contact = $this->contacts->find($this->context->organization(), (int) $payload['contact_id'], lock: true);
        $evaluation = $this->evaluate($payload, $contact);

        return $this->apply('update', $payload, $evaluation, $fingerprint,
            fn () => $this->saveContact->execute($this->context->user(), $this->context->organization(), $evaluation['proposed'], $contact)->refresh());
    }

    /**
     * Must run inside the caller's transaction (row lock). A retry with the
     * same fingerprint after success reports the archived contact.
     *
     * @param array<string, mixed> $payload
     * @return array{contact: OrganizationContact, replay: bool}
     */
    public function archive(array $payload, string $fingerprint): array
    {
        $contact = $this->contacts->find($this->context->organization(), (int) $payload['contact_id'], lock: true);
        if (! $contact->active && $this->previews->wasDone('contact-archive', $fingerprint, (int) $contact->getKey())) {
            return ['contact' => $contact, 'replay' => true];
        }

        $evaluation = $this->evaluate($payload, $contact);
        $this->apply('archive', $payload, $evaluation, $fingerprint, function () use ($contact) {
            $this->archiveContact->execute($this->context->user(), $contact);

            return $contact;
        });
        $this->previews->markDone('contact-archive', $fingerprint, (int) $contact->getKey());

        return ['contact' => $contact->refresh(), 'replay' => false];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evaluation
     * @param callable(): OrganizationContact $action
     */
    private function apply(string $mode, array $payload, array $evaluation, string $fingerprint, callable $action): OrganizationContact
    {
        if ($evaluation['issues'] !== [] || ! $evaluation['has_changes'] || $payload['mode'] !== $mode) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        $binding = $this->binding($payload, $evaluation);
        $this->previews->consume('contact', $fingerprint, $binding);

        try {
            return $action();
        } catch (Throwable $exception) {
            $this->previews->release('contact', $fingerprint, $binding);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function evaluate(array $payload, ?OrganizationContact $contact): array
    {
        $current = $contact ? $this->attributes($contact) : null;
        $issues = [];

        if ($payload['mode'] === 'archive') {
            if (! $current['active']) {
                $issues[] = ['path' => 'contact', 'code' => 'already_archived', 'message' => 'This contact is already archived.'];
            }
            $proposed = [...$current, 'active' => false];
        } else {
            // Create: a contact is active unless stated otherwise, like the ERP action.
            $candidate = [...($current ?? ['active' => true]), ...$payload['changes']];
            $validator = Validator::make($candidate, SaveOrganizationContactAction::rules((int) $this->context->organization()->getKey()));
            foreach ($validator->errors()->messages() as $field => $messages) {
                $issues[] = ['path' => "contact.{$field}", 'code' => 'invalid', 'message' => mb_substr((string) $messages[0], 0, 300)];
            }
            $proposed = $this->canonical($candidate);
        }

        $changed = $current === null
            ? array_keys(array_filter($proposed, fn ($value) => $value !== null))
            : array_keys(array_filter($proposed, fn ($value, $field) => $value !== $current[$field], ARRAY_FILTER_USE_BOTH));

        return [
            'current' => $current,
            'proposed' => $proposed,
            'changed_fields' => array_values($changed),
            'has_changes' => $current === null || $changed !== [],
            'issues' => $issues,
            'same_email_contacts' => $issues === [] && in_array('email', $changed, true) ? $this->sameEmail($proposed['email'], $contact) : [],
            'state_hash' => $contact ? $this->contacts->version($contact) : 'new',
            'proposed_hash' => $this->previews->hash($proposed),
        ];
    }

    /**
     * The values SaveOrganizationContactAction will store, so the preview and
     * the change detection show exactly what is persisted.
     *
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function canonical(array $candidate): array
    {
        $text = fn (string $field) => is_scalar($candidate[$field] ?? null) && filled($candidate[$field]) ? trim((string) $candidate[$field]) : null;
        $id = fn (string $field) => is_numeric($candidate[$field] ?? null) ? (int) $candidate[$field] : null;
        $values = [
            'full_name' => $text('full_name'),
            'contact_type' => is_string($candidate['contact_type'] ?? null) ? $candidate['contact_type'] : null,
            'company_name' => $text('company_name'),
            'job_title' => $text('job_title'),
            'email' => ($email = $text('email')) !== null ? mb_strtolower($email) : null,
            'phone' => $text('phone'),
            'whatsapp' => $text('whatsapp'),
            'whatsapp_username' => WhatsAppUsername::normalize($text('whatsapp_username')),
            'customer_id' => $id('customer_id'),
            'supplier_id' => $id('supplier_id'),
            'notes' => $text('notes'),
            'active' => (bool) ($candidate['active'] ?? true),
        ];
        ksort($values);

        return $values;
    }

    /** @return array<string, mixed> */
    private function attributes(OrganizationContact $contact): array
    {
        $values = $contact->only(array_diff(self::FIELDS, ['customer_id', 'supplier_id', 'active']));
        $values['customer_id'] = $contact->customer_id === null ? null : (int) $contact->customer_id;
        $values['supplier_id'] = $contact->supplier_id === null ? null : (int) $contact->supplier_id;
        $values['active'] = (bool) $contact->active;
        ksort($values);

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        $organizationId = $this->context->organization()->getKey();
        $customer = $values['customer_id'] ? Customer::query()->where('organization_id', $organizationId)->whereKey($values['customer_id'])->first(['id', 'display_name', 'status']) : null;
        $supplier = $values['supplier_id'] ? Supplier::query()->where('organization_id', $organizationId)->whereKey($values['supplier_id'])->first(['id', 'name']) : null;

        return [
            ...$values,
            'customer' => $customer ? ['id' => $customer->getKey(), 'display_name' => $customer->display_name, 'status' => $customer->status->value] : null,
            'supplier' => $supplier ? ['id' => $supplier->getKey(), 'name' => $supplier->name] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sameEmail(?string $email, ?OrganizationContact $contact): array
    {
        if ($email === null) return [];

        return OrganizationContact::query()
            ->where('organization_id', $this->context->organization()->getKey())
            ->when($contact, fn ($query) => $query->whereKeyNot($contact->getKey()))
            ->whereRaw('lower(email) = ?', [$email])
            ->orderBy('id')->limit(10)
            ->get(['id', 'full_name', 'company_name', 'email', 'active'])
            ->map(fn (OrganizationContact $match) => $match->only(['id', 'full_name', 'company_name', 'email', 'active']))
            ->all();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evaluation
     * @return array<string, mixed>
     */
    private function binding(array $payload, array $evaluation): array
    {
        return [
            'entity' => 'contact',
            'mode' => $payload['mode'],
            'target_id' => $payload['contact_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $evaluation['state_hash'],
            'proposed_hash' => $evaluation['proposed_hash'],
            'required_scopes' => [self::requiredScope($payload['mode'])],
        ];
    }
}
