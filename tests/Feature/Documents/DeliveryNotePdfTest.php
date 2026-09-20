<?php

namespace Tests\Feature\Documents;

use App\Services\DeliveryNoteDocumentRenderer;
use Tests\Support\DocumentTestCase;

/**
 * Delivery Note PDF presentation: Document Profile branding (the same
 * source the Invoice PDF uses), graceful rendering when optional profile
 * fields are missing, and the business rule that a Bon de livraison is a
 * logistics document — never a financial one. Cross-tenant PDF access is
 * already covered by Security\TenantRedTeam\DocumentTenantAttackTest and is
 * untouched by this template polish.
 */
class DeliveryNotePdfTest extends DocumentTestCase
{
    public function test_document_profile_identity_appears_in_the_delivery_note_pdf_when_configured(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(true);
        $organization->settings = ['document_profile' => [
            'legal_name' => 'Atelier Test SARL',
            'address' => '12 Rue des Fleurs, Casablanca',
            'phone' => '0522000000',
            'tax_identifier' => 'ICE-999',
            'registration_number' => 'RC-42',
        ]];
        $organization->save();

        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order->fresh()));
        $html = app(DeliveryNoteDocumentRenderer::class)->html($note);

        $this->assertStringContainsString('Atelier Test SARL', $html);
        $this->assertStringContainsString('12 Rue des Fleurs, Casablanca', $html);
        $this->assertStringContainsString('ICE : ICE-999', $html);
        $this->assertStringContainsString('RC N° : RC-42', $html);
        $this->assertStringContainsString('Bon de livraison', $html);
    }

    public function test_missing_optional_profile_fields_render_cleanly_without_blank_labels_or_broken_logo(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(true);
        // Deliberately: no document_profile configured at all.

        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order->fresh()));
        $html = app(DeliveryNoteDocumentRenderer::class)->html($note);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('ICE :', $html);
        $this->assertStringNotContainsString('RC N° :', $html);
        $this->assertStringNotContainsString('TP :', $html);
        // Falls back to the organization's own name (DocumentSellerProfile's
        // own guarantee — never a blank company header).
        $this->assertStringContainsString($organization->name, $html);
    }

    public function test_delivery_note_pdf_never_shows_prices_or_tax_amounts(): void
    {
        [$owner, , , $order] = $this->documentFixture(fulfilled: true, total: '999.9900');
        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order->fresh()));
        $html = app(DeliveryNoteDocumentRenderer::class)->html($note);

        $this->assertStringNotContainsString('999,99', $html);
        $this->assertStringNotContainsString('999.99', $html);
        $this->assertStringNotContainsString('Total HT', $html);
        $this->assertStringNotContainsString('TVA', $html);
        $this->assertStringNotContainsString('Total TTC', $html);
        // The article table only ever carries designation / reference / unit
        // / quantity — the current business rule for this document.
        $this->assertStringContainsString('Désignation', $html);
        $this->assertStringContainsString('Qté', $html);
    }
}
