<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Procurement\SupplierFiscalAdvisor;
use Illuminate\Support\Facades\DB;

class SaveSupplierAction
{
    use AuthorizesProcurementAction;

    /** Optional fiscal / payment identity; only written when the key is present in the payload. */
    private const FISCAL_FIELDS = ['ice', 'tax_identifier', 'rc_number', 'default_payment_term_days', 'default_currency_code', 'bank_iban'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Canonical supplier form rules, shared by the web form and MCP previews.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            // Fiscal identity: length / charset only. Format hints are advisory (SupplierFiscalAdvisor), never blocking.
            'ice' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\-\/]*$/'],
            'tax_identifier' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\-\/]*$/'],
            'rc_number' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\-\/]*$/'],
            'default_payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'default_currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'bank_iban' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 ]+$/'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data, ?Supplier $supplier = null): Supplier
    {
        $this->authorizeProcurement($actor, $organization, 'suppliers.manage');

        return DB::transaction(function () use ($actor, $organization, $data, $supplier) {
            if ($supplier) {
                abort_unless($supplier->organization_id === $organization->getKey(), 404);
                $supplier = Supplier::query()->where('organization_id', $organization->getKey())
                    ->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
                $event = 'supplier.updated';
            } else {
                $supplier = new Supplier;
                $supplier->organization_id = $organization->getKey();
                $event = 'supplier.created';
            }

            $supplier->name = trim((string) $data['name']);
            $supplier->contact_person = $data['contact_person'] ?? null;
            $supplier->phone = $data['phone'] ?? null;
            $supplier->email = $data['email'] ?? null;
            $supplier->address = $data['address'] ?? null;
            $supplier->notes = $data['notes'] ?? null;
            $supplier->active = (bool) ($data['active'] ?? true);

            $oldFiscal = $supplier->exists ? $this->auditFiscal($supplier) : [];
            foreach (self::FISCAL_FIELDS as $field) {
                // A payload that omits a field (MCP, older clients) keeps the stored value.
                if (array_key_exists($field, $data)) {
                    $supplier->{$field} = $this->normalizeFiscal($field, $data[$field]);
                }
            }
            $supplier->save();

            $newFiscal = $this->auditFiscal($supplier);
            $this->audit->record($event, $actor, $organization, auditable: $supplier,
                oldValues: $event === 'supplier.updated' ? array_diff_assoc($oldFiscal, $newFiscal) : [],
                newValues: [
                    'name' => $supplier->name, 'active' => $supplier->active,
                    ...($event === 'supplier.updated' ? array_diff_assoc($newFiscal, $oldFiscal) : array_filter($newFiscal, fn ($value) => $value !== null)),
                ]);

            return $supplier;
        });
    }

    private function normalizeFiscal(string $field, mixed $value): int|string|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($field) {
            'default_payment_term_days' => (int) $value,
            'default_currency_code' => strtoupper(trim((string) $value)),
            'bank_iban' => strtoupper((string) preg_replace('/\s+/', '', (string) $value)),
            default => (string) preg_replace('/\s+/', ' ', trim((string) $value)),
        };
    }

    /** Fiscal values as written to the audit trail: the IBAN is always masked. @return array<string, int|string|null> */
    private function auditFiscal(Supplier $supplier): array
    {
        $values = [];
        foreach (self::FISCAL_FIELDS as $field) {
            $values[$field] = $field === 'bank_iban' ? SupplierFiscalAdvisor::maskIban($supplier->bank_iban) : $supplier->{$field};
        }

        return $values;
    }
}
