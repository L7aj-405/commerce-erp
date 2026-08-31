<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DocumentTestCase;

class DocumentAuthorizationTest extends DocumentTestCase
{
    public function test_sales_employee_can_create_and_issue_normal_documents(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture(true);
        $sales = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $sales);
        $this->actingAs($sales)->post(route('sales.orders.invoices.store', $order))->assertRedirect();
        $invoice = $order->invoices()->firstOrFail();
        $this->actingAs($sales)->post(route('invoices.issue', $invoice))->assertRedirect();
        $this->actingAs($sales)->post(route('sales.orders.delivery-notes.store', $order))->assertRedirect();
        $note = $order->deliveryNotes()->firstOrFail();
        $this->actingAs($sales)->post(route('delivery-notes.issue', $note))->assertRedirect();
        $this->assertSame('issued', $invoice->fresh()->status->value);
        $this->assertSame('issued', $note->fresh()->status->value);
    }

    public function test_sales_employee_cannot_backdate_documents(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture(true);
        $sales = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $sales);
        $this->actingAs($sales)->post(route('sales.orders.invoices.store', $order), ['invoice_date' => '2026-01-01'])->assertForbidden();
        $this->actingAs($sales)->post(route('sales.orders.delivery-notes.store', $order), ['delivery_date' => '2026-01-01'])->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('delivery_notes', 0);
    }

    public function test_missing_document_permission_is_denied_before_creation(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture(true);
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['invoices.view', 'delivery_notes.view'], roleName: 'Document Viewer');
        $this->addStoreMember($store, $viewer);
        $this->activate($viewer, $organization, $store);
        $this->actingAs($viewer)->post(route('sales.orders.invoices.store', $order))->assertForbidden();
        $this->actingAs($viewer)->post(route('sales.orders.delivery-notes.store', $order))->assertForbidden();
    }

    public function test_permission_revocation_takes_effect_without_relogin(): void
    {
        [$owner, $organization, $store] = $this->documentFixture();
        $staff = User::factory()->create();
        $membership = $this->addOrganizationMember($organization, $staff, ['invoices.view'], roleName: 'Invoice Viewer');
        $this->addStoreMember($store, $staff);
        $this->activate($staff, $organization, $store);
        $this->actingAs($staff)->get(route('invoices.index'))->assertOk();
        $membership->role->permissions()->detach($this->permission('invoices.view'));
        $this->actingAs($staff)->get(route('invoices.index'))->assertForbidden();
    }

    public function test_view_permission_allows_rendering_but_not_email_delivery(): void
    {
        Mail::fake();
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['invoices.view'], roleName: 'Invoice Viewer');
        $this->addStoreMember($store, $viewer);
        $this->activate($viewer, $organization, $store);

        $this->actingAs($viewer)->get(route('invoices.print', $invoice))->assertOk();
        $this->actingAs($viewer)->post(route('invoices.email', $invoice), ['email' => 'viewer@example.test'])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_document_permissions_are_provisioned_for_future_organizations(): void
    {
        $organization = $this->createOrganization(User::factory()->create());
        $normal = ['invoices.view', 'invoices.create', 'invoices.update_draft', 'invoices.issue', 'invoices.email', 'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.update_draft', 'delivery_notes.issue', 'delivery_notes.email'];
        $sales = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $this->assertEqualsCanonicalizing($normal, $sales->permissions()->whereIn('key', $normal)->pluck('key')->all());
        $this->assertFalse($sales->permissions()->whereIn('key', ['invoices.backdate', 'delivery_notes.backdate'])->exists());
        $this->assertTrue($admin->permissions()->where('key', 'invoices.backdate')->exists());
        $this->assertTrue($admin->permissions()->where('key', 'delivery_notes.backdate')->exists());
    }
}
