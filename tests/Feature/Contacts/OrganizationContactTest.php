<?php

namespace Tests\Feature\Contacts;

use App\Models\OrganizationContact;
use App\Models\Supplier;
use App\Models\User;
use Tests\Support\SalesTestCase;

class OrganizationContactTest extends SalesTestCase
{
    public function test_contact_crud_is_organization_scoped_and_allows_duplicate_email_warning_not_uniqueness(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);
        $customer = $this->createCustomer($organization, 'ACME', ['email' => 'hello@example.test']);

        $this->actingAs($owner)->post(route('contacts.store'), [
            'full_name' => 'Amina Client',
            'company_name' => 'ACME SARL',
            'job_title' => 'Achats',
            'email' => 'hello@example.test',
            'phone' => '0600000000',
            'whatsapp' => '0600000000',
            'contact_type' => 'client',
            'customer_id' => $customer->id,
            'notes' => 'Contact principal',
            'active' => true,
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('contacts.store'), [
            'full_name' => 'Amina Finance',
            'email' => 'hello@example.test',
            'contact_type' => 'client',
            'active' => true,
        ])->assertRedirect();

        $this->assertSame(2, OrganizationContact::query()->where('organization_id', $organization->id)->where('email', 'hello@example.test')->count());
        $this->actingAs($owner)->get(route('contacts.index', ['search' => 'Amina']))->assertOk();
    }

    public function test_contact_rbac_and_foreign_link_validation_are_enforced(): void
    {
        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $storeA = $this->createStore($organizationA, $ownerA);
        $this->activate($ownerA, $organizationA, $storeA);

        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $foreignCustomer = $this->createCustomer($organizationB, 'Other Customer');

        $this->actingAs($ownerA)->post(route('contacts.store'), [
            'full_name' => 'Invalid link',
            'email' => 'invalid@example.test',
            'contact_type' => 'client',
            'customer_id' => $foreignCustomer->id,
        ])->assertSessionHasErrors('customer_id');

        $clerk = User::factory()->create();
        $this->addOrganizationMember($organizationA, $clerk, ['contacts.view']);
        $this->activate($clerk, $organizationA, $storeA);

        $this->actingAs($clerk)->post(route('contacts.store'), [
            'full_name' => 'Denied',
            'email' => 'denied@example.test',
            'contact_type' => 'other',
        ])->assertForbidden();
    }

    public function test_archive_keeps_contact_in_directory_but_marks_it_inactive(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = 'Supplier';
        $supplier->email = 'supplier@example.test';
        $supplier->active = true;
        $supplier->save();

        $contact = new OrganizationContact;
        $contact->organization_id = $organization->id;
        $contact->supplier_id = $supplier->id;
        $contact->full_name = 'Supplier Contact';
        $contact->email = 'supplier@example.test';
        $contact->contact_type = 'supplier';
        $contact->active = true;
        $contact->save();

        $this->actingAs($owner)->delete(route('contacts.destroy', $contact))->assertRedirect(route('contacts.index'));

        $contact->refresh();
        $this->assertFalse($contact->active);
        $this->assertNotNull($contact->archived_at);
    }
}
