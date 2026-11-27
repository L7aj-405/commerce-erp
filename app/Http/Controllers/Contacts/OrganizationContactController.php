<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ArchiveOrganizationContactAction;
use App\Actions\Contacts\SaveOrganizationContactAction;
use App\Http\Controllers\Controller;
use App\Models\OrganizationContact;
use App\Services\ActiveTenantContext;
use App\Services\Contacts\LegacyPartyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * LEGACY organization_contacts endpoints, kept backward compatible (store / update / destroy / lookup are still used by
 * existing forms, document e-mail recipient search and MCP). The directory, creation form and detail page now live in
 * PartyController: a legacy /contacts/{id} is an OrganizationContact id and is resolved to its canonical Party through the
 * contact's own party_id, never reinterpreted as a party id.
 */
class OrganizationContactController extends Controller
{
    public function store(Request $request, ActiveTenantContext $context, SaveOrganizationContactAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [OrganizationContact::class, $organization]);
        $data = $request->validate($this->rules($organization->getKey()));

        $contact = $action->execute($request->user(), $organization, $data);

        return redirect()->route('contacts.parties.show', app(LegacyPartyResolver::class)->for($contact))->with('success', 'Contact enregistré.');
    }

    public function show(OrganizationContact $contact, LegacyPartyResolver $resolver): RedirectResponse
    {
        $this->authorize('view', $contact);

        return redirect()->route('contacts.parties.show', $resolver->for($contact));
    }

    public function edit(OrganizationContact $contact, LegacyPartyResolver $resolver): RedirectResponse
    {
        $this->authorize('update', $contact);

        return redirect()->route('contacts.parties.edit', $resolver->for($contact));
    }

    public function update(Request $request, OrganizationContact $contact, SaveOrganizationContactAction $action): RedirectResponse
    {
        $this->authorize('update', $contact);
        $action->execute($request->user(), $contact->organization, $request->validate($this->rules($contact->organization_id)), $contact);

        return redirect()->route('contacts.parties.show', app(LegacyPartyResolver::class)->for($contact->fresh()))->with('success', 'Contact mis à jour.');
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
        return SaveOrganizationContactAction::rules($organizationId);
    }
}
