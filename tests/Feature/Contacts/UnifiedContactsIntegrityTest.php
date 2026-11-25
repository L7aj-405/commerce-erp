<?php

namespace Tests\Feature\Contacts;

use App\Actions\Contacts\ActivateCommercialProfileAction;
use App\Actions\Contacts\ArchivePartyAction;
use App\Actions\Contacts\LinkPartyRelationshipAction;
use App\Actions\Contacts\ManageContactRolesCatalogueAction;
use App\Actions\Contacts\ManagePartyRolesAction;
use App\Actions\Contacts\ManagePaymentTermsAction;
use App\Actions\Contacts\SavePartyAction;
use App\Actions\Procurement\SaveSupplierAction;
use App\Enums\ContactSystemRole;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Contacts\ContactRoleRegistry;
use App\Services\Contacts\PartyIdentitySynchronizer;
use App\Services\Contacts\PaymentTermCatalogue;
use App\Services\CustomerManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentTestCase;

/** Unified Contacts Phase 1: organization isolation, atomicity and audit completeness (CI remediation). */
class UnifiedContactsIntegrityTest extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private User $otherOwner;

    private Organization $other;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
        $this->otherOwner = User::factory()->create();
        $this->other = $this->createOrganization($this->otherOwner);
        $this->activate($this->otherOwner, $this->other, $this->createStore($this->other, $this->otherOwner));
        $this->actingAs($this->owner);
    }

    private function party(User $actor, Organization $org, string $name, string $kind = 'organization'): Party
    {
        return app(SavePartyAction::class)->execute($actor, $org, ['kind' => $kind, 'display_name' => $name]);
    }

    public function test_a_custom_role_of_another_organization_can_neither_be_assigned_nor_reused_by_name(): void
    {
        $theirs = app(ManageContactRolesCatalogueAction::class)->create($this->otherOwner, $this->other, 'Secret Role');
        $party = $this->party($this->owner, $this->org, 'Mine');

        // By id: not visible, 404-equivalent, nothing attached.
        try {
            app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, [$theirs->id]);
            $this->fail('another organization role id must not resolve');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, DB::table('party_roles')->where('party_id', $party->id)->count());
        }

        // By name: my organization gets its OWN role, never the foreign one.
        $mine = app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, ['Secret Role'])[0];
        $this->assertNotSame($theirs->id, $mine->id);
        $this->assertSame($this->org->id, (int) $mine->organization_id);
        $this->assertNotContains($theirs->id, array_map(fn ($r) => $r->id, app(ContactRoleRegistry::class)->available($this->org)));
        // System roles stay visible to both.
        $this->assertContains(ContactSystemRole::Client->label(), array_map(fn ($r) => $r->name, app(ContactRoleRegistry::class)->available($this->org)));
    }

    public function test_payment_terms_do_not_cross_organizations(): void
    {
        $foreignTerm = app(ManagePaymentTermsAction::class)->create($this->otherOwner, $this->other, 45);
        $customer = app(CustomerManager::class)->create($this->owner, $this->org, ['type' => 'individual', 'display_name' => 'Mine', 'status' => 'active']);
        $supplier = app(SaveSupplierAction::class)->execute($this->owner, $this->org, ['name' => 'Mine Co']);

        foreach ([
            fn () => app(ManagePaymentTermsAction::class)->assignToCustomer($this->owner, $customer, $foreignTerm),
            fn () => app(ManagePaymentTermsAction::class)->assignToSupplier($this->owner, $supplier, $foreignTerm),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a foreign payment term must not be assignable');
            } catch (ModelNotFoundException) {
                // expected
            }
        }
        $this->assertNull($supplier->fresh()->payment_term_id);
        $this->assertSame(0, DB::table('customer_financial_terms')->where('customer_id', $customer->id)->whereNotNull('payment_term_id')->count());

        // A foreign customer / supplier is refused as well (the actor is not a member of that organization).
        $theirCustomer = app(CustomerManager::class)->create($this->otherOwner, $this->other, ['type' => 'individual', 'display_name' => 'Theirs', 'status' => 'active']);
        $ownTerm = collect(app(PaymentTermCatalogue::class)->list($this->org))->firstWhere('days', 30);
        $this->expectException(HttpException::class);
        app(ManagePaymentTermsAction::class)->assignToCustomer($this->owner, $theirCustomer, $ownTerm);
    }

    public function test_the_database_refuses_a_cross_organization_party_link_and_synchronization_never_crosses(): void
    {
        $mine = $this->party($this->owner, $this->org, 'Twin Name');
        $theirs = $this->party($this->otherOwner, $this->other, 'Twin Name');
        app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $mine);
        app(ActivateCommercialProfileAction::class)->customer($this->otherOwner, $this->other, $theirs);
        $theirCustomer = Customer::query()->where('party_id', $theirs->id)->firstOrFail();

        // Renaming my identity (and re-syncing from the legacy row) never touches the other organization's rows.
        app(SavePartyAction::class)->execute($this->owner, $this->org, ['kind' => 'organization', 'display_name' => 'Renamed', 'email' => 'x@example.test'], $mine);
        app(PartyIdentitySynchronizer::class)->syncFromLegacy(Customer::query()->where('party_id', $mine->id)->firstOrFail());
        $this->assertSame('Twin Name', $theirs->fresh()->display_name);
        $this->assertSame('Twin Name', $theirCustomer->fresh()->display_name);
        $this->assertNull($theirCustomer->fresh()->email);

        // Composite (organization_id, id) foreign key: a customer cannot point at a party of another organization.
        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $theirCustomer->id)->update(['party_id' => $mine->id]);
    }

    public function test_cross_organization_identity_update_activation_and_relationship_are_refused(): void
    {
        $foreign = $this->party($this->otherOwner, $this->other, 'Foreign Co');
        $person = $this->party($this->owner, $this->org, 'Karim', 'person');

        foreach ([
            fn () => app(SavePartyAction::class)->execute($this->owner, $this->org, ['kind' => 'organization', 'display_name' => 'Hijack'], $foreign),
            fn () => app(ActivateCommercialProfileAction::class)->supplier($this->owner, $this->org, $foreign),
            fn () => app(LinkPartyRelationshipAction::class)->execute($this->owner, $this->org, $person, $foreign),
            fn () => app(ArchivePartyAction::class)->archive($this->owner, $this->org, $foreign),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a foreign party must be refused');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $this->assertSame('Foreign Co', $foreign->fresh()->display_name);
        $this->assertSame(0, DB::table('party_relationships')->count());
        $this->assertSame(0, Supplier::query()->where('organization_id', $this->other->id)->count());
    }

    public function test_party_with_both_profiles_is_atomic_and_a_failed_activation_rolls_everything_back(): void
    {
        // A user who may create customers but not suppliers asks for both roles: the Client part runs first, the
        // Fournisseur part is refused, and the whole operation must leave NOTHING behind.
        $partial = User::factory()->create();
        $this->addOrganizationMember($this->org, $partial, ['contacts.update', 'customers.create'], 'active', 'R'.Str::random(5));
        $this->activate($partial, $this->org, $this->store);
        $party = $this->party($this->owner, $this->org, 'All or nothing');

        try {
            app(ManagePartyRolesAction::class)->add($partial, $this->org, $party, [ContactSystemRole::Client, ContactSystemRole::Supplier]);
            $this->fail('the supplier half must be forbidden');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, Customer::query()->where('party_id', $party->id)->count(), 'the customer created before the failure is rolled back');
        $this->assertSame(0, DB::table('party_roles')->where('party_id', $party->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->whereIn('event', ['party.customer_profile_created', 'party.role_assigned'])->count(), 'no audit row survives a rolled-back activation');

        // The same request by an authorized user commits both profiles together.
        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, [ContactSystemRole::Client, ContactSystemRole::Supplier]);
        $this->assertSame(1, Customer::query()->where('party_id', $party->id)->count());
        $this->assertSame(1, Supplier::query()->where('party_id', $party->id)->count());
        // Repeating it duplicates neither profile nor audit rows.
        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, [ContactSystemRole::Client, ContactSystemRole::Supplier]);
        $this->assertSame(1, Customer::query()->where('party_id', $party->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'party.supplier_profile_created')->count());
    }

    public function test_every_significant_mutation_writes_exactly_one_audit_row(): void
    {
        $audited = fn (string $event) => DB::table('audit_logs')->where('organization_id', $this->org->id)->where('event', $event)->count();
        $person = $this->party($this->owner, $this->org, 'Salma', 'person');
        $company = $this->party($this->owner, $this->org, 'Atlas SARL');
        app(SavePartyAction::class)->execute($this->owner, $this->org, ['kind' => 'person', 'display_name' => 'Salma B.'], $person);
        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $company, [ContactSystemRole::Client, ContactSystemRole::Partner, 'Notaire']);
        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $company, ['notaire', ContactSystemRole::Partner]); // pure no-op
        app(LinkPartyRelationshipAction::class)->execute($this->owner, $this->org, $person, $company, 'employee');
        app(LinkPartyRelationshipAction::class)->execute($this->owner, $this->org, $person, $company, 'employee'); // no-op
        $term = app(ManagePaymentTermsAction::class)->create($this->owner, $this->org, 45);
        app(ManagePaymentTermsAction::class)->create($this->owner, $this->org, 45); // reuse
        $customer = Customer::query()->where('party_id', $company->id)->firstOrFail();
        app(ManagePaymentTermsAction::class)->assignToCustomer($this->owner, $customer, $term);
        $role = app(ContactRoleRegistry::class)->find($this->org, 'Notaire');
        app(ManagePartyRolesAction::class)->remove($this->owner, $this->org, $company, $role);
        app(ArchivePartyAction::class)->archive($this->owner, $this->org, $person);
        app(ArchivePartyAction::class)->restore($this->owner, $this->org, $person);
        app(ManageContactRolesCatalogueAction::class)->deactivate($this->owner, $this->org, $role);

        $expected = [
            'party.created' => 2, 'party.updated' => 1, 'party.customer_profile_created' => 1,
            'party.role_assigned' => 3, // Client, Partenaire, Notaire (the repeats are silent)
            'contact_role.created' => 1, 'party.relationship_linked' => 1, 'payment_term.created' => 1,
            'customer.payment_term_assigned' => 1, 'party.role_deactivated' => 1, 'party.archived' => 1, 'party.restored' => 1, 'contact_role.deactivated' => 1,
        ];
        foreach ($expected as $event => $count) {
            $this->assertSame($count, $audited($event), "audit rows for {$event}");
        }
        // The actor and organization are always recorded.
        $this->assertSame(0, DB::table('audit_logs')->where('organization_id', $this->org->id)->where('event', 'like', 'party.%')->whereNull('actor_id')->count());
    }
}
