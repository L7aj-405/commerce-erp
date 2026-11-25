<?php

namespace Tests\Feature\Contacts;

use App\Actions\Contacts\ActivateCommercialProfileAction;
use App\Actions\Contacts\ArchivePartyAction;
use App\Actions\Contacts\LinkPartyRelationshipAction;
use App\Actions\Contacts\ManageContactRolesCatalogueAction;
use App\Actions\Contacts\ManagePartyRolesAction;
use App\Actions\Contacts\ManagePaymentTermsAction;
use App\Actions\Contacts\SavePartyAction;
use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Finance\Customers\SaveCustomerFinancialTermsAction;
use App\Actions\Procurement\SaveSupplierAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\ContactSystemRole;
use App\Models\ContactRole;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Contacts\ContactRoleRegistry;
use App\Services\Contacts\PartyBackfillService;
use App\Services\Contacts\PartyIdentitySerializer;
use App\Services\Contacts\PaymentTermCatalogue;
use App\Services\CustomerManager;
use App\Services\Finance\CustomerFinancialPositionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentTestCase;

/** Unified Contacts, Phase 1 (backend): Party identity, roles, commercial profiles, payment terms, backfill. */
class UnifiedContactsPhase1Test extends PaymentTestCase
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
        $this->actingAs($this->owner);
    }

    private function member(array $permissions, ?Organization $org = null): User
    {
        $org ??= $this->org;
        $user = User::factory()->create();
        $this->addOrganizationMember($org, $user, $permissions, 'active', 'R'.Str::random(5));
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $org, $this->store);

        return $user;
    }

    private function party(string $name = 'Amina', string $kind = 'person', array $extra = []): Party
    {
        return app(SavePartyAction::class)->execute($this->owner, $this->org, ['kind' => $kind, 'display_name' => $name] + $extra);
    }

    private function supplierLegacy(string $name, array $extra = []): Supplier
    {
        return app(SaveSupplierAction::class)->execute($this->owner, $this->org, ['name' => $name] + $extra);
    }

    private function role(ContactSystemRole $role): ContactRole
    {
        return app(ContactRoleRegistry::class)->system($role);
    }

    // ------------------------------------------------------------ identity

    public function test_a_simple_individual_contact_needs_no_fiscal_or_financial_data(): void
    {
        $party = $this->party('Amina Client');

        $this->assertSame('person', $party->kind);
        $this->assertSame('active', $party->status);
        $this->assertNull($party->ice);
        $this->assertSame(0, Customer::query()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, Supplier::query()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, PartyRole::query()->where('party_id', $party->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'party.created']);
    }

    public function test_an_organization_contact_keeps_optional_fiscal_identity(): void
    {
        $party = $this->party('ACME SARL', 'organization', ['ice' => '001234567000089', 'rc_number' => 'RC 123', 'email' => 'Hello@Acme.TEST', 'city' => 'Casablanca', 'country_code' => 'ma']);

        $this->assertSame('organization', $party->kind);
        $this->assertSame('001234567000089', $party->ice);
        $this->assertSame('hello@acme.test', $party->email);
        $this->assertSame('MA', $party->country_code);
    }

    // ------------------------------------------------------------ commercial profiles

    public function test_customer_only_and_supplier_only_identities(): void
    {
        $a = $this->party('Cust Only');
        $b = $this->party('Supp Only', 'organization');

        $customer = app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $a);
        $supplier = app(ActivateCommercialProfileAction::class)->supplier($this->owner, $this->org, $b);

        $this->assertSame($a->id, $customer->party_id);
        $this->assertSame($b->id, $supplier->party_id);
        $this->assertSame('individual', $customer->type->value);
        $this->assertNull(Supplier::query()->where('party_id', $a->id)->first());
        $this->assertNull(Customer::query()->where('party_id', $b->id)->first());
        $this->assertSame(['client'], $this->activeRoleKeys($a));
        $this->assertSame(['supplier'], $this->activeRoleKeys($b));
    }

    public function test_customer_and_supplier_are_created_together_on_one_identity(): void
    {
        $party = $this->party('Dual SARL', 'organization', ['ice' => '009999999000011']);

        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, [ContactSystemRole::Client, ContactSystemRole::Supplier]);

        $this->assertSame(1, Party::query()->where('organization_id', $this->org->id)->count());
        $this->assertSame(1, Customer::query()->where('party_id', $party->id)->count());
        $this->assertSame(1, Supplier::query()->where('party_id', $party->id)->count());
        $this->assertSame('009999999000011', Supplier::query()->where('party_id', $party->id)->value('ice'));
        $this->assertEqualsCanonicalizing(['client', 'supplier'], $this->activeRoleKeys($party));
    }

    public function test_existing_customer_becomes_supplier_and_ids_are_preserved(): void
    {
        $customer = app(CustomerManager::class)->create($this->owner, $this->org, ['type' => 'company', 'display_name' => 'Legacy Co', 'company_name' => 'Legacy Co SARL', 'status' => 'active']);
        $customerId = $customer->id;
        $this->assertNotNull($customer->fresh()->party_id, 'a new legacy customer gets its party immediately');

        $supplier = app(ActivateCommercialProfileAction::class)->supplier($this->owner, $this->org, Party::query()->findOrFail($customer->party_id));

        $this->assertSame($customerId, $customer->fresh()->id);
        $this->assertSame($customer->fresh()->party_id, $supplier->party_id);
        $this->assertSame(1, Party::query()->where('organization_id', $this->org->id)->count(), 'no duplicate identity');
        $this->assertSame('Legacy Co', $supplier->name);
    }

    public function test_existing_supplier_becomes_customer_and_ids_are_preserved(): void
    {
        $supplier = $this->supplierLegacy('Fourn SARL', ['ice' => '001111111000022']);
        $supplierId = $supplier->id;

        $customer = app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, Party::query()->findOrFail($supplier->fresh()->party_id));

        $this->assertSame($supplierId, $supplier->fresh()->id);
        $this->assertSame($supplier->fresh()->party_id, $customer->party_id);
        $this->assertSame('company', $customer->type->value);
        $this->assertSame(1, Party::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_repeated_role_activation_is_idempotent(): void
    {
        $party = $this->party('Repeat');
        $first = app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $party);
        app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $party);
        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, [ContactSystemRole::Client]);

        $this->assertSame(1, Customer::query()->where('party_id', $party->id)->count());
        $this->assertSame($first->id, Customer::query()->where('party_id', $party->id)->value('id'));
        $this->assertSame(1, PartyRole::query()->where('party_id', $party->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'party.customer_profile_created')->count());
    }

    public function test_concurrent_activation_reuses_the_profile_created_by_the_other_session(): void
    {
        $party = $this->party('Race');
        $stale = Party::query()->findOrFail($party->id); // the "second session" loaded the party before the first one finished

        app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $party);
        $second = app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $stale);

        $this->assertSame(1, Customer::query()->where('party_id', $party->id)->count());
        $this->assertSame($second->id, Customer::query()->where('party_id', $party->id)->value('id'));

        // The database itself refuses a second commercial profile for the same party (the last line of defence).
        $this->expectException(QueryException::class);
        DB::table('customers')->insert(['organization_id' => $this->org->id, 'party_id' => $party->id, 'type' => 'individual', 'display_name' => 'Dup', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------ roles

    public function test_custom_role_is_persisted_and_reused_case_insensitively(): void
    {
        $a = $this->party('A');
        $b = $this->party('B');

        $first = app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $a, ['Électricien Agréé'])[0];
        $second = app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $b, ['  electricien   AGREE '])[0];

        $this->assertSame($first->id, $second->id, 'the same role is reused, not duplicated');
        $this->assertFalse((bool) $first->is_system);
        $this->assertSame(1, ContactRole::query()->where('organization_id', $this->org->id)->count());
        $this->assertCount(0, Customer::query()->where('organization_id', $this->org->id)->get(), 'a custom role grants no commercial profile');
    }

    public function test_duplicate_custom_role_is_rejected_by_strict_creation_and_system_roles_are_protected(): void
    {
        $action = app(ManageContactRolesCatalogueAction::class);
        $role = $action->create($this->owner, $this->org, 'Architecte');

        foreach (['architecte', ' ARCHITECTE ', 'Fournisseur', 'client'] as $dup) {
            try {
                $action->create($this->owner, $this->org, $dup);
                $this->fail("{$dup} must be rejected");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('name', $e->errors());
            }
        }

        $system = $this->role(ContactSystemRole::Client);
        $this->expectException(HttpException::class);
        try {
            $action->deactivate($this->owner, $this->org, $system);
        } finally {
            $this->assertTrue((bool) $system->fresh()->active);
            $this->assertNotNull($role->id);
        }
    }

    public function test_the_same_custom_role_name_may_exist_in_two_organizations_without_leaking(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->createOrganization($otherOwner);
        $this->activate($otherOwner, $other, $this->createStore($other, $otherOwner));

        $mine = app(ManageContactRolesCatalogueAction::class)->create($this->owner, $this->org, 'Notaire');
        $theirs = app(ManageContactRolesCatalogueAction::class)->create($otherOwner, $other, 'Notaire');

        $this->assertNotSame($mine->id, $theirs->id);
        $this->expectException(ModelNotFoundException::class);
        app(ContactRoleRegistry::class)->findForOrganization($theirs->id, $this->org);
    }

    public function test_system_roles_are_the_eight_stable_identifiers(): void
    {
        $keys = ContactRole::query()->where('organization_scope', 0)->where('is_system', true)->pluck('system_key')->all();

        $this->assertEqualsCanonicalizing(['client', 'supplier', 'internal', 'provider', 'carrier', 'partner', 'interlocutor', 'prospect'], $keys);
        $this->assertSame(['Client', 'Fournisseur', 'Interne', 'Prestataire', 'Transporteur', 'Partenaire', 'Interlocuteur', 'Prospect'], array_map(fn ($c) => $c->label(), ContactSystemRole::cases()));
    }

    public function test_role_deactivation_is_soft_and_never_deletes_the_commercial_profile(): void
    {
        $party = $this->party('Soft');
        $customer = app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $party);

        app(ManagePartyRolesAction::class)->remove($this->owner, $this->org, $party, $this->role(ContactSystemRole::Client));

        $this->assertNotNull(Customer::query()->find($customer->id), 'profile kept');
        $this->assertSame([], $this->activeRoleKeys($party));
        $this->assertSame(1, PartyRole::query()->where('party_id', $party->id)->where('status', 'inactive')->count(), 'history row kept');

        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, [ContactSystemRole::Client]);
        $this->assertSame($customer->id, Customer::query()->where('party_id', $party->id)->value('id'), 'reactivation reuses the same customer id');
        $this->assertSame(['client'], $this->activeRoleKeys($party));
    }

    // ------------------------------------------------------------ relationships

    public function test_person_to_organization_relationship_without_merging_identities(): void
    {
        $person = $this->party('Karim', 'person', ['email' => 'shared@acme.test']);
        $company = $this->party('ACME', 'organization', ['email' => 'shared@acme.test']);
        $action = app(LinkPartyRelationshipAction::class);

        $a = $action->execute($this->owner, $this->org, $person, $company, 'employee', 'Achats', true);
        $b = $action->execute($this->owner, $this->org, $person, $company, 'employee');

        $this->assertSame($a->id, $b->id, 'idempotent');
        $this->assertSame(2, Party::query()->where('organization_id', $this->org->id)->count(), 'a shared email never merges identities');
        $this->assertSame('Achats', $a->fresh()->job_title);
        $this->assertTrue($a->fresh()->is_primary);

        $this->expectException(ValidationException::class);
        $action->execute($this->owner, $this->org, $company, $person);
    }

    public function test_relationship_across_organizations_is_refused(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->createOrganization($otherOwner);
        $this->activate($otherOwner, $other, $this->createStore($other, $otherOwner));
        $foreign = app(SavePartyAction::class)->execute($otherOwner, $other, ['kind' => 'organization', 'display_name' => 'Foreign']);

        $this->expectException(HttpException::class);
        app(LinkPartyRelationshipAction::class)->execute($this->owner, $this->org, $this->party('P'), $foreign);
    }

    // ------------------------------------------------------------ payment terms

    public function test_catalogue_has_the_standard_terms_and_reuses_custom_delays(): void
    {
        $catalogue = app(PaymentTermCatalogue::class);
        $names = array_map(fn ($t) => $t->name.':'.$t->days, $catalogue->list($this->org));
        $this->assertSame(['Comptant:0', '30 jours:30', '60 jours:60', '90 jours:90', '120 jours:120'], $names);

        $action = app(ManagePaymentTermsAction::class);
        $custom = $action->create($this->owner, $this->org, 45, null, ['deposit_percent' => 30, 'installments' => [['percent' => 70, 'days' => 45]]]);
        $again = $action->create($this->owner, $this->org, 45);

        $this->assertSame($custom->id, $again->id);
        $this->assertSame(0, DB::table('customer_payment_installments')->count(), 'the catalogue never creates a schedule');
        $this->expectException(ValidationException::class);
        $action->create($this->owner, $this->org, 400);
    }

    public function test_customer_and_supplier_payment_terms_are_independent_on_one_party(): void
    {
        $party = $this->party('Both', 'organization');
        $roles = app(ManagePartyRolesAction::class);
        $roles->add($this->owner, $this->org, $party, [ContactSystemRole::Client, ContactSystemRole::Supplier]);
        $customer = Customer::query()->where('party_id', $party->id)->firstOrFail();
        $supplier = Supplier::query()->where('party_id', $party->id)->firstOrFail();
        $terms = collect(app(PaymentTermCatalogue::class)->list($this->org))->keyBy('days');
        DB::table('customer_financial_terms')->insert(['organization_id' => $this->org->id, 'customer_id' => $customer->id, 'credit_enabled' => true, 'credit_limit' => '5000.0000', 'invoicing_policy' => 'after_full_payment', 'created_at' => now(), 'updated_at' => now()]);
        $supplier->default_currency_code = 'EUR';
        $supplier->save();

        $action = app(ManagePaymentTermsAction::class);
        $action->assignToCustomer($this->owner, $customer, $terms[30]);
        $action->assignToSupplier($this->owner, $supplier, $terms[90]);

        $row = DB::table('customer_financial_terms')->where('customer_id', $customer->id)->first();
        $this->assertSame($terms[30]->id, (int) $row->payment_term_id);
        $this->assertSame(30, (int) $row->payment_term_days);
        $this->assertSame($terms[90]->id, $supplier->fresh()->payment_term_id);
        $this->assertSame(90, (int) $supplier->fresh()->default_payment_term_days);
        // Existing configuration is preserved.
        $this->assertSame(0, bccomp('5000', (string) $row->credit_limit, 4));
        $this->assertSame('after_full_payment', $row->invoicing_policy);
        $this->assertSame('EUR', $supplier->fresh()->default_currency_code);
    }

    public function test_a_manual_customer_delay_that_diverges_detaches_the_catalogue_term(): void
    {
        $customer = app(CustomerManager::class)->create($this->owner, $this->org, ['type' => 'individual', 'display_name' => 'Delay', 'status' => 'active']);
        $term = collect(app(PaymentTermCatalogue::class)->list($this->org))->firstWhere('days', 60);
        app(ManagePaymentTermsAction::class)->assignToCustomer($this->owner, $customer, $term);

        app(SaveCustomerFinancialTermsAction::class)->execute($this->owner, $customer, ['payment_term_days' => 45, 'overdue_tolerance_days' => 0]);

        $row = DB::table('customer_financial_terms')->where('customer_id', $customer->id)->first();
        $this->assertNull($row->payment_term_id);
        $this->assertSame(45, (int) $row->payment_term_days);
    }

    // ------------------------------------------------------------ preservation

    public function test_backfill_preserves_ids_balances_and_issued_documents_and_identity_edits_do_not_touch_them(): void
    {
        $customer = $this->createCustomer($this->org, 'Hist Co', ['type' => 'company', 'company_name' => 'Hist Co SARL', 'email' => 'h@example.test']);
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '1000.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $draft = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($this->owner, $order);
        app(IssueInvoiceAction::class)->execute($this->owner, $draft);
        $position = fn () => app(CustomerFinancialPositionService::class)->forCustomers($this->org, [$customer->id], $this->owner)[$customer->id];
        $before = ['position' => $position(), 'invoice' => (array) DB::table('invoices')->first(), 'order' => (array) DB::table('sales_orders')->find($order->id)];

        app(PartyBackfillService::class)->run();

        $this->assertSame($customer->id, Customer::query()->where('display_name', 'Hist Co')->value('id'));
        $this->assertNotNull($customer->fresh()->party_id);
        $this->assertEquals($before['position'], $position());
        $this->assertEquals($before['invoice'], (array) DB::table('invoices')->first());
        $this->assertEquals($before['order'], (array) DB::table('sales_orders')->find($order->id));

        // Renaming the identity updates the live mirror but never an issued document or the financial position.
        app(SavePartyAction::class)->execute($this->owner, $this->org, ['kind' => 'organization', 'display_name' => 'Renamed Co', 'email' => 'new@example.test'], Party::query()->findOrFail($customer->fresh()->party_id));

        $this->assertSame('Renamed Co', $customer->fresh()->display_name);
        $this->assertEquals($before['position'], $position());
        $this->assertEquals($before['invoice'], (array) DB::table('invoices')->first());
        $this->assertEquals($before['order'], (array) DB::table('sales_orders')->find($order->id));
    }

    // ------------------------------------------------------------ migration of ambiguous legacy data

    public function test_backfill_merges_only_unambiguous_identities_and_reports_the_rest(): void
    {
        $org = $this->org->id;
        $customerCo = $this->createCustomer($this->org, 'Same Ice SARL', ['type' => 'company', 'tax_identifier' => '00 1234 5678 9001 1']);
        $this->createCustomer($this->org, 'Name Twin', ['type' => 'company', 'tax_identifier' => 'X']);
        $this->createCustomer($this->org, 'Person Buyer', ['type' => 'individual']);
        $this->supplierLegacyRaw('Fourn Same Ice', '001234567890011');
        $twinSupplier = $this->supplierLegacyRaw('Name Twin', '009999999999999');

        $contact = fn (array $a) => DB::table('organization_contacts')->insertGetId($a + ['organization_id' => $org, 'contact_type' => 'other', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sameName = $contact(['full_name' => 'Person Buyer', 'customer_id' => Customer::query()->where('display_name', 'Person Buyer')->value('id'), 'contact_type' => 'client']);
        $interlocutor = $contact(['full_name' => 'Karim Achats', 'job_title' => 'Achats', 'customer_id' => $customerCo->id, 'contact_type' => 'client', 'email' => 'k@ssl.test']);
        $other = $contact(['full_name' => 'Mystery Person', 'contact_type' => 'other']);
        $both = $contact(['full_name' => 'Dual Link', 'customer_id' => $customerCo->id, 'supplier_id' => $twinSupplier, 'contact_type' => 'supplier']);
        $originalCounts = [DB::table('customers')->count(), DB::table('suppliers')->count(), DB::table('organization_contacts')->count()];

        $report = app(PartyBackfillService::class)->run();

        $this->assertSame($originalCounts, [DB::table('customers')->count(), DB::table('suppliers')->count(), DB::table('organization_contacts')->count()], 'no legacy row added or removed');
        // Same 15-digit ICE on exactly one customer and one supplier -> one identity.
        $partyId = Customer::query()->find($customerCo->id)->party_id;
        $this->assertSame($partyId, Supplier::query()->where('name', 'Fourn Same Ice')->value('party_id'));
        $this->assertSame(1, $report['suppliers_merged']);
        // Same display name but a different ICE -> kept separate and reported.
        $this->assertNotSame(Customer::query()->where('display_name', 'Name Twin')->value('party_id'), Supplier::query()->where('name', 'Name Twin')->value('party_id'));
        $this->assertTrue(DB::table('party_migration_conflicts')->where('reason', 'possible_duplicate_identity')->exists());
        // A contact bearing exactly the account name adopts that identity; an interlocutor with a job title does not.
        $this->assertSame(Customer::query()->where('display_name', 'Person Buyer')->value('party_id'), DB::table('organization_contacts')->find($sameName)->party_id);
        $karim = DB::table('organization_contacts')->find($interlocutor)->party_id;
        $this->assertNotSame($partyId, $karim);
        $this->assertTrue(DB::table('party_relationships')->where('party_id', $karim)->where('related_party_id', $partyId)->where('type', 'interlocutor')->exists());
        $this->assertTrue(DB::table('party_migration_conflicts')->where('reason', 'contact_linked_to_account_not_identical')->where('source_id', $interlocutor)->exists());
        // Dual-linked contact is ambiguous.
        $this->assertTrue(DB::table('party_migration_conflicts')->where('reason', 'contact_linked_to_customer_and_supplier')->where('source_id', $both)->exists());
        // Legacy "other" keeps its meaning and gets no role.
        $mystery = DB::table('parties')->find(DB::table('organization_contacts')->find($other)->party_id);
        $this->assertSame('other', $mystery->legacy_contact_type);
        $this->assertSame(0, DB::table('party_roles')->where('party_id', $mystery->id)->count());
        // Customer -> Client role; supplier -> Fournisseur; contact types map to roles.
        $this->assertSame(['client', 'supplier'], DB::table('party_roles as pr')->join('contact_roles as r', 'r.id', '=', 'pr.contact_role_id')->where('pr.party_id', $partyId)->orderBy('r.system_key')->pluck('r.system_key')->all());
        // Idempotent re-run.
        $partyCount = DB::table('parties')->count();
        $rerun = app(PartyBackfillService::class)->run();
        $this->assertSame($partyCount, DB::table('parties')->count());
        $this->assertSame(0, $rerun['customers'] + $rerun['suppliers'] + $rerun['contacts'] + $rerun['conflicts']);
    }

    public function test_backfill_links_exact_standard_terms_and_leaves_other_delays_untouched(): void
    {
        $a = $this->createCustomer($this->org, 'T30');
        $b = $this->createCustomer($this->org, 'T45');
        DB::table('customer_financial_terms')->insert([
            ['organization_id' => $this->org->id, 'customer_id' => $a->id, 'payment_term_days' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->org->id, 'customer_id' => $b->id, 'payment_term_days' => 45, 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(PartyBackfillService::class)->run();

        $rows = DB::table('customer_financial_terms')->orderBy('customer_id')->get();
        $this->assertNotNull($rows[0]->payment_term_id);
        $this->assertNull($rows[1]->payment_term_id);
        $this->assertSame(45, (int) $rows[1]->payment_term_days);
    }

    // ------------------------------------------------------------ transitional source of truth

    public function test_legacy_edits_propagate_through_the_party_so_identities_cannot_diverge(): void
    {
        $party = $this->party('Shared Co', 'organization', ['email' => 'a@shared.test']);
        app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $party, [ContactSystemRole::Client, ContactSystemRole::Supplier]);
        $customer = Customer::query()->where('party_id', $party->id)->firstOrFail();
        $supplier = Supplier::query()->where('party_id', $party->id)->firstOrFail();

        // Edit through the LEGACY customer form: the party and the sibling supplier follow.
        app(CustomerManager::class)->update($this->owner, $customer, ['type' => 'company', 'display_name' => 'Shared Renamed', 'company_name' => 'Shared Renamed SARL', 'email' => 'b@shared.test', 'phone' => '0611111111', 'status' => 'active']);
        $this->assertSame('Shared Renamed', $party->fresh()->display_name);
        $this->assertSame('b@shared.test', $supplier->fresh()->email);
        $this->assertSame('Shared Renamed', $supplier->fresh()->name);
        $this->assertSame('0611111111', $supplier->fresh()->phone);

        // Edit through the LEGACY supplier action: the party and the sibling customer follow.
        app(SaveSupplierAction::class)->execute($this->owner, $this->org, ['name' => 'Supplier Side', 'email' => 'c@shared.test', 'phone' => '0622222222', 'ice' => '001111111000033'], $supplier->fresh());
        $this->assertSame('Supplier Side', $party->fresh()->display_name);
        $this->assertSame('Supplier Side', $customer->fresh()->display_name);
        $this->assertSame('001111111000033', $party->fresh()->ice);
    }

    public function test_the_legacy_contact_form_keeps_working_and_never_merges_a_person_into_a_company(): void
    {
        $company = app(CustomerManager::class)->create($this->owner, $this->org, ['type' => 'company', 'display_name' => 'Shop SARL', 'status' => 'active', 'email' => 'shop@example.test']);

        $this->actingAs($this->owner)->post(route('contacts.store'), [
            'full_name' => 'Salma', 'job_title' => 'Gérante', 'email' => 'shop@example.test', 'contact_type' => 'client', 'customer_id' => $company->id, 'active' => true,
        ])->assertRedirect();

        $contact = OrganizationContact::query()->where('full_name', 'Salma')->firstOrFail();
        $this->assertNotNull($contact->party_id);
        $this->assertNotSame($company->fresh()->party_id, $contact->party_id);
        $this->assertTrue(DB::table('party_relationships')->where('party_id', $contact->party_id)->where('related_party_id', $company->fresh()->party_id)->exists());
        $this->assertSame('Shop SARL', $company->fresh()->display_name, 'the company identity is untouched by its interlocutor');
    }

    // ------------------------------------------------------------ authorization

    public function test_a_normal_contact_creator_cannot_create_financial_profiles_or_admin_roles_and_terms(): void
    {
        $creator = $this->member(['contacts.view', 'contacts.create', 'contacts.update']);
        $party = app(SavePartyAction::class)->execute($creator, $this->org, ['kind' => 'person', 'display_name' => 'Plain']);

        foreach ([
            fn () => app(ManagePartyRolesAction::class)->add($creator, $this->org, $party, [ContactSystemRole::Client]),
            fn () => app(ManagePartyRolesAction::class)->add($creator, $this->org, $party, [ContactSystemRole::Supplier]),
            fn () => app(ActivateCommercialProfileAction::class)->customer($creator, $this->org, $party),
            fn () => app(ManagePartyRolesAction::class)->add($creator, $this->org, $party, ['Brand New Role']),
            fn () => app(ManageContactRolesCatalogueAction::class)->create($creator, $this->org, 'Another'),
            fn () => app(ManagePaymentTermsAction::class)->create($creator, $this->org, 15),
            fn () => app(ArchivePartyAction::class)->archive($creator, $this->org, $party),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt {$i} must be forbidden");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(0, Customer::query()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, ContactRole::query()->where('organization_id', $this->org->id)->count());
        // With the matching permission each step succeeds, and a plain label role needs only contacts.update.
        $this->assertCount(1, app(ManagePartyRolesAction::class)->add($creator, $this->org, $party, [$this->role(ContactSystemRole::Partner)->id]));
    }

    public function test_commercial_activation_needs_its_own_permission_and_terms_need_finance_rights(): void
    {
        $sales = $this->member(['contacts.update', 'customers.create']);
        $party = $this->party('Needs perms');

        app(ActivateCommercialProfileAction::class)->customer($sales, $this->org, $party);
        $this->assertSame(1, Customer::query()->where('party_id', $party->id)->count());
        $this->expectException(HttpException::class);
        app(ActivateCommercialProfileAction::class)->supplier($sales, $this->org, $party);
    }

    public function test_actions_refuse_a_party_of_another_organization(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->createOrganization($otherOwner);
        $this->activate($otherOwner, $other, $this->createStore($other, $otherOwner));
        $foreign = app(SavePartyAction::class)->execute($otherOwner, $other, ['kind' => 'person', 'display_name' => 'Foreign']);

        foreach ([
            fn () => app(SavePartyAction::class)->execute($this->owner, $this->org, ['kind' => 'person', 'display_name' => 'x'], $foreign),
            fn () => app(ManagePartyRolesAction::class)->add($this->owner, $this->org, $foreign, [ContactSystemRole::Client]),
            fn () => app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $foreign),
            fn () => app(ArchivePartyAction::class)->archive($this->owner, $this->org, $foreign),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a foreign party must be refused');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $this->assertSame('Foreign', $foreign->fresh()->display_name);
        $this->assertSame(0, Customer::query()->where('organization_id', $other->id)->count());
    }

    // ------------------------------------------------------------ protected lifecycle

    public function test_archiving_and_commercial_role_removal_are_blocked_while_money_is_open(): void
    {
        $customer = app(CustomerManager::class)->create($this->owner, $this->org, ['type' => 'company', 'display_name' => 'Debtor', 'status' => 'active']);
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $customer);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '500.0000']);
        app(ConfirmSalesOrderAction::class)->execute($this->owner, $order);
        $party = Party::query()->findOrFail($customer->fresh()->party_id);

        try {
            app(ArchivePartyAction::class)->archive($this->owner, $this->org, $party);
            $this->fail('archiving a debtor must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('customer_unpaid_orders', $e->errors()['party'][0]);
        }
        try {
            app(ManagePartyRolesAction::class)->remove($this->owner, $this->org, $party, $this->role(ContactSystemRole::Client));
            $this->fail('removing Client while money is open must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }
        $this->assertSame('active', $party->fresh()->status);
        $this->assertSame(['client'], $this->activeRoleKeys($party));
    }

    public function test_a_clean_party_can_be_archived_and_restored_without_touching_its_profile(): void
    {
        $party = $this->party('Clean');
        $customer = app(ActivateCommercialProfileAction::class)->customer($this->owner, $this->org, $party);

        app(ArchivePartyAction::class)->archive($this->owner, $this->org, $party);
        $this->assertSame('archived', $party->fresh()->status);
        $this->assertNotNull(Customer::query()->find($customer->id));

        try {
            app(SavePartyAction::class)->execute($this->owner, $this->org, ['kind' => 'person', 'display_name' => 'Edit archived'], $party->fresh());
            $this->fail('an archived party is read-only until restored');
        } catch (ValidationException) {
            $this->assertSame('Clean', $party->fresh()->display_name);
        }
        app(ArchivePartyAction::class)->restore($this->owner, $this->org, $party);
        $this->assertSame('active', $party->fresh()->status);
        $this->assertSame(['party.archived', 'party.restored'], DB::table('audit_logs')->whereIn('event', ['party.archived', 'party.restored'])->orderBy('id')->pluck('event')->all());
    }

    // ------------------------------------------------------------ data exposure

    public function test_the_identity_serializer_never_exposes_banking_data_and_gates_fiscal_identity(): void
    {
        $supplier = $this->supplierLegacy('Bank Co', ['ice' => '001111111000044', 'bank_iban' => 'MA64011519000001205000534921']);
        $party = Party::query()->findOrFail($supplier->fresh()->party_id);

        $owner = app(PartyIdentitySerializer::class)->toArray($party, $this->owner, $this->org);
        $limited = app(PartyIdentitySerializer::class)->toArray($party->fresh(), $this->member(['customers.view']), $this->org);

        $this->assertStringNotContainsStringIgnoringCase('iban', json_encode($owner));
        $this->assertStringNotContainsString('MA6401', json_encode($owner));
        $this->assertSame('001111111000044', $owner['ice']);
        $this->assertArrayNotHasKey('ice', $limited);
        $this->assertNull($limited['supplier_id'], 'a supplier id is not disclosed without suppliers.view');
    }

    // ------------------------------------------------------------ rollback safety

    public function test_rollback_is_refused_when_it_would_destroy_natively_created_data(): void
    {
        $this->party('Native');
        $migration = require base_path('database/migrations/2026_12_02_000001_create_unified_contacts_party_tables.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to roll back');
        $migration->down();
    }

    // ------------------------------------------------------------ helpers

    /** @return list<string> */
    private function activeRoleKeys(Party $party): array
    {
        return DB::table('party_roles as pr')->join('contact_roles as r', 'r.id', '=', 'pr.contact_role_id')->where('pr.party_id', $party->id)
            ->where('pr.status', 'active')->orderBy('r.system_key')->pluck('r.system_key')->all();
    }

    /** A supplier row exactly as it existed before the unified lifecycle (no party). */
    private function supplierLegacyRaw(string $name, string $ice): int
    {
        return (int) DB::table('suppliers')->insertGetId(['organization_id' => $this->org->id, 'name' => $name, 'ice' => $ice, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}
