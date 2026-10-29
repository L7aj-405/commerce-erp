<?php

namespace Tests\Feature\Documents;

use App\Services\DeliveryNoteDocumentRenderer;
use App\Services\DocumentTemplateRegistry;
use Dompdf\Dompdf;
use Dompdf\Options;
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

    public function test_issued_delivery_note_keeps_its_issuance_time_presentation(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(true);
        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order->fresh()));
        $frozenHtml = app(DeliveryNoteDocumentRenderer::class)->html($note);

        $settings = $organization->settings ?? [];
        data_set($settings, 'document_profile.pdf_template.published.document_header.visible', false);
        $organization->settings = $settings;
        $organization->save();

        $this->assertSame($frozenHtml, app(DeliveryNoteDocumentRenderer::class)->html($note->fresh()));
    }
    public function test_designation_is_the_dominant_column_and_long_names_and_references_stay_inside_the_page(): void
    {
        [$owner, , , $order] = $this->documentFixture(true);
        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order->fresh()));
        $renderer = app(DeliveryNoteDocumentRenderer::class);
        $payload = (fn () => $this->payload($note->fresh()))->call($renderer);
        $payload['lines'] = [
            ['description' => 'Câble XLR', 'variant' => null, 'sku' => 'COR-XLR', 'reference' => null, 'unit' => 'pc', 'quantity' => '2'],
            ['description' => 'Micro Shure MV7X - Micro Dynamique XLR/USB pour Podcast et Voix, réduction de bruit, livré avec câble et bonnette', 'variant' => 'Noir', 'sku' => 'SHU-MV7X-BLK-2026', 'reference' => null, 'unit' => 'pc', 'quantity' => '1'],
            ['description' => 'Table de mixage', 'variant' => null, 'sku' => 'MACKIE-PROFX10V3-USB-EFFECTS-ANALOG-MIXER-REF-000123', 'reference' => null, 'unit' => 'pc', 'quantity' => '12'],
            ['description' => 'Pied de micro', 'variant' => null, 'sku' => null, 'reference' => null, 'unit' => null, 'quantity' => '1'],
        ];
        $html = view(app(DocumentTemplateRegistry::class)->deliveryNoteView($payload['template_version']), $payload)->render();

        // Measure the laid-out header cells in the real Dompdf render (the shared commercial
        // style sizes columns by position for invoices and used to squeeze Désignation).
        $headers = [];
        $tableWidth = null;
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->setCallbacks([['event' => 'end_frame', 'f' => function ($frame) use (&$headers, &$tableWidth) {
            $node = $frame->get_node();
            if ($node->nodeName === 'table' && str_contains((string) $node->getAttribute('class'), 'delivery-items')) $tableWidth = $frame->get_border_box()[2];
            if ($node->nodeName === 'th' && str_contains((string) $node->parentNode?->parentNode?->parentNode?->getAttribute('class'), 'delivery-items')) {
                $headers[] = $frame->get_border_box();
            }
        }]]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $this->assertCount(4, $headers);
        $share = array_map(fn (array $box) => round(100 * $box[2] / $tableWidth), $headers);
        $this->assertEqualsWithDelta([56, 23, 11, 10], $share, 1.0, 'Désignation / Référence / Unité / Qté widths');
        [$lastX, , $lastWidth] = $headers[3];
        $this->assertLessThanOrEqual(595.28 - 36.85 + 0.5, $lastX + $lastWidth, 'The table stays inside the A4 right margin.');
        $this->assertStringContainsString('Micro Shure MV7X - Micro Dynamique XLR/USB pour Podcast et Voix, réduction de bruit, livré avec câble et bonnette', $html, 'Full name, never truncated.');
        $this->assertStringContainsString('<td class="col-unit">—</td>', $html, 'A missing unit keeps its dash.');
    }
}
