<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ArchivePartyAction;
use App\Actions\Contacts\CreateContactAction;
use App\Actions\Contacts\LinkPartyRelationshipAction;
use App\Actions\Contacts\ManagePartyRolesAction;
use App\Actions\Contacts\ManagePaymentTermsAction;
use App\Actions\Contacts\SavePartyAction;
use App\Enums\ContactSystemRole;
use App\Http\Controllers\Controller;
use App\Models\ContactRole;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PartyRelationship;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\Contacts\ContactRoleRegistry;
use App\Services\Contacts\PartyDirectory;
use App\Services\Contacts\PartyDuplicateFinder;
use App\Services\Contacts\PartyIdentitySerializer;
use App\Services\Contacts\PartyLifecycleGuard;
use App\Services\Contacts\PaymentTermCatalogue;
use App\Services\WhatsAppContactLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Canonical contacts workspace: ONE directory, ONE form and ONE detail page for every role. All identity writes go
 * through the Phase 1 actions (authorization, audit and organization scoping live there); this controller only
 * validates input, resolves the route and serializes what the viewer is allowed to see.
 *
 * Route ids: /contacts/parties/{party} is ALWAYS a Party id. Customer, supplier and legacy contact ids are resolved
 * through their real party_id (see LegacyContactRouteResolver), never reinterpreted.
 */
