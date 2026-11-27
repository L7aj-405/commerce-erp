<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ManageContactRolesCatalogueAction;
use App\Actions\Contacts\ManagePaymentTermsAction;
use App\Http\Controllers\Controller;
use App\Models\ContactRole;
use App\Models\PartyMigrationConflict;
use App\Models\PaymentTerm;
use App\Services\ActiveTenantContext;
use App\Services\Contacts\PaymentTermCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Contact settings: role catalogue, payment terms catalogue, and a READ-ONLY review of migration conflicts. */
class ContactSettingsController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, PaymentTermCatalogue $terms): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        abort_unless($user->hasPermission($organization, 'contacts.view'), 403);
        $orgId = (int) $organization->getKey();
        $canRoles = $user->hasPermission($organization, 'contacts.roles.manage');

        return Inertia::render('Contacts/Settings', [
            'roles' => ContactRole::query()->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
                ->orderByDesc('is_system')->orderBy('name')->get()
                ->map(fn (ContactRole $r) => ['id' => $r->id, 'name' => $r->name, 'system_key' => $r->system_key, 'is_system' => (bool) $r->is_system, 'active' => (bool) $r->active])->all(),
            'paymentTerms' => array_map(fn (PaymentTerm $t) => ['id' => $t->id, 'name' => $t->name, 'days' => $t->days, 'is_system' => (bool) $t->is_system], $terms->list($organization)),
            'conflicts' => $canRoles ? $this->conflicts($orgId) : null,
            'can' => ['manageRoles' => $canRoles, 'manageTerms' => $user->hasPermission($organization, 'contacts.payment_terms.manage')],
        ]);
    }

    public function storeRole(Request $request, ActiveTenantContext $context, ManageContactRolesCatalogueAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $action->create($request->user(), $organization, $data['name']);

        return back()->with('success', 'Rôle créé.');
    }

    public function deactivateRole(Request $request, int $role, ActiveTenantContext $context, ManageContactRolesCatalogueAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $model = ContactRole::query()->where('organization_id', $organization->getKey())->findOrFail($role); // custom roles of THIS organization only
        $action->deactivate($request->user(), $organization, $model);

        return back()->with('success', 'Rôle désactivé.');
    }

    public function storeTerm(Request $request, ActiveTenantContext $context, ManagePaymentTermsAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $data = $request->validate(['days' => ['required', 'integer', 'min:0', 'max:365'], 'name' => ['nullable', 'string', 'max:80']]);
        $action->create($request->user(), $organization, (int) $data['days'], $data['name'] ?? null);

        return back()->with('success', 'Condition de paiement enregistrée.');
    }

    /** @return list<array<string, mixed>> */
    private function conflicts(int $orgId): array
    {
        $conflicts = PartyMigrationConflict::query()->where('organization_id', $orgId)->where('status', 'open')->orderByDesc('id')->limit(200)->get();
        $names = fn (string $table, string $column, $ids) => DB::table($table)->where('organization_id', $orgId)->whereIn('id', $ids)->pluck($column, 'id');
        $lookup = [
            'customer' => $names('customers', 'display_name', $conflicts->where('source_type', 'customer')->pluck('source_id')->merge($conflicts->where('related_type', 'customer')->pluck('related_id'))),
            'supplier' => $names('suppliers', 'name', $conflicts->where('source_type', 'supplier')->pluck('source_id')->merge($conflicts->where('related_type', 'supplier')->pluck('related_id'))),
            'contact' => $names('organization_contacts', 'full_name', $conflicts->where('source_type', 'contact')->pluck('source_id')),
            'party' => $names('parties', 'display_name', $conflicts->where('source_type', 'party')->pluck('source_id')->merge($conflicts->where('related_type', 'party')->pluck('related_id'))->merge($conflicts->pluck('party_id')->filter())),
        ];

        return $conflicts->map(fn (PartyMigrationConflict $c) => [
            'id' => $c->id, 'reason' => $c->reason, 'resolution' => $c->resolution, 'details' => $c->details,
            'source' => ['type' => $c->source_type, 'id' => $c->source_id, 'name' => $lookup[$c->source_type][$c->source_id] ?? null],
            'related' => $c->related_type ? ['type' => $c->related_type, 'id' => $c->related_id, 'name' => $lookup[$c->related_type][$c->related_id] ?? null] : null,
            'party_id' => $c->party_id,
        ])->all();
    }
}
