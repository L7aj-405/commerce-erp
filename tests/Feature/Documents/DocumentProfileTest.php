<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Contracts\PdfGenerator;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\InvoiceDocumentRenderer;
use Illuminate\Support\Facades\Storage;
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

    public function test_pdf_studio_stores_header_and_pagination_options_for_future_snapshots(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => [
                'document_header' => ['visible' => false],
                'pagination' => ['visible' => false],
            ],
        ])->assertRedirect();

        $profile = data_get($organization->fresh()->settings, 'document_profile');
        $this->assertFalse($profile['show_document_header']);
        $this->assertFalse($profile['show_pdf_pagination']);

        $invoice = $this->createInvoice($owner, $order);
        $this->assertFalse($invoice->seller_snapshot['show_document_header']);
        $this->assertFalse($invoice->seller_snapshot['show_pdf_pagination']);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $this->assertStringNotContainsString('class="runhead"', $html);
        $this->assertStringContainsString('Facture N°', $html, 'Mandatory document identification must remain in the body meta table.');
    }

    public function test_explicit_string_false_settings_are_not_cast_back_to_true_in_pdf_html(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => [
                'legal_name' => '10xScale ERP',
                'show_document_header' => 'false',
                'show_pdf_pagination' => 'false',
            ],
        ]);
        $organization->save();

        $invoice = $this->createInvoice($owner, $order);

        $this->assertFalse($invoice->seller_snapshot['show_document_header']);
        $this->assertFalse($invoice->seller_snapshot['show_pdf_pagination']);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $this->assertStringNotContainsString('class="runhead"', $html);
        $this->assertStringContainsString('Facture N°', $html);
    }

    public function test_pdf_pagination_option_is_passed_to_the_pdf_generator(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => ['legal_name' => '10xScale ERP', 'show_pdf_pagination' => false],
        ]);
        $organization->save();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $fake = new class implements PdfGenerator {
            /** @var array<string, mixed> */
            public array $options = [];

            public function generate(string $html, array $options = []): string
            {
                $this->options = $options;

                return '%PDF-disabled';
            }
        };
        $this->app->instance(PdfGenerator::class, $fake);

        app(DocumentPdfService::class)->invoice($invoice);

        $this->assertFalse($fake->options['pageNumbers']);

        [$owner2, $organization2, , $order2] = $this->documentFixture();
        $organization2->settings = array_replace_recursive($organization2->settings ?? [], [
            'document_profile' => ['legal_name' => '10xScale ERP', 'show_pdf_pagination' => true],
        ]);
        $organization2->save();
        $invoice2 = $this->issueInvoice($owner2, $this->createInvoice($owner2, $order2));

        app(DocumentPdfService::class)->invoice($invoice2);

        $this->assertTrue($fake->options['pageNumbers']);
    }

    public function test_current_presentation_settings_apply_to_existing_document_snapshots(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => [
                'legal_name' => 'Snapshot Seller',
                'show_document_header' => true,
                'show_pdf_pagination' => true,
            ],
        ]);
        $organization->save();

        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->assertTrue($invoice->seller_snapshot['show_document_header']);
        $this->assertTrue($invoice->seller_snapshot['show_pdf_pagination']);

        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => [
                'show_document_header' => false,
                'show_pdf_pagination' => false,
            ],
        ]);
        $organization->save();

        $html = app(InvoiceDocumentRenderer::class)->html($invoice->fresh());
        $this->assertStringNotContainsString('class="runhead"', $html);

        $fake = new class implements PdfGenerator {
            /** @var array<string, mixed> */
            public array $options = [];

            public function generate(string $html, array $options = []): string
            {
                $this->options = $options;

                return '%PDF-disabled-existing';
            }
        };
        $this->app->instance(PdfGenerator::class, $fake);

        app(DocumentPdfService::class)->invoice($invoice->fresh());

        $this->assertFalse($fake->options['pageNumbers']);
    }

    public function test_item_line_style_settings_are_persisted_and_rendered_as_effective_pdf_colors(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => [
                'item_table' => [
                    'separator_color' => '#000000',
                    'separator_opacity' => 50,
                    'separator_thickness' => 'thick',
                ],
            ],
        ])->assertRedirect();

        $profile = data_get($organization->fresh()->settings, 'document_profile');
        $this->assertSame('#000000', $profile['item_line_color']);
        $this->assertSame(50, $profile['item_line_opacity']);
        $this->assertSame('thick', $profile['item_line_thickness']);

        $invoice = $this->createInvoice($owner, $order);
        $this->assertSame('#808080', $invoice->seller_snapshot['item_line_css_color']);
        $this->assertSame('.95pt', $invoice->seller_snapshot['item_line_css_width']);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $this->assertStringContainsString('border-bottom: .95pt solid #808080', $html);
        $this->assertStringNotContainsString('rgba(', $html);
    }

    public function test_email_is_available_in_company_block_and_website_remains_in_footer(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => [
                'legal_name' => '10xScale ERP',
                'email' => 'contact@example.test',
                'website' => 'https://example.test/',
            ],
        ]);
        $organization->save();

        $html = app(InvoiceDocumentRenderer::class)->html($this->createInvoice($owner, $order));

        $this->assertStringContainsString('<td class="info-label">Email :</td>', $html);
        $this->assertStringContainsString('<td class="info-value">contact@example.test</td>', $html);
        $this->assertStringNotContainsString('<td class="info-label">Web :</td>', $html);
        $this->assertStringContainsString('Email : contact@example.test', $html);
        $this->assertStringContainsString('Web : example.test', $html);
    }

    public function test_studio_persists_independent_company_recipient_title_and_totals_styles(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => [
                'company_name' => ['visible' => false, 'color' => '#112233'],
                'company_block' => [
                    'show_email' => false, 'background_color' => '#f1f2f3',
                    'row_spacing_mm' => 1.2, 'label_gap_mm' => 3,
                    'labels' => ['font_size' => 8, 'color' => '#223344', 'width_percent' => 24],
                    'values' => ['font_size' => 11, 'color' => '#334455'],
                ],
                'recipient_block' => [
                    'name' => ['font_size' => 13, 'color' => '#445566'],
                    'row_spacing_mm' => 0.8, 'label_gap_mm' => 2,
                    'labels' => ['font_size' => 12, 'width_percent' => 31],
                    'show_phone' => false,
                ],
                'document_title' => ['font_size' => 30, 'alignment' => 'left', 'color' => '#778899'],
                'totals' => ['width_percent' => 52, 'background_color' => '#fafafa'],
            ],
        ])->assertRedirect();

        $published = data_get($organization->fresh()->settings, 'document_profile.pdf_template.published');
        $this->assertFalse(data_get($published, 'company_name.visible'));
        $this->assertSame(8.0, data_get($published, 'company_block.labels.font_size'));
        $this->assertSame(11.0, data_get($published, 'company_block.values.font_size'));
        $this->assertSame(12.0, data_get($published, 'recipient_block.labels.font_size'));
        $this->assertSame(13.0, data_get($published, 'recipient_block.name.font_size'));
        $this->assertSame(24, data_get($published, 'company_block.labels.width_percent'));
        $this->assertSame(31, data_get($published, 'recipient_block.labels.width_percent'));
        $this->assertSame(3.0, data_get($published, 'company_block.label_gap_mm'));
        $this->assertSame(2.0, data_get($published, 'recipient_block.label_gap_mm'));
        $this->assertSame(17, data_get($published, 'company_name.font_size'), 'Changing company labels must not change the company name.');
        $this->assertSame(30.0, data_get($published, 'document_title.font_size'));
        $this->assertSame(52, data_get($published, 'totals.width_percent'));

        $html = app(InvoiceDocumentRenderer::class)->html($this->createInvoice($owner, $order));
        $this->assertStringContainsString('.company-name { display: none;', $html);
        $this->assertStringContainsString('font-size: 13px', $html);
        $this->assertStringContainsString('font-size: 30px', $html);
        $this->assertStringContainsString('width: 52%', $html);
        $this->assertStringContainsString('.recipient-phone { display: none; }', $html);
        $this->assertStringContainsString('class="info-kv company-info-table"', $html);
        $this->assertStringContainsString('class="info-kv recipient-info-table"', $html);
        $this->assertStringContainsString('.info-kv tr, .info-kv td { border: 0 !important;', $html);
        $this->assertStringContainsString('.company-info-table .info-label { width: 24%; padding-right: 3mm', $html);
        $this->assertStringContainsString('.recipient-info-table .info-label { width: 31%; padding-right: 2mm', $html);
    }

    public function test_document_pdf_settings_are_organization_isolated(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => [
                'legal_name' => 'Hidden Header Org',
                'show_document_header' => false,
                'item_line_color' => '#000000',
                'item_line_opacity' => 50,
                'item_line_thickness' => 'thick',
            ],
        ]);
        $organization->save();

        [$otherOwner, $otherOrganization, , $otherOrder] = $this->documentFixture();
        $otherOrganization->settings = array_replace_recursive($otherOrganization->settings ?? [], [
            'document_profile' => [
                'legal_name' => 'Visible Header Org',
                'show_document_header' => true,
                'item_line_color' => '#ff0000',
                'item_line_opacity' => 100,
                'item_line_thickness' => 'fine',
            ],
        ]);
        $otherOrganization->save();

        $hiddenHtml = app(InvoiceDocumentRenderer::class)->html($this->createInvoice($owner, $order));
        $visibleHtml = app(InvoiceDocumentRenderer::class)->html($this->createInvoice($otherOwner, $otherOrder));

        $this->assertStringNotContainsString('class="runhead"', $hiddenHtml);
        $this->assertStringContainsString('class="runhead"', $visibleHtml);
        $this->assertStringContainsString('border-bottom: .95pt solid #808080', $hiddenHtml);
        $this->assertStringContainsString('border-bottom: .45pt solid #ff0000', $visibleHtml);
    }

    public function test_document_template_studio_draft_does_not_affect_pdf_until_published(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $template = [
            'brand' => ['accent_color' => '#123456'],
            'document_header' => ['visible' => false],
            'pagination' => ['visible' => false],
            'watermark' => ['visible' => false],
            'item_table' => [
                'separator_color' => '#000000',
                'separator_opacity' => 50,
                'separator_thickness' => 'thick',
            ],
        ];

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'draft',
            'template' => $template,
        ])->assertRedirect();

        $profile = data_get($organization->fresh()->settings, 'document_profile');
        $this->assertSame('#123456', data_get($profile, 'pdf_template.draft.brand.accent_color'));
        $this->assertNull(data_get($profile, 'pdf_template.published'));

        $draftOnlyHtml = app(InvoiceDocumentRenderer::class)->html($this->createInvoice($owner, $order));
        $this->assertStringContainsString('class="runhead"', $draftOnlyHtml);
        $this->assertStringNotContainsString('border-bottom: .95pt solid #808080', $draftOnlyHtml);

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => $template,
        ])->assertRedirect();

        $publishedProfile = data_get($organization->fresh()->settings, 'document_profile');
        $this->assertSame('#123456', data_get($publishedProfile, 'pdf_template.published.brand.accent_color'));
        $this->assertFalse($publishedProfile['show_document_header']);
        $this->assertFalse($publishedProfile['show_pdf_pagination']);
        $this->assertSame('#000000', $publishedProfile['item_line_color']);
        $this->assertSame(50, $publishedProfile['item_line_opacity']);
        $this->assertSame('thick', $publishedProfile['item_line_thickness']);

        $publishedHtml = app(InvoiceDocumentRenderer::class)->html($this->createInvoice($owner, $order));
        $this->assertStringNotContainsString('class="runhead"', $publishedHtml);
        $this->assertStringContainsString('border-bottom: .95pt solid #808080', $publishedHtml);
    }

    public function test_document_template_studio_sanitizes_visual_schema_and_rejects_raw_css(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => [
                'page' => [
                    'base_font_size' => 99,
                    'text_color' => 'expression(alert(1))',
                ],
                'item_table' => [
                    'separator_color' => '#000000',
                    'separator_opacity' => 50,
                    'separator_thickness' => 'thick',
                    'unsafe_css' => 'body{display:none}',
                ],
                'amount_words' => ['visible' => false],
            ],
        ])->assertRedirect();

        $stored = data_get($organization->fresh()->settings, 'document_profile.pdf_template.published');
        $this->assertSame(12.0, data_get($stored, 'page.base_font_size'));
        $this->assertSame('#1f211d', data_get($stored, 'page.text_color'));
        $this->assertNull(data_get($stored, 'item_table.unsafe_css'));
        $this->assertFalse(data_get($stored, 'amount_words.visible'));

        $html = app(InvoiceDocumentRenderer::class)->html($this->createInvoice($owner, $order));
        $this->assertStringContainsString('body { color: #1f211d;', $html);
        $this->assertStringContainsString('font-size: 12px;', $html);
        $this->assertStringNotContainsString('body{display:none}', $html);
        $this->assertStringContainsString('.words-box { display: none; }', $html);
    }

    public function test_document_template_studio_is_permission_gated_and_organization_isolated(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['organizations.view'], roleName: 'Viewer');
        $this->addStoreMember($store, $viewer);
        $this->activate($viewer, $organization, $store);

        $this->actingAs($viewer)->get(route('document-profile.studio.edit'))->assertForbidden();
        $this->actingAs($viewer)->post(route('document-profile.studio.preview'), [
            'template' => ['document_header' => ['visible' => false]],
        ])->assertForbidden();
        $this->actingAs($viewer)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => ['document_header' => ['visible' => false]],
        ])->assertForbidden();

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => [
                'document_header' => ['visible' => false],
                'item_table' => ['separator_color' => '#000000', 'separator_opacity' => 50, 'separator_thickness' => 'thick'],
            ],
        ])->assertRedirect();

        [$otherOwner, , , $otherOrder] = $this->documentFixture();

        $this->assertStringNotContainsString('class="runhead"', app(InvoiceDocumentRenderer::class)->html($this->createInvoice($owner, $order)));
        $this->assertStringContainsString('class="runhead"', app(InvoiceDocumentRenderer::class)->html($this->createInvoice($otherOwner, $otherOrder)));
    }

    public function test_studio_preview_uses_real_embedded_logo_and_unsaved_draft_style(): void
    {
        [$owner, $organization] = $this->documentFixture();
        Storage::fake('public');
        Storage::disk('public')->put('document-profiles/logo.png', 'organization-logo-bytes');
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => ['legal_name' => 'Logo Seller', 'logo_path' => 'document-profiles/logo.png'],
        ]);
        $organization->save();

        $fake = new class implements PdfGenerator {
            public string $html = '';
            public array $options = [];

            public function generate(string $html, array $options = []): string
            {
                $this->html = $html;
                $this->options = $options;

                return '%PDF-studio-preview';
            }
        };
        $this->app->instance(PdfGenerator::class, $fake);

        $this->actingAs($owner)->post(route('document-profile.studio.preview'), [
            'template' => [
                'logo' => ['visible' => true, 'max_width_mm' => 62, 'alignment' => 'center'],
                'document_header' => ['visible' => false],
                'pagination' => ['visible' => false],
            ],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('data:image/png;base64,'.base64_encode('organization-logo-bytes'), $fake->html);
        $this->assertStringContainsString('max-width: 62mm', $fake->html);
        $this->assertStringContainsString('text-align: center', $fake->html);
        $this->assertStringNotContainsString('class="runhead"', $fake->html);
        $this->assertFalse($fake->options['pageNumbers']);
        $this->assertNull(data_get($organization->fresh()->settings, 'document_profile.pdf_template.draft'));
    }

    public function test_studio_persists_allowlisted_stamp_and_pagination_presentation(): void
    {
        [$owner, $organization] = $this->documentFixture();

        $this->actingAs($owner)->put(route('document-profile.studio.update'), [
            'mode' => 'publish',
            'template' => [
                'pagination' => ['visible' => false, 'position' => 'top', 'alignment' => 'center', 'font_size' => 9, 'color' => '#123456'],
                'stamp' => [
                    'visible' => true, 'position_anchor' => 'bottom_right', 'offset_x_mm' => 12,
                    'offset_y_mm' => 18, 'display_width_mm' => 48, 'display_height_mm' => 30,
                    'rotation_deg' => 7, 'opacity' => 72, 'preserve_aspect_ratio' => false,
                ],
            ],
        ])->assertRedirect();

        $published = data_get($organization->fresh()->settings, 'document_profile.pdf_template.published');
        $this->assertFalse(data_get($published, 'pagination.visible'));
        $this->assertSame('top', data_get($published, 'pagination.position'));
        $this->assertSame('bottom_right', data_get($published, 'stamp.position_anchor'));
        $this->assertSame(7.0, data_get($published, 'stamp.rotation_deg'));
        $this->assertSame(72, data_get($published, 'stamp.opacity'));
        $this->assertFalse(data_get($published, 'stamp.preserve_aspect_ratio'));
    }
}
