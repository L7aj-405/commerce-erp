<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use Tests\Support\DocumentTestCase;

class DocumentProfileTest extends DocumentTestCase
{
    public function test_profile_changes_apply_only_to_future_document_snapshots(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $organization->settings = ['unrelated' => ['preserved' => true], 'document_profile' => ['legal_name' => 'Old Seller']];
        $organization->save();
        $oldInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->put(route('document-profile.update'), [
            'legal_name' => 'New Seller', 'trade_name' => 'New Trade', 'tax_identifier' => 'ICE-NEW',
            'additional_identifiers' => [['label' => 'IF', 'value' => '12345']],
        ])->assertRedirect();

        $newOrder = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $newOrder);
        $newOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $newOrder);
        $newInvoice = $this->createInvoice($owner, $newOrder);

        $this->assertSame('Old Seller', $oldInvoice->fresh()->seller_snapshot['legal_name']);
        $this->assertSame('New Seller', $newInvoice->seller_snapshot['legal_name']);
        $this->assertTrue($organization->fresh()->settings['unrelated']['preserved']);
    }

    public function test_user_without_settings_permission_cannot_edit_or_forge_profile(): void
    {
        [$owner, $organization, $store] = $this->documentFixture();
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['organizations.view'], roleName: 'Viewer');
        $this->addStoreMember($store, $viewer);
        $this->activate($viewer, $organization, $store);

        $this->actingAs($viewer)->get(route('document-profile.edit'))->assertForbidden();
        $this->actingAs($viewer)->put(route('document-profile.update'), ['legal_name' => 'Forged Seller'])->assertForbidden();
        $this->assertNotSame('Forged Seller', data_get($organization->fresh()->settings, 'document_profile.legal_name'));
    }

    public function test_document_profile_website_accepts_a_bare_domain_and_stores_a_canonical_url(): void
    {
        [$owner, $organization] = $this->documentFixture();

        $this->actingAs($owner)->put(route('document-profile.update'), [
            'legal_name' => 'AV Professional',
            'website' => 'avprofessional-store.ma',
            'additional_identifiers' => [],
        ])->assertRedirect();

        $this->assertSame('https://avprofessional-store.ma', data_get($organization->fresh()->settings, 'document_profile.website'));
    }
}
