<?php

namespace Tests\Feature\Contacts;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\Party;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Contacts\PaymentTermCatalogue;
use App\Services\CustomerManager;
use App\Services\Finance\CustomerFinancialPositionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PaymentTestCase;

/** Unified Contacts, Phase 2: the HTTP workflows behind the shared form, the directory, the detail page and legacy resolution. */
class UnifiedContactsPhase2Test extends PaymentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->activate($this->owner, $this->org, $this->store);
    }

    private function member(array $permissions, ?Organization $org = null, ?Store $store = null): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($org ?? $this->org, $user, $permissions, 'active', 'R'.Str::random(5));
        $this->addStoreMember($store ?? $this->store, $user);
        $this->activate($user, $org ?? $this->org, $store ?? $this->store);

        return $user;
    }

    /** @param array<string, mixed> $data */
    private function create(array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->postJson(route('contacts.parties.store'), $data);
    }

    private function roleId(string $key): int
    {
        return (int) DB::table('contact_roles')->whereNull('organization_id')->where('system_key', $key)->value('id');
    }

    // ------------------------------------------------------------ scenarios 01-05: creation

    public function test_01_a_general_contact_creates_no_commercial_profile(): void
    {
        $response = $this->create(['kind' => 'person', 'display_name' => 'Amina Contact'])->assertCreated();

        $party = Party::query()->findOrFail($response->json('data.party_id'));
        $this->assertNull($response->json('data.customer'));
        $this->assertSame(0, Customer::query()->where('party_id', $party->id)->count());
        $this->assertSame(0, Supplier::query()->where('party_id', $party->id)->count());
        $this->assertSame(0, DB::table('party_roles')->where('party_id', $party->id)->count());
    }

    public function test_02_a_b2c_customer_appears_in_all_contacts_and_in_clients(): void
    {
        $this->create(['kind' => 'person', 'display_name' => 'Sara Particulier', 'phone' => '0600000001', 'roles' => ['client']])->assertCreated()
            ->assertJsonPath('data.customer.type', 'individual');
        $this->create(['kind' => 'person', 'display_name' => 'Karim Seul'])->assertCreated();

        $names = fn (array $query) => collect($this->actingAs($this->owner)->get(route('contacts.index', $query))->viewData('page')['props']['parties']['data'])->pluck('display_name')->all();
        $this->assertEqualsCanonicalizing(['Sara Particulier', 'Karim Seul'], $names([]));
        $this->assertSame(['Sara Particulier'], $names(['role' => 'client']));
    }

    public function test_03_a_b2b_customer_gets_its_payment_term_and_a_distinct_interlocutor(): void
    {
        $term = DB::table('payment_terms')->where('organization_id', $this->org->id)->where('days', 30)->value('id')
            ?? app(PaymentTermCatalogue::class)->list($this->org)[1]->id;

        $response = $this->create([
            'kind' => 'organization', 'display_name' => 'Atlas SARL', 'ice' => '001234567000089', 'roles' => ['client'], 'customer' => ['payment_term_id' => $term],
            'contact_person' => 'Salma Achats',
        ])->assertCreated();

        $company = Party::query()->findOrFail($response->json('data.party_id'));
        $customer = Customer::query()->where('party_id', $company->id)->firstOrFail();
        $this->assertSame('company', $customer->type->value);
        $this->assertSame(30, (int) DB::table('customer_financial_terms')->where('customer_id', $customer->id)->value('payment_term_days'));
        $person = Party::query()->where('display_name', 'Salma Achats')->firstOrFail();
        $this->assertSame('person', $person->kind);
        $this->assertNotSame($company->id, $person->id, 'the interlocutor keeps a separate identity');
        $this->assertTrue(DB::table('party_relationships')->where('party_id', $person->id)->where('related_party_id', $company->id)->exists());
    }

    public function test_04_a_supplier_is_created_through_the_same_endpoint(): void
    {
        $response = $this->create(['kind' => 'organization', 'display_name' => 'Fournisseur SA', 'roles' => ['supplier'], 'supplier' => ['default_currency_code' => 'eur']])->assertCreated();

        $supplier = Supplier::query()->where('party_id', $response->json('data.party_id'))->firstOrFail();
        $this->assertSame('Fournisseur SA', $supplier->name);
        $this->assertSame('EUR', $supplier->default_currency_code);
        $this->assertSame(0, Customer::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_05_a_dual_role_contact_is_one_party_with_two_profiles_and_independent_terms(): void
    {
        $terms = collect(app(PaymentTermCatalogue::class)->list($this->org))->keyBy('days');
        $response = $this->create([
            'kind' => 'organization', 'display_name' => 'Dual SARL', 'roles' => ['client', 'supplier'],
            'customer' => ['payment_term_id' => $terms[30]->id], 'supplier' => ['payment_term_id' => $terms[60]->id],
        ])->assertCreated();

        $partyId = $response->json('data.party_id');
        $this->assertSame(1, Party::query()->where('organization_id', $this->org->id)->count());
        $customer = Customer::query()->where('party_id', $partyId)->firstOrFail();
        $supplier = Supplier::query()->where('party_id', $partyId)->firstOrFail();
        $this->assertSame(30, (int) DB::table('customer_financial_terms')->where('customer_id', $customer->id)->value('payment_term_days'));
        $this->assertSame(60, (int) $supplier->default_payment_term_days);
        $this->assertSame($terms[60]->id, $supplier->payment_term_id);
    }

    // ------------------------------------------------------------ scenarios 06-09: role lifecycle

    public function test_06_and_07_a_customer_becomes_a_supplier_and_a_supplier_becomes_a_customer_without_duplicates(): void
    {
        $customerId = $this->create(['kind' => 'person', 'display_name' => 'Cust First', 'roles' => ['client']])->json('data.customer.id');
        $partyId = Customer::query()->findOrFail($customerId)->party_id;

        $this->actingAs($this->owner)->postJson(route('contacts.parties.roles.add', $partyId), ['roles' => ['supplier']])->assertOk();
        $this->assertSame($customerId, Customer::query()->where('party_id', $partyId)->value('id'), 'customer id is stable');
        $this->assertSame(1, Supplier::query()->where('party_id', $partyId)->count());
        $this->actingAs($this->owner)->postJson(route('contacts.parties.roles.add', $partyId), ['roles' => ['supplier', 'client']])->assertOk();
        $this->assertSame(1, Supplier::query()->where('party_id', $partyId)->count(), 'repeating never duplicates');

        $supplierParty = $this->create(['kind' => 'organization', 'display_name' => 'Supp First', 'roles' => ['supplier']])->json('data.party_id');
        $supplierId = Supplier::query()->where('party_id', $supplierParty)->value('id');
        $this->actingAs($this->owner)->postJson(route('contacts.parties.roles.add', $supplierParty), ['roles' => ['client']])->assertOk();
        $this->assertSame($supplierId, Supplier::query()->where('party_id', $supplierParty)->value('id'));
        $this->assertSame(1, Customer::query()->where('party_id', $supplierParty)->count());
        $this->assertSame(2, Party::query()->where('organization_id', $this->org->id)->count(), 'no extra identity was created');
    }

    public function test_08_a_custom_role_created_with_autre_is_reusable_and_needs_its_own_permission(): void
    {
        $first = $this->create(['kind' => 'person', 'display_name' => 'Installer One', 'custom_role' => 'Installateur'])->assertCreated()->json('data.party_id');
        $second = $this->create(['kind' => 'person', 'display_name' => 'Installer Two', 'custom_role' => '  installateur '])->assertCreated()->json('data.party_id');

        $this->assertSame(1, DB::table('contact_roles')->where('organization_id', $this->org->id)->count(), 'one reusable role');
        $roleId = DB::table('contact_roles')->where('organization_id', $this->org->id)->value('id');
        $this->assertSame(2, DB::table('party_roles')->where('contact_role_id', $roleId)->where('status', 'active')->count());
        $this->assertSame(0, Customer::query()->where('organization_id', $this->org->id)->count(), 'a custom role grants nothing commercial');

        // It is offered in the catalogue of the next form and filterable in the directory.
        $catalogue = $this->actingAs($this->owner)->get(route('contacts.create'))->viewData('page')['props']['catalogue']['roles'];
        $this->assertContains('Installateur', array_column($catalogue, 'name'));
        $listed = $this->actingAs($this->owner)->get(route('contacts.index', ['role_ids' => [$roleId]]))->viewData('page')['props']['parties']['data'];
        $this->assertEqualsCanonicalizing([$first, $second], array_column($listed, 'id'));

        $limited = $this->member(['contacts.view', 'contacts.create', 'contacts.update']);
        $this->create(['kind' => 'person', 'display_name' => 'Reuse OK', 'custom_role' => 'Installateur'], $limited)->assertCreated();
        $this->create(['kind' => 'person', 'display_name' => 'New Role Denied', 'custom_role' => 'Brand New'], $limited)->assertForbidden();
        $this->assertSame(0, Party::query()->where('display_name', 'New Role Denied')->count(), 'the denied request left nothing behind');
    }

    public function test_09_directors_and_accountants_are_distinct_people_linked_to_one_company(): void
    {
        $company = $this->create(['kind' => 'organization', 'display_name' => 'Holding SARL'])->json('data.party_id');

        foreach ([['Directeur Général', 'Younes Dir', 'owner'], ['Comptable', 'Hind Compta', 'employee']] as [$job, $name, $type]) {
            $this->actingAs($this->owner)->postJson(route('contacts.parties.relationships.add', $company), ['display_name' => $name, 'type' => $type, 'job_title' => $job])->assertCreated();
        }

        $people = Party::query()->whereIn('display_name', ['Younes Dir', 'Hind Compta'])->get();
        $this->assertCount(2, $people);
        $this->assertSame(2, DB::table('party_relationships')->where('related_party_id', $company)->count());
        $this->assertTrue($people->every(fn (Party $p) => $p->kind === 'person' && $p->id !== (int) $company));
        $page = $this->actingAs($this->owner)->get(route('contacts.parties.show', $company))->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(['Younes Dir', 'Hind Compta'], array_column(array_column($page['relationships'], 'party'), 'display_name'));
    }

    // ------------------------------------------------------------ scenarios 10-12: transactional entry points

    public function test_10_pos_and_quotation_quick_creation_still_return_the_customer_and_now_create_the_canonical_party(): void
    {
        $pos = $this->actingAs($this->owner)->postJson('/pos/customers', ['type' => 'individual', 'display_name' => 'Walk In Pos', 'phone' => '0611111111'])->assertCreated();
        $quote = $this->actingAs($this->owner)->postJson('/quotations/customers', ['type' => 'company', 'company_name' => 'Devis SARL', 'phone' => '0622222222'])->assertCreated();

        foreach ([[$pos, 'Walk In Pos'], [$quote, 'Devis SARL']] as [$response, $name]) {
            $customer = Customer::query()->findOrFail($response->json('data.id'));
            $this->assertSame($name, $response->json('data.display_name'));
            $this->assertNotNull($customer->party_id, 'a customer created from a transaction has its canonical identity');
            $this->assertSame(['client'], DB::table('party_roles as pr')->join('contact_roles as r', 'r.id', '=', 'pr.contact_role_id')->where('pr.party_id', $customer->party_id)->pluck('r.system_key')->all());
        }
    }

    public function test_11_a_quick_edit_keeps_the_advanced_fields_it_did_not_send(): void
    {
        $customer = app(CustomerManager::class)->create($this->owner, $this->org, [
            'type' => 'individual', 'display_name' => 'Keep Me', 'phone' => '0600', 'email' => 'keep@example.test', 'tax_identifier' => 'TX1', 'billing_address' => 'Rue 9', 'notes' => 'Important note', 'status' => 'active',
        ]);

        $this->actingAs($this->owner)->patchJson("/pos/customers/{$customer->id}", ['type' => 'individual', 'display_name' => 'Keep Me Renamed'])->assertOk();

        $fresh = $customer->fresh();
        $this->assertSame('Keep Me Renamed', $fresh->display_name);
        $this->assertSame('Important note', $fresh->notes, 'omitted notes are kept');
        $this->assertSame('TX1', $fresh->tax_identifier);
        $this->assertSame('Rue 9', $fresh->billing_address);
        $this->assertSame('keep@example.test', $fresh->email);
        $this->assertSame('Keep Me Renamed', Party::query()->findOrFail($fresh->party_id)->display_name, 'the canonical identity follows');
        // Sending a field empty clears it on purpose.
        $this->actingAs($this->owner)->patchJson("/pos/customers/{$customer->id}", ['type' => 'individual', 'display_name' => 'Keep Me Renamed', 'email' => ''])->assertOk();
        $this->assertNull($customer->fresh()->email);
    }

    // ------------------------------------------------------------ scenarios 13-15: legacy ids, permissions, isolation

    public function test_13_legacy_ids_are_resolved_through_their_own_party_never_by_numeric_coincidence(): void
    {
        // Make the three id namespaces disagree on purpose.
        $filler = $this->create(['kind' => 'person', 'display_name' => 'Filler'])->json('data.party_id');
        DB::table('parties')->where('id', '>', 0)->count();
        $customer = $this->createCustomer($this->org, 'Legacy Customer', ['type' => 'company']); // a pre-existing row with no party yet
        $contactId = DB::table('organization_contacts')->insertGetId(['organization_id' => $this->org->id, 'full_name' => 'Legacy Contact', 'contact_type' => 'other', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->get("/sales/customers/{$customer->id}/edit")->assertRedirect();
        $partyId = $customer->fresh()->party_id;
        $this->assertNotNull($partyId, 'an unresolved customer gets the party the backfill would have given it');
        $this->actingAs($this->owner)->get("/sales/customers/{$customer->id}/edit")->assertRedirect(route('contacts.parties.edit', $partyId));
        $this->assertSame('Legacy Customer', Party::query()->findOrFail($partyId)->display_name);

        $contactResponse = $this->actingAs($this->owner)->get(route('contacts.show', $contactId));
        $contactParty = OrganizationContact::query()->findOrFail($contactId)->party_id;
        $contactResponse->assertRedirect(route('contacts.parties.show', $contactParty));
        $this->assertSame('Legacy Contact', Party::query()->findOrFail($contactParty)->display_name);
        $this->assertNotSame($filler, $contactParty);

        // The old list URLs land on the matching view; the full customer sheet stays reachable.
        $this->actingAs($this->owner)->get('/sales/customers?search=Legacy')->assertRedirect(route('contacts.index', ['role' => 'client', 'search' => 'Legacy']));
        $this->actingAs($this->owner)->get('/procurement/suppliers')->assertRedirect(route('contacts.index', ['role' => 'supplier']));
        $this->actingAs($this->owner)->get("/sales/customers/{$customer->id}")->assertOk();
    }

    public function test_14_permissions_shape_what_each_profile_sees(): void
    {
        $this->create(['kind' => 'organization', 'display_name' => 'Dual Co', 'ice' => '001111111000011', 'roles' => ['client', 'supplier']])->assertCreated();
        $this->create(['kind' => 'person', 'display_name' => 'Plain Person'])->assertCreated();
        $partyId = Party::query()->where('display_name', 'Dual Co')->value('id');

        // customers.view only: keeps the Clients directory, nothing else.
        $sales = $this->member(['customers.view']);
        $rows = $this->actingAs($sales)->get(route('contacts.index'))->assertOk()->viewData('page')['props']['parties']['data'];
        $this->assertSame(['Dual Co'], array_column($rows, 'display_name'), 'forced to the Client view');
        $this->assertNull($rows[0]['supplier_id'], 'a supplier id is not disclosed without suppliers.view');
        $this->actingAs($sales)->get(route('contacts.parties.show', $partyId))->assertForbidden();
        $this->actingAs($sales)->get(route('contacts.create'))->assertForbidden();
        $this->actingAs($sales)->get(route('contacts.settings'))->assertForbidden();

        // contacts.view only: sees every contact, but no commercial ids and no conditions.
        $reader = $this->member(['contacts.view']);
        $page = $this->actingAs($reader)->get(route('contacts.parties.show', $partyId))->assertOk()->viewData('page')['props'];
        $this->assertNull($page['customer']);
        $this->assertNull($page['supplier']);
        $this->assertSame('001111111000011', $page['party']['ice']);
        $this->assertStringNotContainsStringIgnoringCase('iban', json_encode($page));

        // finance + customers: the Client tab carries the financial account link and the terms.
        $finance = $this->member(['contacts.view', 'customers.view', 'finance.view']);
        $tab = $this->actingAs($finance)->get(route('contacts.parties.show', $partyId))->viewData('page')['props']['customer'];
        $this->assertArrayHasKey('financial_account', $tab['links']);

        // No contact permission at all.
        $none = $this->member(['sales_orders.view']);
        $this->actingAs($none)->get(route('contacts.index'))->assertForbidden();
        $this->actingAs($none)->postJson(route('contacts.parties.store'), ['kind' => 'person', 'display_name' => 'x'])->assertForbidden();
    }

    public function test_15_another_organizations_contacts_roles_terms_and_relationships_are_unreachable(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->createOrganization($otherOwner);
        $otherStore = $this->createStore($other, $otherOwner);
        $this->activate($otherOwner, $other, $otherStore);
        $foreignId = $this->create(['kind' => 'organization', 'display_name' => 'Foreign Co', 'roles' => ['client'], 'custom_role' => 'SecretRole'], $otherOwner)->json('data.party_id');
        $foreignRole = DB::table('contact_roles')->where('organization_id', $other->id)->value('id');
        $foreignTerm = app(PaymentTermCatalogue::class)->list($other)[1]->id;
        $mine = $this->create(['kind' => 'organization', 'display_name' => 'My Co'])->json('data.party_id');

        $this->actingAs($this->owner)->get(route('contacts.parties.show', $foreignId))->assertNotFound();
        $this->actingAs($this->owner)->get(route('contacts.parties.edit', $foreignId))->assertNotFound();
        $this->actingAs($this->owner)->patchJson(route('contacts.parties.update', $foreignId), ['kind' => 'organization', 'display_name' => 'Hijack'])->assertNotFound();
        $this->actingAs($this->owner)->postJson(route('contacts.parties.roles.add', $foreignId), ['roles' => ['supplier']])->assertNotFound();
        $this->actingAs($this->owner)->postJson(route('contacts.parties.archive', $foreignId))->assertNotFound();
        $this->actingAs($this->owner)->postJson(route('contacts.parties.relationships.add', $foreignId), ['display_name' => 'X', 'type' => 'employee'])->assertNotFound();
        // A foreign role id / term id / party id inside MY request is refused as well.
        $this->actingAs($this->owner)->postJson(route('contacts.parties.roles.add', $mine), ['role_ids' => [$foreignRole]])->assertNotFound();
        $this->create(['kind' => 'organization', 'display_name' => 'Term Thief', 'roles' => ['client'], 'customer' => ['payment_term_id' => $foreignTerm]])->assertStatus(422);
        $this->create(['kind' => 'person', 'display_name' => 'Reuse Thief', 'reuse_party_id' => $foreignId])->assertStatus(422);

        $listed = $this->actingAs($this->owner)->get(route('contacts.index'))->viewData('page')['props']['parties']['data'];
        $this->assertNotContains('Foreign Co', array_column($listed, 'display_name'));
        $this->assertNotContains('SecretRole', array_column($this->actingAs($this->owner)->get(route('contacts.create'))->viewData('page')['props']['catalogue']['roles'], 'name'));
        $this->assertSame('Foreign Co', Party::query()->findOrFail($foreignId)->display_name);
    }

    // ------------------------------------------------------------ scenarios 16, 18, 19

    public function test_16_customer_and_supplier_terms_assigned_from_the_detail_page_stay_independent(): void
    {
        $partyId = $this->create(['kind' => 'organization', 'display_name' => 'Terms Co', 'roles' => ['client', 'supplier']])->json('data.party_id');
        $terms = collect(app(PaymentTermCatalogue::class)->list($this->org))->keyBy('days');

        $this->actingAs($this->owner)->postJson(route('contacts.parties.terms.assign', $partyId), ['profile' => 'customer', 'payment_term_id' => $terms[30]->id])->assertOk();
        $this->actingAs($this->owner)->postJson(route('contacts.parties.terms.assign', $partyId), ['profile' => 'supplier', 'payment_term_id' => $terms[90]->id])->assertOk();

        $page = $this->actingAs($this->owner)->get(route('contacts.parties.show', $partyId))->viewData('page')['props'];
        $this->assertSame($terms[30]->id, $page['customer']['terms']['payment_term_id']);
        $this->assertSame($terms[90]->id, $page['supplier']['payment_term_id']);
        $this->assertSame(0, DB::table('customer_payment_installments')->count(), 'no schedule or due date is created');
    }

    public function test_18_archive_hides_the_profiles_from_new_transactions_blocks_open_money_and_restore_reinstates_them(): void
    {
        $partyId = $this->create(['kind' => 'person', 'display_name' => 'Archivable Client', 'phone' => '0633333333', 'roles' => ['client']])->json('data.party_id');
        $customerId = Customer::query()->where('party_id', $partyId)->value('id');
        $found = fn () => collect($this->actingAs($this->owner)->getJson('/pos/customers?search=Archivable')->json('data'))->pluck('id')->all();
        $this->assertSame([$customerId], $found());

        $this->actingAs($this->owner)->post(route('contacts.parties.archive', $partyId))->assertRedirect();
        $this->assertSame('inactive', Customer::query()->find($customerId)->status->value);
        $this->assertSame([], $found(), 'an archived contact is not offered to the POS');
        $this->assertNotNull(Customer::query()->find($customerId), 'the profile and its history are kept');
        $archived = $this->actingAs($this->owner)->get(route('contacts.index', ['status' => 'archived']))->viewData('page')['props']['parties']['data'];
        $this->assertSame([$partyId], array_column($archived, 'id'));
        $this->actingAs($this->owner)->get(route('contacts.parties.show', $partyId))->assertOk();

        $this->actingAs($this->owner)->post(route('contacts.parties.restore', $partyId))->assertRedirect();
        $this->assertSame('active', Customer::query()->find($customerId)->status->value);
        $this->assertSame([$customerId], $found());

        // Open money blocks the archive.
        $debtor = app(CustomerManager::class)->create($this->owner, $this->org, ['type' => 'company', 'display_name' => 'Debtor Co', 'status' => 'active']);
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $debtor);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '300.0000']);
        app(ConfirmSalesOrderAction::class)->execute($this->owner, $order);
        $this->actingAs($this->owner)->postJson(route('contacts.parties.archive', $debtor->party_id))->assertStatus(422);
        $this->assertSame('active', Party::query()->find($debtor->party_id)->status);
    }

    public function test_19_a_failing_commercial_activation_leaves_no_partial_contact(): void
    {
        // Allowed to create contacts and customers, NOT suppliers: the Fournisseur half fails after the identity and the customer exist.
        $partial = $this->member(['contacts.view', 'contacts.create', 'contacts.update', 'customers.create']);

        $this->create(['kind' => 'organization', 'display_name' => 'All Or Nothing', 'roles' => ['client', 'supplier']], $partial)->assertForbidden();

        $this->assertSame(0, Party::query()->where('display_name', 'All Or Nothing')->count());
        $this->assertSame(0, Customer::query()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, Supplier::query()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', 'party.%')->count(), 'no audit row survives the rollback');
        // And the same request, authorized, commits everything.
        $this->create(['kind' => 'organization', 'display_name' => 'All Or Nothing', 'roles' => ['client', 'supplier']])->assertCreated();
        $this->assertSame(1, Customer::query()->where('organization_id', $this->org->id)->count());
        $this->assertSame(1, Supplier::query()->where('organization_id', $this->org->id)->count());
    }

    // ------------------------------------------------------------ directory, duplicates, edit, settings

    public function test_the_directory_filters_on_the_server_and_defines_internal_external_and_partners(): void
    {
        $this->create(['kind' => 'person', 'display_name' => 'Staff Member', 'roles' => ['internal']])->assertCreated();
        $this->create(['kind' => 'organization', 'display_name' => 'Trans Rapide', 'roles' => ['carrier'], 'ice' => '009999999000011'])->assertCreated();
        $this->create(['kind' => 'organization', 'display_name' => 'Prest Co', 'roles' => ['provider']])->assertCreated();
        $this->create(['kind' => 'person', 'display_name' => 'Client Lambda', 'roles' => ['client']])->assertCreated();
        $this->create(['kind' => 'person', 'display_name' => 'Staff And Client', 'roles' => ['internal', 'client']])->assertCreated();
        $names = fn (array $q) => collect($this->actingAs($this->owner)->get(route('contacts.index', $q))->viewData('page')['props']['parties']['data'])->pluck('display_name')->sort()->values()->all();

        $this->assertSame(['Staff And Client', 'Staff Member'], $names(['role' => 'internal']));
        $this->assertSame(['Client Lambda', 'Prest Co', 'Trans Rapide'], $names(['scope' => 'external']), 'external = does not hold the Interne role');
        $this->assertSame(['Prest Co', 'Trans Rapide'], $names(['group' => 'partners']));
        $this->assertSame(['Client Lambda', 'Staff And Client'], $names(['roles' => ['client']]));
        $this->assertSame(['Client Lambda', 'Prest Co', 'Staff And Client', 'Staff Member', 'Trans Rapide'], $names([]));
        $this->assertSame(['Trans Rapide'], $names(['search' => '009999999']), 'ICE search for an authorized viewer');
        $this->assertSame(['Staff Member'], $names(['kind' => 'person', 'roles' => ['internal'], 'search' => 'Member']));
        $this->actingAs($this->owner)->get(route('contacts.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Contacts/Index')->has('parties.data', 5)->has('parties.data.0', fn (Assert $row) => $row->hasAll(['id', 'display_name', 'kind', 'status', 'phone', 'email', 'roles'])->etc()));
    }

    public function test_duplicate_candidates_are_graded_and_nothing_is_merged_automatically(): void
    {
        $existing = $this->create(['kind' => 'organization', 'display_name' => 'Maroc Textile', 'email' => 'contact@textile.test', 'phone' => '+212 600-112233', 'ice' => '001122334455667'])->json('data.party_id');
        $this->create(['kind' => 'person', 'display_name' => 'Someone Else', 'email' => 'x@y.test'])->assertCreated();
        $candidates = fn (array $q) => $this->actingAs($this->owner)->getJson(route('contacts.candidates', $q))->assertOk()->json('data');

        $byName = $candidates(['display_name' => 'maroc  TEXTILE', 'kind' => 'organization']);
        $this->assertSame([$existing], array_column($byName, 'id'));
        $this->assertSame('strong', $byName[0]['strength']);
        $byIce = $candidates(['display_name' => 'Different name', 'ice' => '001122334455667']);
        $this->assertSame('strong', $byIce[0]['strength']);
        $this->assertStringNotContainsString('001122334455667', json_encode($byIce), 'the ICE is never echoed');
        $byMail = $candidates(['display_name' => 'Zzz', 'email' => 'CONTACT@textile.test']);
        $this->assertSame('weak', $byMail[0]['strength'], 'a shared e-mail is not proof');
        $this->assertSame('weak', $candidates(['display_name' => 'Zzz', 'phone' => '0600 11 22 33'])[0]['strength']);
        $this->assertSame([], $candidates(['display_name' => 'Totally Unknown']));
        // Reuse adds roles to the existing identity instead of creating another one.
        $response = $this->create(['kind' => 'organization', 'display_name' => 'Maroc Textile', 'reuse_party_id' => $existing, 'roles' => ['supplier']])->assertOk();
        $this->assertTrue($response->json('data.reused'));
        $this->assertSame($existing, $response->json('data.party_id'));
        $this->assertSame(1, Party::query()->where('display_name', 'Maroc Textile')->count());
        $this->assertSame(1, Supplier::query()->where('party_id', $existing)->count());
    }

    public function test_the_edit_form_updates_identity_without_touching_roles_unless_asked_and_never_reactivates_silently(): void
    {
        $partyId = $this->create(['kind' => 'organization', 'display_name' => 'Edit Me', 'roles' => ['client']])->json('data.party_id');
        $customerId = Customer::query()->where('party_id', $partyId)->value('id');

        // Identity-only update: roles untouched.
        $this->actingAs($this->owner)->patchJson(route('contacts.parties.update', $partyId), ['kind' => 'organization', 'display_name' => 'Edit Me Renamed', 'email' => 'new@x.test'])->assertOk();
        $this->assertSame('Edit Me Renamed', Customer::query()->find($customerId)->display_name, 'mirrored to the legacy profile');
        $this->assertSame(1, DB::table('party_roles')->where('party_id', $partyId)->where('status', 'active')->count());

        // Un-tick Client (no open money): soft deactivation, the profile and its id survive.
        $this->actingAs($this->owner)->patchJson(route('contacts.parties.update', $partyId), ['kind' => 'organization', 'display_name' => 'Edit Me Renamed', 'roles' => [], 'role_ids' => []])->assertOk();
        $this->assertSame(0, DB::table('party_roles')->where('party_id', $partyId)->where('status', 'active')->count());
        $this->assertNotNull(Customer::query()->find($customerId));

        // A later identity edit does NOT bring the role back; ticking it again does, reusing the same customer.
        $this->actingAs($this->owner)->patchJson(route('contacts.parties.update', $partyId), ['kind' => 'organization', 'display_name' => 'Edit Me Again'])->assertOk();
        $this->assertSame(0, DB::table('party_roles')->where('party_id', $partyId)->where('status', 'active')->count());
        $this->actingAs($this->owner)->patchJson(route('contacts.parties.update', $partyId), ['kind' => 'organization', 'display_name' => 'Edit Me Again', 'roles' => ['client']])->assertOk();
        $this->assertSame($customerId, Customer::query()->where('party_id', $partyId)->value('id'));
        $this->assertSame(1, DB::table('party_roles')->where('party_id', $partyId)->where('status', 'active')->count());
        $this->actingAs($this->owner)->get(route('contacts.parties.edit', $partyId))->assertInertia(fn (Assert $page) => $page->component('Contacts/Form')->where('party.display_name', 'Edit Me Again'));
    }

    public function test_settings_expose_roles_terms_and_a_read_only_conflict_review(): void
    {
        DB::table('party_migration_conflicts')->insert(['organization_id' => $this->org->id, 'reason' => 'shared_email_not_merged', 'source_type' => 'party', 'source_id' => 1, 'related_type' => 'party', 'related_id' => 2,
            'resolution' => 'kept_separate', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->get(route('contacts.settings'))->assertInertia(fn (Assert $page) => $page->component('Contacts/Settings')
            ->has('roles', 8)->has('paymentTerms', 5)->has('conflicts', 1)->where('can.manageRoles', true));
        $this->actingAs($this->owner)->post(route('contacts.settings.roles.store'), ['name' => 'Architecte'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('contacts.settings.roles.store'), ['name' => ' ARCHITECTE '])->assertSessionHasErrors('name');
        $this->actingAs($this->owner)->post(route('contacts.settings.terms.store'), ['days' => 45])->assertRedirect();
        $this->actingAs($this->owner)->post(route('contacts.settings.terms.store'), ['days' => 45])->assertRedirect();
        $this->assertSame(1, DB::table('payment_terms')->where('organization_id', $this->org->id)->where('days', 45)->count());
        $role = DB::table('contact_roles')->where('organization_id', $this->org->id)->first();
        $this->actingAs($this->owner)->post(route('contacts.settings.roles.deactivate', $role->id))->assertRedirect();
        $this->assertSame(0, (int) DB::table('contact_roles')->where('id', $role->id)->value('active'));
        $systemRole = $this->roleId('client');
        $this->actingAs($this->owner)->post(route('contacts.settings.roles.deactivate', $systemRole))->assertNotFound();

        // A reader sees the catalogues but neither the conflicts nor the forms.
        $reader = $this->member(['contacts.view']);
        $this->actingAs($reader)->get(route('contacts.settings'))->assertInertia(fn (Assert $page) => $page->where('conflicts', null)->where('can.manageRoles', false)->where('can.manageTerms', false));
        $this->actingAs($reader)->post(route('contacts.settings.roles.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($reader)->post(route('contacts.settings.terms.store'), ['days' => 10])->assertForbidden();
    }

    public function test_historical_documents_and_financial_positions_do_not_move_when_a_shared_identity_is_edited(): void
    {
        $partyId = $this->create(['kind' => 'organization', 'display_name' => 'History Co', 'roles' => ['client']])->json('data.party_id');
        $customer = Customer::query()->where('party_id', $partyId)->firstOrFail();
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '800.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $position = fn () => app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$customer->id], $this->owner)[$customer->id];
        $before = ['position' => $position(), 'order' => (array) DB::table('sales_orders')->find($order->id)];

        $this->actingAs($this->owner)->patchJson(route('contacts.parties.update', $partyId), ['kind' => 'organization', 'display_name' => 'History Co Renamed', 'email' => 'moved@x.test', 'address' => 'New address'])->assertOk();

        $this->assertSame('History Co Renamed', $customer->fresh()->display_name);
        $this->assertEquals($before['position'], $position());
        $this->assertEquals($before['order'], (array) DB::table('sales_orders')->find($order->id));
    }
}