class PartyController extends Controller
{
    public function __construct(
        private readonly PartyDirectory $directory,
        private readonly ContactRoleRegistry $roles,
        private readonly PaymentTermCatalogue $terms,
    ) {}

    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in(Party::KINDS)],
            'role' => ['nullable', 'string', 'max:64'],
            'roles' => ['nullable', 'array', 'max:12'], 'roles.*' => ['string', 'max:64'],
            'role_ids' => ['nullable', 'array', 'max:12'], 'role_ids.*' => ['integer'],
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'scope' => ['nullable', Rule::in(['internal', 'external'])],
            'group' => ['nullable', Rule::in(['partners'])],
        ]);
        $applied = $filters;
        $applied['roles'] = array_values(array_unique([...($filters['roles'] ?? []), ...(filled($filters['role'] ?? null) ? [$filters['role']] : [])]));
        $applied = $this->scopedToPermissions($user, $organization, $applied);

        return Inertia::render('Contacts/Index', [
            'parties' => $this->directory->paginate($organization, $user, $applied),
            'filters' => $filters,
            'catalogue' => $this->catalogue($organization),
            'can' => $this->can($organization, $request),
        ]);
    }

    /**
     * Directory access. contacts.view sees every contact. A user who only holds customers.view (or suppliers.view) keeps their
     * historical entry: the directory is then forced to the Client (or Fournisseur) view and nothing else is disclosed.
     *
     *   array<string, mixed>  $applied
     *  array<string, mixed>
     */
    private function scopedToPermissions(User $user, Organization $organization, array $applied): array
    {
        if ($user->hasPermission($organization, 'contacts.view')) {
            return $applied;
        }
        $allowed = array_values(array_filter([
            $user->hasPermission($organization, 'customers.view') ? 'client' : null,
            $user->hasPermission($organization, 'suppliers.view') ? 'supplier' : null,
        ]));
        abort_if($allowed === [], 403);
        $requested = array_values(array_intersect($applied['roles'] ?? [], $allowed));
        $applied['roles'] = $requested === [] ? $allowed : $requested;
        unset($applied['role_ids'], $applied['scope'], $applied['group']);

        return $applied;
    }

    public function create(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'contacts.create'), 403);

        return Inertia::render('Contacts/Form', [
            'party' => null,
            'presetRoles' => $this->presets($request),
            'catalogue' => $this->catalogue($organization),
            'can' => $this->can($organization, $request),
        ]);
    }

    public function edit(Request $request, Party $party, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'contacts.update'), 403);
        $this->assertSameOrganization($party, $organization);

        return Inertia::render('Contacts/Form', [
            'party' => $this->formParty($party, $organization, $request),
            'presetRoles' => [],
            'catalogue' => $this->catalogue($organization),
            'can' => $this->can($organization, $request),
        ]);
    }

    public function show(Request $request, Party $party, ActiveTenantContext $context, PartyIdentitySerializer $serializer, PartyLifecycleGuard $guard): Response
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        abort_unless($user->hasPermission($organization, 'contacts.view'), 403);
        $this->assertSameOrganization($party, $organization);
        $orgId = (int) $organization->getKey();

        $customer = $user->hasPermission($organization, 'customers.view')
            ? Customer::query()->where('organization_id', $orgId)->where('party_id', $party->getKey())->first() : null;
        $supplier = $user->hasPermission($organization, 'suppliers.view')
            ? Supplier::query()->where('organization_id', $orgId)->where('party_id', $party->getKey())->first() : null;

        return Inertia::render('Contacts/Show', [
            'party' => $serializer->toArray($party, $user, $organization),
            'whatsappDestination' => app(WhatsAppContactLinkResolver::class)->resolve($party->whatsapp_username, $party->whatsapp ?: $party->phone),
            'inactiveRoles' => DB::table('party_roles as pr')->join('contact_roles as r', 'r.id', '=', 'pr.contact_role_id')
                ->where('pr.organization_id', $orgId)->where('pr.party_id', $party->getKey())->where('pr.status', 'inactive')->get(['r.id', 'r.name', 'r.system_key']),
            'relationships' => $this->relationships($party, $orgId),
            'customer' => $customer ? $this->customerTab($customer, $organization, $user) : null,
            'supplier' => $supplier ? $this->supplierTab($supplier, $organization, $user) : null,
            'activity' => $this->activity($party, $orgId),
            'blockers' => $user->hasPermission($organization, 'contacts.archive') ? $guard->blockers($party) : [],
            'tab' => $request->query('tab'),
            'catalogue' => $this->catalogue($organization),
            'can' => $this->can($organization, $request) + ['manageTerms' => $user->hasPermission($organization, 'finance.customers.terms.manage')],
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, CreateContactAction $action): JsonResponse|RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $data = $request->validate($this->writeRules($organization));
        $result = $action->execute($request->user(), $organization, $data);

        if ($request->expectsJson()) {
            return response()->json(['data' => $this->result($result)], $result['reused'] ? 200 : 201);
        }

        return redirect()->route('contacts.parties.show', $result['party'])->with('success', 'Contact enregistré.');
    }

    public function update(Request $request, Party $party, ActiveTenantContext $context, SavePartyAction $save, ManagePartyRolesAction $roles): JsonResponse|RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertSameOrganization($party, $organization);
        $data = $request->validate($this->writeRules($organization));

        DB::transaction(function () use ($request, $organization, $party, $data, $save, $roles) {
            $save->execute($request->user(), $organization, $data, $party);
            $this->syncRoles($request, $organization, $party, $data, $roles);
        });

        return $request->expectsJson()
            ? response()->json(['data' => ['party_id' => $party->getKey()]])
            : redirect()->route('contacts.parties.show', $party)->with('success', 'Contact mis à jour.');
    }

    public function addRoles(Request $request, Party $party, ActiveTenantContext $context, ManagePartyRolesAction $roles): RedirectResponse|JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertSameOrganization($party, $organization);
        $data = $request->validate(['roles' => ['nullable', 'array'], 'roles.*' => ['string', Rule::in(array_column(ContactSystemRole::cases(), 'value'))],
            'role_ids' => ['nullable', 'array'], 'role_ids.*' => ['integer'], 'custom_role' => ['nullable', 'string', 'max:80']]);
        $roles->add($request->user(), $organization, $party, $this->desiredRoles($data));

        return $request->expectsJson() ? response()->json(['data' => ['party_id' => $party->getKey()]]) : back()->with('success', 'Rôles mis à jour.');
    }

    public function removeRole(Request $request, Party $party, int $role, ActiveTenantContext $context, ManagePartyRolesAction $roles): RedirectResponse|JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertSameOrganization($party, $organization);
        $contactRole = $this->roles->findForOrganization($role, $organization);
        $roles->remove($request->user(), $organization, $party, $contactRole);

        return $request->expectsJson() ? response()->json(['data' => ['party_id' => $party->getKey()]]) : back()->with('success', 'Rôle retiré.');
    }

    public function addRelationship(Request $request, Party $party, ActiveTenantContext $context, CreateContactAction $create, LinkPartyRelationshipAction $link, ManagePartyRolesAction $roles): RedirectResponse|JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertSameOrganization($party, $organization);
        $data = $request->validate([
            'type' => ['required', Rule::in(PartyRelationship::TYPES)], 'job_title' => ['nullable', 'string', 'max:255'],
            'person_party_id' => ['nullable', 'integer'], 'display_name' => ['nullable', 'string', 'max:255', 'required_without:person_party_id'],
            'email' => ['nullable', 'email:rfc', 'max:254'], 'phone' => ['nullable', 'string', 'max:64'],
        ]);
        DB::transaction(function () use ($request, $organization, $party, $data, $link, $roles, $create) {
            if (! empty($data['person_party_id'])) {
                $person = Party::query()->where('organization_id', $organization->getKey())->findOrFail((int) $data['person_party_id']);
                $roles->add($request->user(), $organization, $person, [ContactSystemRole::Interlocutor]);
                $link->execute($request->user(), $organization, $person, $party, $data['type'], $data['job_title'] ?? null);

                return;
            }
            $create->execute($request->user(), $organization, [
                'kind' => Party::KIND_PERSON, 'display_name' => $data['display_name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                'relationship' => ['related_party_id' => $party->getKey(), 'type' => $data['type'], 'job_title' => $data['job_title'] ?? null],
            ]);
        });

        return $request->expectsJson() ? response()->json(['data' => ['party_id' => $party->getKey()]], 201) : back()->with('success', 'Interlocuteur ajouté.');
    }

    public function archive(Request $request, Party $party, ActiveTenantContext $context, ArchivePartyAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertSameOrganization($party, $organization);
        $action->archive($request->user(), $organization, $party);

        return back()->with('success', 'Contact archivé.');
    }

    public function restore(Request $request, Party $party, ActiveTenantContext $context, ArchivePartyAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertSameOrganization($party, $organization);
        $action->restore($request->user(), $organization, $party);

        return back()->with('success', 'Contact restauré.');
    }

    public function assignTerm(Request $request, Party $party, ActiveTenantContext $context, ManagePaymentTermsAction $action): RedirectResponse|JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->assertSameOrganization($party, $organization);
        $data = $request->validate(['profile' => ['required', Rule::in(['customer', 'supplier'])], 'payment_term_id' => ['nullable', 'integer']]);
        $term = ! empty($data['payment_term_id']) ? PaymentTerm::query()->where('organization_id', $organization->getKey())->where('active', true)->findOrFail((int) $data['payment_term_id']) : null;
        $orgId = $organization->getKey();
        if ($data['profile'] === 'customer') {
            $customer = Customer::query()->where('organization_id', $orgId)->where('party_id', $party->getKey())->firstOrFail();
            $action->assignToCustomer($request->user(), $customer, $term);
        } else {
            $supplier = Supplier::query()->where('organization_id', $orgId)->where('party_id', $party->getKey())->firstOrFail();
            $action->assignToSupplier($request->user(), $supplier, $term);
        }

        return $request->expectsJson() ? response()->json(['data' => ['party_id' => $party->getKey()]]) : back()->with('success', 'Conditions de paiement mises à jour.');
    }

    /** Identity candidates while typing (duplicate prevention). Evidence is graded; nothing is merged automatically. */
    public function candidates(Request $request, ActiveTenantContext $context, PartyDuplicateFinder $finder): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $user = $request->user();
        abort_unless($user->hasPermission($organization, 'contacts.view') || $user->hasPermission($organization, 'customers.view') || $user->hasPermission($organization, 'suppliers.view'), 403);
        $input = $request->validate(['display_name' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'string', 'max:254'], 'phone' => ['nullable', 'string', 'max:64'],
            'ice' => ['nullable', 'string', 'max:64'], 'kind' => ['nullable', Rule::in(Party::KINDS)], 'except' => ['nullable', 'integer']]);

        return response()->json(['data' => $finder->find($organization, $input, isset($input['except']) ? (int) $input['except'] : null)]);
    }

    /** @return array<string, mixed> */
    private function writeRules(Organization $organization): array
    {
        $orgId = $organization->getKey();

        return SavePartyAction::rules() + [
            'roles' => ['nullable', 'array', 'max:12'], 'roles.*' => ['string', Rule::in(array_column(ContactSystemRole::cases(), 'value'))],
            'role_ids' => ['nullable', 'array', 'max:20'], 'role_ids.*' => ['integer'],
            'custom_role' => ['nullable', 'string', 'max:80'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'reuse_party_id' => ['nullable', Rule::exists('parties', 'id')->where('organization_id', $orgId)],
            'customer' => ['nullable', 'array'], 'customer.payment_term_id' => ['nullable', Rule::exists('payment_terms', 'id')->where('organization_id', $orgId)],
            'supplier' => ['nullable', 'array'], 'supplier.payment_term_id' => ['nullable', Rule::exists('payment_terms', 'id')->where('organization_id', $orgId)],
            'supplier.default_currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'relationship' => ['nullable', 'array'], 'relationship.related_party_id' => ['nullable', Rule::exists('parties', 'id')->where('organization_id', $orgId)],
            'relationship.type' => ['nullable', Rule::in(PartyRelationship::TYPES)], 'relationship.job_title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @param array<string, mixed> $data  @return list<ContactSystemRole|int|string> */
    private function desiredRoles(array $data): array
    {
        $out = [];
        foreach ((array) ($data['roles'] ?? []) as $key) {
            $out[] = ContactSystemRole::from($key);
        }
        foreach ((array) ($data['role_ids'] ?? []) as $id) {
            $out[] = (int) $id;
        }
        if (filled($data['custom_role'] ?? null)) {
            $out[] = trim((string) $data['custom_role']);
        }

        return $out;
    }

    /**
     * Edit form: the submitted selection is the desired set of ACTIVE roles. Missing ones are added (commercial ones through the
     * Phase 1 profile activation), un-ticked ones are removed (soft, lifecycle-guarded). A deactivated commercial role is only ever
     * reactivated when the user ticks it explicitly.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncRoles(Request $request, Organization $organization, Party $party, array $data, ManagePartyRolesAction $roles): void
    {
        if (! array_key_exists('roles', $data) && ! array_key_exists('role_ids', $data) && blank($data['custom_role'] ?? null)) {
            return; // an identity-only update never touches roles
        }
        $applied = $desired = $this->desiredRoles($data);
        $applied = $desired === [] ? [] : $roles->add($request->user(), $organization, $party, $desired);
        $keep = array_map(fn (ContactRole $r) => (int) $r->id, $applied);
        $current = DB::table('party_roles as pr')->where('pr.organization_id', $organization->getKey())->where('pr.party_id', $party->getKey())->where('pr.status', 'active')->pluck('pr.contact_role_id');
        foreach ($current as $roleId) {
            if (! in_array((int) $roleId, $keep, true)) {
                $roles->remove($request->user(), $organization, $party, $this->roles->findForOrganization((int) $roleId, $organization));
            }
        }
    }

    /** @return array<string, mixed> */
    private function catalogue(Organization $organization): array
    {
        return [
            'roles' => array_map(fn (ContactRole $r) => ['id' => $r->id, 'name' => $r->name, 'system_key' => $r->system_key, 'is_system' => (bool) $r->is_system], $this->roles->available($organization)),
            'paymentTerms' => array_map(fn (PaymentTerm $t) => ['id' => $t->id, 'name' => $t->name, 'days' => $t->days], $this->terms->list($organization)),
        ];
    }

    /** @return array<string, bool> */
    private function can(Organization $organization, Request $request): array
    {
        $u = $request->user();

        return [
            'create' => $u->hasPermission($organization, 'contacts.create'), 'update' => $u->hasPermission($organization, 'contacts.update'),
            'archive' => $u->hasPermission($organization, 'contacts.archive'), 'manageRoles' => $u->hasPermission($organization, 'contacts.roles.manage'),
            'createCustomer' => $u->hasPermission($organization, 'customers.create'), 'createSupplier' => $u->hasPermission($organization, 'suppliers.manage'),
            'manageTerms' => $u->hasPermission($organization, 'contacts.payment_terms.manage'),
            'assignCustomerTerms' => $u->hasPermission($organization, 'finance.customers.terms.manage'), 'assignSupplierTerms' => $u->hasPermission($organization, 'suppliers.manage'),
            'viewContacts' => $u->hasPermission($organization, 'contacts.view'), 'viewCustomers' => $u->hasPermission($organization, 'customers.view'),
            'viewSuppliers' => $u->hasPermission($organization, 'suppliers.view'),
        ];
    }

    /** Entry-point presets (query `role=`): only known system keys are honoured; the server still authorizes every role. @return list<string> */
    private function presets(Request $request): array
    {
        $valid = array_column(ContactSystemRole::cases(), 'value');

        return array_values(array_intersect(array_map('strval', (array) $request->query('role', [])), $valid));
    }

    /** @return array<string, mixed> */
    private function formParty(Party $party, Organization $organization, Request $request): array
    {
        $data = app(PartyIdentitySerializer::class)->toArray($party, $request->user(), $organization);
        $data['role_ids'] = array_column($data['roles'], 'id');

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function relationships(Party $party, int $orgId): array
    {
        $rows = DB::table('party_relationships as rel')
            ->join('parties as p', fn ($j) => $j->on('p.id', '=', DB::raw('CASE WHEN rel.party_id = '.(int) $party->getKey().' THEN rel.related_party_id ELSE rel.party_id END')))
            ->where('rel.organization_id', $orgId)->where('p.organization_id', $orgId)->where('rel.active', true)
            ->where(fn ($q) => $q->where('rel.party_id', $party->getKey())->orWhere('rel.related_party_id', $party->getKey()))
            ->get(['rel.id', 'rel.type', 'rel.job_title', 'rel.is_primary', 'rel.party_id', 'p.id as other_id', 'p.display_name', 'p.kind', 'p.email', 'p.phone']);

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'type' => $r->type, 'job_title' => $r->job_title, 'is_primary' => (bool) $r->is_primary,
            'direction' => (int) $r->party_id === (int) $party->getKey() ? 'works_at' : 'has_person',
            'party' => ['id' => (int) $r->other_id, 'display_name' => $r->display_name, 'kind' => $r->kind, 'email' => $r->email, 'phone' => $r->phone],
        ])->all();
    }

    /** @return array<string, mixed> */
    private function customerTab(Customer $customer, Organization $organization, User $user): array
    {
        $tab = ['id' => $customer->getKey(), 'status' => $customer->status->value, 'type' => $customer->type->value,
            'links' => ['sheet' => "/sales/customers/{$customer->getKey()}"]];
        if ($user->hasPermission($organization, 'finance.view')) {
            $tab['links']['financial_account'] = "/finance/clients/comptes/{$customer->getKey()}";
        }
        if ($user->hasPermission($organization, 'finance.customers.terms.manage') || $user->hasPermission($organization, 'finance.view')) {
            $terms = DB::table('customer_financial_terms')->where('organization_id', $organization->getKey())->where('customer_id', $customer->getKey())->first();
            $tab['terms'] = $terms ? [
                'payment_term_id' => $terms->payment_term_id, 'payment_term_days' => $terms->payment_term_days, 'credit_enabled' => (bool) $terms->credit_enabled,
                'credit_limit' => $terms->credit_limit, 'invoicing_policy' => $terms->invoicing_policy,
            ] : null;
        }

        return $tab;
    }

    /** Never includes banking data (bank_iban stays on the supplier sheet behind its own permission). @return array<string, mixed> */
    private function supplierTab(Supplier $supplier, Organization $organization, User $user): array
    {
        return [
            'id' => $supplier->getKey(), 'active' => (bool) $supplier->active, 'payment_term_id' => $supplier->payment_term_id,
            'default_payment_term_days' => $supplier->default_payment_term_days, 'default_currency_code' => $supplier->default_currency_code,
            'links' => ['sheet' => "/procurement/suppliers/{$supplier->getKey()}"],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activity(Party $party, int $orgId): array
    {
        return DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.organization_id', $orgId)->where('a.auditable_type', $party->getMorphClass())->where('a.auditable_id', $party->getKey())
            ->orderByDesc('a.id')->limit(25)->get(['a.id', 'a.event', 'a.created_at', 'u.name as actor'])->map(fn ($r) => (array) $r)->all();
    }

    /** @param array{party: Party, customer: ?Customer, supplier: ?Supplier, reused: bool} $result  @return array<string, mixed> */
    private function result(array $result): array
    {
        return [
            'party_id' => $result['party']->getKey(), 'display_name' => $result['party']->display_name, 'reused' => $result['reused'],
            'customer' => $result['customer']?->only(['id', 'type', 'display_name', 'company_name', 'phone', 'email', 'tax_identifier', 'billing_address']),
            'supplier' => $result['supplier']?->only(['id', 'name']),
        ];
    }

    private function assertSameOrganization(Party $party, Organization $organization): void
    {
        abort_unless((int) $party->organization_id === (int) $organization->getKey(), 404);
    }
}
