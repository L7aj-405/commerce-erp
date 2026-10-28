<?php

namespace App\Actions\Procurement;

use App\Actions\Procurement\Concerns\AuthorizesProcurementAction;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class SaveSupplierAction
{
    use AuthorizesProcurementAction;

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
            $supplier->save();

            $this->audit->record($event, $actor, $organization, auditable: $supplier, newValues: [
                'name' => $supplier->name, 'active' => $supplier->active,
            ]);

            return $supplier;
        });
    }
}
