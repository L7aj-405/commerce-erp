<?php

namespace App\Services\Mcp\Parties;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\CustomerManager;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Sales\CustomerReadService;
use App\Support\WhatsAppUsername;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Customer create/update over MCP. Candidate data is validated with the web
 * customer form rules (CustomerManager::rules) and persisted only through
 * CustomerManager, which owns defaults, WhatsApp normalization and audit.
 * The ERP has no customer archive: deactivation is status "inactive".
 * Creating a customer never creates an organization contact.
 */
final class McpCustomerChangeService
{
    public const FIELDS = ['type', 'display_name', 'company_name', 'email', 'phone', 'whatsapp_username', 'tax_identifier', 'billing_address', 'notes', 'status'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly CustomerReadService $customers,
        private readonly CustomerManager $manager,
        private readonly McpPartyPreviews $previews,
    ) {}

    public static function requiredScope(string $mode): string
    {
        return $mode === 'update' ? 'customers.update' : 'customers.create';
    }

    /**
     * Canonical form of the candidate: known fields only, strings trimmed and
     * empty strings as null (what the web form receives after middleware).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(string $mode, array $data, ?int $customerId = null): array
    {
        $update = $mode === 'update';
        if (array_diff(array_keys($data), self::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        if ($update && ($customerId === null || $customerId < 1)) throw new InvalidArgumentException('invalid_id');
        if ($update && $data === []) throw new InvalidArgumentException('no_changes');

        $changes = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $data);
        ksort($changes);

        return ['mode' => $update ? 'update' : 'create', 'customer_id' => $update ? $customerId : null, 'changes' => $changes];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $customer = $payload['mode'] === 'update' ? $this->customers->find($this->context->organization(), (int) $payload['customer_id']) : null;
        $evaluation = $this->evaluate($payload, $customer);
        $scope = self::requiredScope($payload['mode']);

        [$fingerprint, $expiresAt] = $evaluation['issues'] === [] && $evaluation['has_changes']
            ? $this->previews->issue('customer', $this->binding($payload, $evaluation))
            : [null, null];
        $showNotes = array_key_exists('notes', $payload['changes']);

        return [
            'mode' => $payload['mode'],
            'customer_id' => $payload['customer_id'],
            'current' => $evaluation['current'] === null ? null : $this->present($evaluation['current'], false),
            'proposed' => $this->present($evaluation['proposed'], $showNotes),
            'changed_fields' => $evaluation['changed_fields'],
            'has_changes' => $evaluation['has_changes'],
            'valid' => $evaluation['issues'] === [],
            'issues' => $evaluation['issues'],
            'possible_duplicates' => $evaluation['possible_duplicates'],
            'required_scopes' => [$scope],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => $payload['mode'] === 'create'
                ? 'Creates one customer only: no contact, quotation or other record is created. possible_duplicates is informational.'
                : 'Updates the customer record only; existing quotations, orders and invoices keep their customer snapshot. Status "inactive" is how the ERP deactivates a customer (it can no longer be quoted).',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, string $fingerprint): Customer
    {
        $evaluation = $this->evaluate($payload, null);

        return $this->apply('create', $payload, $evaluation, $fingerprint,
            fn () => $this->manager->create($this->context->user(), $this->context->organization(), $evaluation['proposed']));
    }

    /**
     * Must run inside the caller's transaction (row lock).
     *
     * @param array<string, mixed> $payload
     */
    public function update(array $payload, string $fingerprint): Customer
    {
        $customer = $this->customers->find($this->context->organization(), (int) $payload['customer_id'], lock: true);
        $evaluation = $this->evaluate($payload, $customer);

        return $this->apply('update', $payload, $evaluation, $fingerprint,
            fn () => $this->manager->update($this->context->user(), $customer, $evaluation['proposed'])->refresh());
    }

