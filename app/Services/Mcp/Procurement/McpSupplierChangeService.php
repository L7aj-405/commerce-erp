<?php

namespace App\Services\Mcp\Procurement;

use App\Actions\Procurement\SaveSupplierAction;
use App\Models\Supplier;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Supplier create/update over MCP. Candidate data is validated with the web
 * supplier form rules (SaveSupplierAction::rules) and persisted only through
 * SaveSupplierAction, which owns authorization (suppliers.manage) and the
 * supplier.created / supplier.updated audit events. Nothing is invented: a
 * field the caller does not supply stays empty (create) or unchanged (update).
 */
final class McpSupplierChangeService
{
    public const FIELDS = ['name', 'contact_person', 'phone', 'email', 'address', 'notes', 'status'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SupplierReadService $suppliers,
        private readonly SaveSupplierAction $save,
        private readonly McpPartyPreviews $previews,
    ) {}

    public static function requiredScope(string $mode): string
    {
        return $mode === 'update' ? 'suppliers.update' : 'suppliers.create';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(string $mode, array $data, ?int $supplierId = null): array
    {
        $update = $mode === 'update';
        if (array_diff(array_keys($data), self::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        if ($update && ($supplierId === null || $supplierId < 1)) throw new InvalidArgumentException('invalid_id');
        if ($update && $data === []) throw new InvalidArgumentException('no_changes');

        $changes = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $data);
        ksort($changes);

        return ['mode' => $update ? 'update' : 'create', 'supplier_id' => $update ? $supplierId : null, 'changes' => $changes];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $supplier = $payload['mode'] === 'update' ? $this->suppliers->find($this->context->organization(), (int) $payload['supplier_id']) : null;
        $evaluation = $this->evaluate($payload, $supplier);

        [$fingerprint, $expiresAt] = $evaluation['issues'] === [] && $evaluation['has_changes']
            ? $this->previews->issue('supplier', $this->binding($payload, $evaluation))
            : [null, null];
        $showNotes = array_key_exists('notes', $payload['changes']);

        return [
            'mode' => $payload['mode'],
            'supplier_id' => $payload['supplier_id'],
            'current' => $evaluation['current'] === null ? null : $this->present($evaluation['current'], false),
            'proposed' => $this->present($evaluation['proposed'], $showNotes),
            'changed_fields' => $evaluation['changed_fields'],
            'has_changes' => $evaluation['has_changes'],
            'valid' => $evaluation['issues'] === [],
            'issues' => $evaluation['issues'],
            'possible_duplicates' => $evaluation['possible_duplicates'],
            'required_scopes' => [self::requiredScope($payload['mode'])],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => $payload['mode'] === 'create'
                ? 'Creates one supplier record only. Only "name" is required by the ERP; never invent contact details the user did not give. If possible_duplicates lists a match, use that supplier instead of creating another.'
                : 'Updates the supplier record only. Status "inactive" hides the supplier from new supplier requests.',
        ];
    }

    /** Must run inside the caller's transaction (idempotency wrapper). @param array<string, mixed> $payload */
    public function create(array $payload, string $fingerprint): Supplier
    {
        $evaluation = $this->evaluate($payload, null);

        return $this->apply('create', $payload, $evaluation, $fingerprint,
            fn () => $this->save->execute($this->context->user(), $this->context->organization(), $this->actionData($evaluation['proposed'])));
    }

    /** Must run inside the caller's transaction (row lock). @param array<string, mixed> $payload */
    public function update(array $payload, string $fingerprint): Supplier
    {
        $supplier = $this->suppliers->find($this->context->organization(), (int) $payload['supplier_id'], lock: true);
        $evaluation = $this->evaluate($payload, $supplier);

        return $this->apply('update', $payload, $evaluation, $fingerprint,
            fn () => $this->save->execute($this->context->user(), $this->context->organization(), $this->actionData($evaluation['proposed']), $supplier)->refresh());
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evaluation
     * @param callable(): Supplier $action
     */
    private function apply(string $mode, array $payload, array $evaluation, string $fingerprint, callable $action): Supplier
    {
        if ($evaluation['issues'] !== [] || ! $evaluation['has_changes'] || $payload['mode'] !== $mode) {
            throw new InvalidArgumentException('stale_or_invalid_preview');
        }
        $binding = $this->binding($payload, $evaluation);
        $this->previews->consume('supplier', $fingerprint, $binding);

        try {
            return $action();
        } catch (Throwable $exception) {
            $this->previews->release('supplier', $fingerprint, $binding);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function evaluate(array $payload, ?Supplier $supplier): array
    {
        $current = $supplier ? $this->attributes($supplier) : null;
        // Create: a new supplier is active exactly like SaveSupplierAction's default.
        $candidate = [...($current ?? ['status' => 'active']), ...$payload['changes']];

        $issues = [];
        if (isset($candidate['status']) && ! in_array($candidate['status'], ['active', 'inactive'], true)) {
            $issues[] = ['path' => 'supplier.status', 'code' => 'invalid', 'message' => 'status must be "active" or "inactive".'];
        }
        $validator = Validator::make($this->actionData($candidate), SaveSupplierAction::rules());
        foreach ($validator->errors()->messages() as $field => $messages) {
            $issues[] = ['path' => 'supplier.'.($field === 'active' ? 'status' : $field), 'code' => 'invalid', 'message' => mb_substr((string) $messages[0], 0, 300)];
        }

        $proposed = array_intersect_key($candidate, array_flip(self::FIELDS)) + array_fill_keys(self::FIELDS, null);
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
            'possible_duplicates' => $issues === [] ? $this->duplicates($proposed, $supplier, $changed) : [],
            'state_hash' => $supplier ? $this->suppliers->version($supplier) : 'new',
            'proposed_hash' => $this->previews->hash($proposed),
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function actionData(array $values): array
    {
        $data = array_intersect_key($values, array_flip(['name', 'contact_person', 'phone', 'email', 'address', 'notes']));
        $data['active'] = ($values['status'] ?? 'active') === 'active';

        return $data;
    }

    /** @return array<string, mixed> */
    private function attributes(Supplier $supplier): array
    {
        $values = $supplier->only(['name', 'contact_person', 'phone', 'email', 'address', 'notes']);
        $values['status'] = $supplier->active ? 'active' : 'inactive';
        ksort($values);

        return $values;
    }

    /**
     * Same name, e-mail or phone (case-insensitive) in the organization.
     * Informational only: the ERP does not forbid duplicate suppliers.
     *
     * @param array<string, mixed> $proposed
     * @param list<string> $changed
     * @return list<array<string, mixed>>
     */
    private function duplicates(array $proposed, ?Supplier $supplier, array $changed): array
    {
        $match = fn (string $field) => in_array($field, $changed, true) && is_string($proposed[$field]) ? mb_strtolower($proposed[$field]) : null;
        $name = $match('name');
        $email = $match('email');
        $phone = $match('phone');
        if ($name === null && $email === null && $phone === null) return [];

        return Supplier::query()
            ->where('organization_id', $this->context->organization()->getKey())
            ->when($supplier, fn ($query) => $query->whereKeyNot($supplier->getKey()))
            ->where(fn ($query) => $query
                ->when($name !== null, fn ($query) => $query->orWhereRaw('lower(name) = ?', [$name]))
                ->when($email !== null, fn ($query) => $query->orWhereRaw('lower(email) = ?', [$email]))
                ->when($phone !== null, fn ($query) => $query->orWhereRaw('lower(phone) = ?', [$phone])))
            ->orderBy('id')->limit(5)->get()
            ->map(fn (Supplier $found) => [
                ...$this->suppliers->summary($found),
                'matched_on' => array_values(array_filter([
                    $name !== null && mb_strtolower($found->name) === $name ? 'name' : null,
                    $email !== null && mb_strtolower((string) $found->email) === $email ? 'email' : null,
                    $phone !== null && mb_strtolower((string) $found->phone) === $phone ? 'phone' : null,
                ])),
            ])->all();
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
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
            'entity' => 'supplier',
            'mode' => $payload['mode'],
            'target_id' => $payload['supplier_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $evaluation['state_hash'],
            'proposed_hash' => $evaluation['proposed_hash'],
            'required_scopes' => [self::requiredScope($payload['mode'])],
        ];
    }
}
