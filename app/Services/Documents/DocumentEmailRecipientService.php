<?php

namespace App\Services\Documents;

use App\Actions\Contacts\SaveOrganizationContactAction;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentEmailRecipientService
{
    public const DEFAULT_LIMIT = 25;

    public function __construct(private readonly SaveOrganizationContactAction $saveContact) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{to: list<array<string, mixed>>, cc: list<array<string, mixed>>, bcc: list<array<string, mixed>>, mail_to: list<string>, mail_cc: list<string>, mail_bcc: list<string>}
     */
    public function envelope(User $actor, Organization $organization, array $payload): array
    {
        $limit = (int) config('documents.email_recipient_limit', self::DEFAULT_LIMIT);
        $groups = [
            'to' => $payload['to'] ?? null,
            'cc' => $payload['cc'] ?? [],
            'bcc' => $payload['bcc'] ?? [],
        ];

        if (! is_array($groups['to']) && filled($payload['email'] ?? null)) {
            $groups['to'] = [['email' => $payload['email'], 'name' => null]];
        }

        $snapshot = ['to' => [], 'cc' => [], 'bcc' => []];
        $mail = ['to' => [], 'cc' => [], 'bcc' => []];
        $seen = [];

        foreach (['to', 'cc', 'bcc'] as $bucket) {
            if (! is_array($groups[$bucket])) {
                $groups[$bucket] = [];
            }

            foreach ($groups[$bucket] as $index => $recipient) {
                if (! is_array($recipient)) {
                    throw ValidationException::withMessages([$bucket => 'Destinataire invalide.']);
                }

                $entry = $this->normalizeRecipient($actor, $organization, $recipient, "{$bucket}.{$index}");
                $key = mb_strtolower($entry['email']);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = $bucket;
                $snapshot[$bucket][] = $entry;
                $mail[$bucket][] = $entry['email'];
            }
        }

        $total = count($mail['to']) + count($mail['cc']) + count($mail['bcc']);
        if (count($mail['to']) === 0) {
            throw ValidationException::withMessages(['to' => 'Au moins un destinataire principal est requis.']);
        }
        if ($total > $limit) {
            throw ValidationException::withMessages(['to' => "Maximum {$limit} destinataires par envoi."]);
        }

        return [
            'to' => $snapshot['to'],
            'cc' => $snapshot['cc'],
            'bcc' => $snapshot['bcc'],
            'mail_to' => $mail['to'],
            'mail_cc' => $mail['cc'],
            'mail_bcc' => $mail['bcc'],
        ];
    }

    /** @param array<string, mixed> $recipient */
    private function normalizeRecipient(User $actor, Organization $organization, array $recipient, string $field): array
    {
        Validator::make($recipient, [
            'contact_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:255'],
            'save_as_contact' => ['sometimes', 'boolean'],
        ])->validate();

        $contact = null;
        if (filled($recipient['contact_id'] ?? null)) {
            $contact = OrganizationContact::query()
                ->where('organization_id', $organization->getKey())
                ->where('id', (int) $recipient['contact_id'])
                ->where('active', true)
                ->first();

            if (! $contact) {
                throw ValidationException::withMessages([$field.'.contact_id' => 'Contact introuvable pour cette organisation.']);
            }
        }

        $email = mb_strtolower(trim((string) ($recipient['email'] ?? $contact?->email ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([$field.'.email' => 'Adresse email invalide.']);
        }

        $name = trim((string) ($recipient['name'] ?? $contact?->full_name ?? ''));
        $save = (bool) ($recipient['save_as_contact'] ?? false);
        if ($save && ! $contact) {
            if (! $actor->hasPermission($organization, 'contacts.create')) {
                throw ValidationException::withMessages([$field.'.save_as_contact' => 'Vous ne pouvez pas enregistrer ce destinataire comme contact.']);
            }
            $contact = $this->saveContact->execute($actor, $organization, [
                'full_name' => $name !== '' ? $name : $email,
                'email' => $email,
                'contact_type' => OrganizationContact::TYPE_OTHER,
                'active' => true,
            ]);
        }

        return [
            'email' => $email,
            'name' => $name !== '' ? $name : null,
            'contact_id' => $contact?->getKey(),
            'source' => $contact ? 'contact' : 'manual',
        ];
    }

    public function sanitizeFailure(Throwable $exception): string
    {
        return Str::limit(preg_replace('/(password|secret|token|authorization|bearer)\s*[:=]\s*\S+/i', '$1=[redacted]', $exception->getMessage()) ?: $exception::class, 1000);
    }
}