    /** @return array<string, mixed> */
    public function summary(Customer $customer): array
    {
        return [
            'id' => $customer->getKey(),
            'type' => $customer->type->value,
            'display_name' => $customer->display_name,
            'company_name' => $customer->company_name,
            'status' => $customer->status->value,
            'can_be_quoted' => $customer->status === CustomerStatus::Active,
            'version' => $this->customers->version($customer),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evaluation
     * @param callable(): Customer $action
     */
    private function apply(string $mode, array $payload, array $evaluation, string $fingerprint, callable $action): Customer
    {
        if ($evaluation['issues'] !== [] || ! $evaluation['has_changes'] || $payload['mode'] !== $mode) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        $binding = $this->binding($payload, $evaluation);
        $this->previews->consume('customer', $fingerprint, $binding);

        try {
            return $action();
        } catch (Throwable $exception) {
            $this->previews->release('customer', $fingerprint, $binding);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function evaluate(array $payload, ?Customer $customer): array
    {
        $current = $customer ? $this->attributes($customer) : null;
        // Create: status defaults to active exactly like CustomerManager.
        $candidate = [...($current ?? ['status' => CustomerStatus::Active->value]), ...$payload['changes']];

        $validator = Validator::make($candidate, CustomerManager::rules());
        $issues = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $issues[] = ['path' => "customer.{$field}", 'code' => 'invalid', 'message' => mb_substr((string) $messages[0], 0, 300)];
        }

        $proposed = array_intersect_key($candidate, array_flip(self::FIELDS)) + array_fill_keys(self::FIELDS, null);
        $proposed['whatsapp_username'] = is_string($proposed['whatsapp_username']) ? WhatsAppUsername::normalize($proposed['whatsapp_username']) : $proposed['whatsapp_username'];
        ksort($proposed);

        $changed = $current === null
            ? array_keys(array_filter($proposed, fn ($value) => $value !== null))
            : array_keys(array_filter($proposed, fn ($value, $field) => $value !== $current[$field], ARRAY_FILTER_USE_BOTH));

        return [
            'current' => $current,
            'proposed' => $proposed,
            'changed_fields' => array_values($changed),
            'has_changes' => $current === null || $changed !== [],
            'issues' => $issues,
            'possible_duplicates' => $issues === [] ? $this->duplicates($proposed, $customer, $changed) : [],
            'state_hash' => $customer ? $this->customers->version($customer) : 'new',
            'proposed_hash' => $this->previews->hash($proposed),
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(Customer $customer): array
    {
        $values = $customer->only(array_diff(self::FIELDS, ['type', 'status']));
        $values['type'] = $customer->type?->value;
        $values['status'] = $customer->status?->value;
        ksort($values);

        return $values;
    }

    /**
     * Same e-mail or same display name (case-insensitive) in the organization.
     * Informational only: the ERP does not forbid duplicates.
     *
     * @param array<string, mixed> $proposed
     * @param list<string> $changed
     * @return list<array<string, mixed>>
     */
    private function duplicates(array $proposed, ?Customer $customer, array $changed): array
    {
        $email = in_array('email', $changed, true) && is_string($proposed['email']) ? mb_strtolower($proposed['email']) : null;
        $name = in_array('display_name', $changed, true) && is_string($proposed['display_name']) ? mb_strtolower($proposed['display_name']) : null;
        if ($email === null && $name === null) return [];

        return Customer::query()
            ->where('organization_id', $this->context->organization()->getKey())
            ->when($customer, fn ($query) => $query->whereKeyNot($customer->getKey()))
            ->where(fn ($query) => $query
                ->when($email !== null, fn ($query) => $query->orWhereRaw('lower(email) = ?', [$email]))
                ->when($name !== null, fn ($query) => $query->orWhereRaw('lower(display_name) = ?', [$name])))
            ->orderBy('id')->limit(5)
            ->get(['id', 'type', 'display_name', 'company_name', 'email', 'status'])
            ->map(fn (Customer $match) => [
                'id' => $match->getKey(), 'type' => $match->type->value, 'display_name' => $match->display_name,
                'company_name' => $match->company_name, 'email' => $match->email, 'status' => $match->status->value,
                'matched_on' => array_values(array_filter([
                    $email !== null && mb_strtolower((string) $match->email) === $email ? 'email' : null,
                    $name !== null && mb_strtolower($match->display_name) === $name ? 'display_name' : null,
                ])),
            ])->all();
    }

    /**
     * Internal notes are not exposed by customer reads; they are shown only
     * when the caller supplied them in this request.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function present(array $values, bool $withNotes): array
    {
        return $withNotes ? $values : array_diff_key($values, ['notes' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evaluation
     * @return array<string, mixed>
     */
    private function binding(array $payload, array $evaluation): array
    {
        return [
            'entity' => 'customer',
            'mode' => $payload['mode'],
            'target_id' => $payload['customer_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $evaluation['state_hash'],
            'proposed_hash' => $evaluation['proposed_hash'],
            'required_scopes' => [self::requiredScope($payload['mode'])],
        ];
    }
}
