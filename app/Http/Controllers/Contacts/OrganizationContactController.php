<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ArchiveOrganizationContactAction;
use App\Actions\Contacts\SaveOrganizationContactAction;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\OrganizationContact;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationContactController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [OrganizationContact::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'contact_type' => ['nullable', Rule::in($this->types())],
            'active' => ['nullable', 'in:1,0'],
        ]);

        $contacts = OrganizationContact::query()
            ->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('whatsapp', 'like', "%{$search}%")))
            ->when($filters['contact_type'] ?? null, fn ($query, string $type) => $query->where('contact_type', $type))
            ->when(isset($filters['active']), fn ($query) => $query->where('active', (bool) $filters['active']))
            ->with(['customer:id,display_name', 'supplier:id,name'])
            ->orderByDesc('active')
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (OrganizationContact $contact) => $this->serialize($contact));

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'can' => [
                'create' => $request->user()->hasPermission($organization, 'contacts.create'),
                'update' => $request->user()->hasPermission($organization, 'contacts.update'),
                'archive' => $request->user()->hasPermission($organization, 'contacts.archive'),
            ],
        ]);
    }

    public function create(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [OrganizationContact::class, $organization]);

        return Inertia::render('Contacts/Form', [
            'contact' => null,
            'linkedOptions' => $this->linkedOptions($organization->getKey()),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, SaveOrganizationContactAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [OrganizationContact::class, $organization]);
        $data = $request->validate($this->rules($organization->getKey()));

        $contact = $action->execute($request->user(), $organization, $data);

        return redirect()->route('contacts.show', $contact)->with('success', 'Contact enregistré.');
    }

    public function show(OrganizationContact $contact): Response
    {
        $this->authorize('view', $contact);

        $duplicates = $contact->email
            ? OrganizationContact::query()
                ->where('organization_id', $contact->organization_id)
                ->where('id', '!=', $contact->getKey())
                ->whereRaw('lower(email) = ?', [mb_strtolower($contact->email)])
                ->limit(10)
                ->get(['id', 'full_name', 'company_name', 'email'])
            : collect();

        return Inertia::render('Contacts/Show', [
            'contact' => $this->serialize($contact->load(['customer:id,display_name', 'supplier:id,name'])),
            'duplicates' => $duplicates,
            'can' => [
                'update' => request()->user()->can('update', $contact),
                'archive' => request()->user()->can('archive', $contact),
            ],
        ]);
    }

    public function edit(OrganizationContact $contact): Response
    {
        $this->authorize('update', $contact);

        return Inertia::render('Contacts/Form', [
            'contact' => $this->serialize($contact->load(['customer:id,display_name', 'supplier:id,name'])),
            'linkedOptions' => $this->linkedOptions($contact->organization_id),
        ]);
    }

    public function update(Request $request, OrganizationContact $contact, SaveOrganizationContactAction $action): RedirectResponse
    {
        $this->authorize('update', $contact);
        $action->execute($request->user(), $contact->organization, $request->validate($this->rules($contact->organization_id)), $contact);

        return redirect()->route('contacts.show', $contact)->with('success', 'Contact mis à jour.');
    }

    public function destroy(OrganizationContact $contact, ArchiveOrganizationContactAction $action): RedirectResponse
    {
        $this->authorize('archive', $contact);
        $action->execute(request()->user(), $contact);

        return redirect()->route('contacts.index')->with('success', 'Contact archivé.');
    }

    public function lookup(Request $request, ActiveTenantContext $context): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [OrganizationContact::class, $organization]);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = $data['search'] ?? '';

        $contacts = OrganizationContact::query()
            ->where('organization_id', $organization->getKey())
            ->where('active', true)
            ->whereNotNull('email')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('full_name')
            ->limit(20)
            ->get(['id', 'full_name', 'company_name', 'job_title', 'email', 'contact_type']);

        return response()->json(['data' => $contacts]);
    }

    /** @return array<string, mixed> */
    private function rules(int $organizationId): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'whatsapp' => ['nullable', 'string', 'max:64'],
            'contact_type' => ['required', Rule::in($this->types())],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('organization_id', $organizationId)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<string> */
    private function types(): array
    {
        return [
            OrganizationContact::TYPE_CLIENT,
            OrganizationContact::TYPE_SUPPLIER,
            OrganizationContact::TYPE_INTERNAL,
            OrganizationContact::TYPE_OTHER,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(OrganizationContact $contact): array
    {
        return [
            'id' => $contact->getKey(),
            'full_name' => $contact->full_name,
            'company_name' => $contact->company_name,
            'job_title' => $contact->job_title,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'whatsapp' => $contact->whatsapp,
            'contact_type' => $contact->contact_type,
            'notes' => $contact->notes,
            'active' => $contact->active,
            'archived_at' => $contact->archived_at,
            'customer' => $contact->customer ? ['id' => $contact->customer->id, 'name' => $contact->customer->display_name] : null,
            'supplier' => $contact->supplier ? ['id' => $contact->supplier->id, 'name' => $contact->supplier->name] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function linkedOptions(int $organizationId): array
    {
        return [
            'customers' => Customer::query()->where('organization_id', $organizationId)->orderBy('display_name')->limit(200)->get(['id', 'display_name']),
            'suppliers' => Supplier::query()->where('organization_id', $organizationId)->orderBy('name')->limit(200)->get(['id', 'name']),
        ];
    }
}
