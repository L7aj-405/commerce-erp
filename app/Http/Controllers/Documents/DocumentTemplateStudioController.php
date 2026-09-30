<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentTemplatePreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTemplateStudioController extends Controller
{
    public function edit(Request $request, ActiveTenantContext $context, DocumentSellerProfile $sellerProfile): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);

        $profile = data_get($organization->settings, 'document_profile', []);
        $defaults = $sellerProfile->defaultPdfTemplate();
        $draft = data_get($profile, 'pdf_template.draft');
        $activeStamp = $organization->activeDocumentStamp()->first();
        $legacyStamp = $activeStamp ? [
            'stamp' => [
                'position_anchor' => $activeStamp->position_anchor,
                'offset_x_mm' => (float) $activeStamp->offset_x_mm,
                'offset_y_mm' => (float) $activeStamp->offset_y_mm,
                'display_width_mm' => (float) $activeStamp->display_width_mm,
                'rotation_deg' => (float) $activeStamp->rotation_deg,
            ],
        ] : [];
        $effective = $sellerProfile->effectivePdfTemplate($profile);
        if (! is_array(data_get($profile, 'pdf_template.published.stamp'))) {
            $effective = array_replace_recursive($effective, $legacyStamp);
        }
        $identity = $sellerProfile->organizationIdentity($organization);

        return Inertia::render('Documents/TemplateStudio', [
            'organization' => $organization->only(['id', 'name']),
            'profile' => [
                'legal_name' => $profile['legal_name'] ?? $organization->name,
                'trade_name' => $profile['trade_name'] ?? null,
                'address' => $profile['address'] ?? null,
                'phone' => $profile['phone'] ?? null,
                'email' => $profile['email'] ?? null,
                'tax_identifier' => $profile['tax_identifier'] ?? null,
                'registration_number' => $profile['registration_number'] ?? null,
                'patente_number' => $profile['patente_number'] ?? null,
                'website' => $profile['website'] ?? null,
                'footer_text' => $profile['footer_text'] ?? null,
            ],
            // The same embedded asset used by official PDFs. This avoids a
            // public-storage URL/symlink dependency in the editor preview.
            'logoUrl' => $identity['logo'] ?? null,
            'stampUrl' => $activeStamp ? route('document-stamp.image') : null,
            'defaults' => $defaults,
            'published' => $effective,
            'draft' => is_array($draft) ? array_replace_recursive($effective, $draft) : null,
            'canUpdate' => $request->user()->hasPermission($organization, 'settings.update'),
        ]);
    }

    public function preview(
        Request $request,
        ActiveTenantContext $context,
        DocumentSellerProfile $sellerProfile,
        DocumentTemplatePreviewService $preview,
    ): HttpResponse {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);

        $request->validate(['template' => ['required', 'array']]);
        $template = $this->sanitizeTemplate($request->input('template', []), $sellerProfile->defaultPdfTemplate());

        return response($preview->render($organization, $template), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Apercu-Studio-PDF.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, ActiveTenantContext $context, AuditLogger $audit, DocumentSellerProfile $sellerProfile): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $data = $request->validate([
            'mode' => ['required', 'in:draft,publish'],
            'template' => ['required', 'array'],
        ]);

        $template = $this->sanitizeTemplate($request->input('template', []), $sellerProfile->defaultPdfTemplate());

        DB::transaction(function () use ($organization, $template, $data, $audit, $request, $context) {
            $settings = $organization->settings ?? [];
            $profile = $settings['document_profile'] ?? [];
            $profile['pdf_template']['draft'] = $template;

            if ($data['mode'] === 'publish') {
                $profile['pdf_template']['published'] = $template;

                // Keep transitional legacy keys synchronized for older render
                // paths. They are no longer editable outside Studio, so this
                // remains one-way compatibility rather than a second source.
                $profile['accent_color'] = data_get($template, 'brand.accent_color', DocumentSellerProfile::DEFAULT_ACCENT_COLOR);
                $profile['show_invoice_watermark'] = (bool) data_get($template, 'watermark.visible', false);
                $profile['show_document_header'] = (bool) data_get($template, 'document_header.visible', true);
                $profile['show_pdf_pagination'] = (bool) data_get($template, 'pagination.visible', true);
                $profile['item_line_color'] = data_get($template, 'item_table.separator_color', '#8c8c8c');
                $profile['item_line_opacity'] = (int) data_get($template, 'item_table.separator_opacity', 50);
                $profile['item_line_thickness'] = data_get($template, 'item_table.separator_thickness', 'fine');
            }

            $settings['document_profile'] = $profile;
            $organization->settings = $settings;
            $organization->save();

            $audit->record('document_template.'.$data['mode'], $request->user(), $organization, $context->store(), $organization, newValues: [
                'mode' => $data['mode'],
            ]);
        });

        return back()->with('success', $data['mode'] === 'publish'
            ? 'Modèle PDF publié. Les documents commerciaux utiliseront cette présentation.'
            : 'Brouillon du modèle PDF enregistré.');
    }

    public function reset(Request $request, ActiveTenantContext $context, AuditLogger $audit, DocumentSellerProfile $sellerProfile): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        DB::transaction(function () use ($organization, $audit, $request, $context, $sellerProfile) {
            $settings = $organization->settings ?? [];
            $profile = $settings['document_profile'] ?? [];
            $defaults = $sellerProfile->defaultPdfTemplate();
            $profile['pdf_template'] = ['draft' => $defaults, 'published' => $defaults];
            $profile['accent_color'] = data_get($defaults, 'brand.accent_color');
            $profile['show_invoice_watermark'] = data_get($defaults, 'watermark.visible');
            $profile['show_document_header'] = data_get($defaults, 'document_header.visible');
            $profile['show_pdf_pagination'] = data_get($defaults, 'pagination.visible');
            $profile['item_line_color'] = data_get($defaults, 'item_table.separator_color');
            $profile['item_line_opacity'] = data_get($defaults, 'item_table.separator_opacity');
            $profile['item_line_thickness'] = data_get($defaults, 'item_table.separator_thickness');

            $settings['document_profile'] = $profile;
            $organization->settings = $settings;
            $organization->save();

            $audit->record('document_template.reset', $request->user(), $organization, $context->store(), $organization);
        });

        return back()->with('success', 'Modèle PDF réinitialisé avec les valeurs par défaut.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function sanitizeTemplate(array $input, array $defaults): array
    {
        return [
            'page' => [
                'base_font_size' => $this->float($input, 'page.base_font_size', data_get($defaults, 'page.base_font_size'), 8, 12),
                'text_color' => $this->hex($input, 'page.text_color', data_get($defaults, 'page.text_color')),
                'line_height' => $this->float($input, 'page.line_height', data_get($defaults, 'page.line_height'), 1.1, 1.8),
                'margin_top_mm' => $this->float($input, 'page.margin_top_mm', data_get($defaults, 'page.margin_top_mm'), 10, 35),
                'margin_right_mm' => $this->float($input, 'page.margin_right_mm', data_get($defaults, 'page.margin_right_mm'), 8, 30),
                'margin_bottom_mm' => $this->float($input, 'page.margin_bottom_mm', data_get($defaults, 'page.margin_bottom_mm'), 15, 40),
                'margin_left_mm' => $this->float($input, 'page.margin_left_mm', data_get($defaults, 'page.margin_left_mm'), 8, 30),
            ],
            'brand' => [
                'accent_color' => $this->hex($input, 'brand.accent_color', data_get($defaults, 'brand.accent_color')),
            ],
            'logo' => [
                'visible' => $this->bool($input, 'logo.visible', data_get($defaults, 'logo.visible')),
                'max_width_mm' => $this->float($input, 'logo.max_width_mm', data_get($defaults, 'logo.max_width_mm'), 30, 100),
                'max_height_mm' => $this->float($input, 'logo.max_height_mm', data_get($defaults, 'logo.max_height_mm'), 12, 40),
                'margin_bottom_mm' => $this->float($input, 'logo.margin_bottom_mm', data_get($defaults, 'logo.margin_bottom_mm'), 0, 12),
                'alignment' => $this->option($input, 'logo.alignment', data_get($defaults, 'logo.alignment'), ['left', 'center', 'right']),
                'preserve_aspect_ratio' => $this->bool($input, 'logo.preserve_aspect_ratio', data_get($defaults, 'logo.preserve_aspect_ratio')),
            ],
            'company_name' => $this->typography($input, $defaults, 'company_name') + [
                'position' => $this->option($input, 'company_name.position', data_get($defaults, 'company_name.position'), ['above_logo', 'below_logo', 'beside_logo']),
                'logo_spacing_mm' => $this->float($input, 'company_name.logo_spacing_mm', data_get($defaults, 'company_name.logo_spacing_mm'), 0, 20),
                'width_percent' => $this->int($input, 'company_name.width_percent', data_get($defaults, 'company_name.width_percent'), 20, 100),
                'margin_left_mm' => $this->float($input, 'company_name.margin_left_mm', data_get($defaults, 'company_name.margin_left_mm'), 0, 30),
                'margin_right_mm' => $this->float($input, 'company_name.margin_right_mm', data_get($defaults, 'company_name.margin_right_mm'), 0, 30),
                'horizontal_offset_mm' => $this->float($input, 'company_name.horizontal_offset_mm', data_get($defaults, 'company_name.horizontal_offset_mm'), -40, 40),
                'text_transform' => $this->option($input, 'company_name.text_transform', data_get($defaults, 'company_name.text_transform'), ['none', 'uppercase']),
            ],
            'document_header' => [
                'visible' => $this->bool($input, 'document_header.visible', data_get($defaults, 'document_header.visible')),
                'font_size' => $this->float($input, 'document_header.font_size', data_get($defaults, 'document_header.font_size'), 7, 11),
                'text_color' => $this->hex($input, 'document_header.text_color', data_get($defaults, 'document_header.text_color')),
                'border_color' => $this->hex($input, 'document_header.border_color', data_get($defaults, 'document_header.border_color')),
                'border_width' => $this->borderWidth($input, 'document_header.border_width', data_get($defaults, 'document_header.border_width')),
                'height_mm' => $this->float($input, 'document_header.height_mm', data_get($defaults, 'document_header.height_mm'), 8, 20),
                'margin_bottom_mm' => $this->float($input, 'document_header.margin_bottom_mm', data_get($defaults, 'document_header.margin_bottom_mm'), 0, 10),
            ],
            'company_block' => [
                'visible' => $this->bool($input, 'company_block.visible', data_get($defaults, 'company_block.visible')),
                'font_size' => $this->float($input, 'company_block.font_size', data_get($defaults, 'company_block.font_size'), 7.5, 12),
                'line_height' => $this->float($input, 'company_block.line_height', data_get($defaults, 'company_block.line_height'), 1, 2),
                'label_color' => $this->hex($input, 'company_block.label_color', data_get($defaults, 'company_block.label_color')),
                'value_color' => $this->hex($input, 'company_block.value_color', data_get($defaults, 'company_block.value_color')),
                'label_weight' => $this->int($input, 'company_block.label_weight', data_get($defaults, 'company_block.label_weight'), 400, 800),
                'value_weight' => $this->int($input, 'company_block.value_weight', data_get($defaults, 'company_block.value_weight'), 400, 800),
                'alignment' => $this->option($input, 'company_block.alignment', data_get($defaults, 'company_block.alignment'), ['left', 'center', 'right']),
                'padding_mm' => $this->float($input, 'company_block.padding_mm', data_get($defaults, 'company_block.padding_mm'), 0, 12),
                'margin_top_mm' => $this->float($input, 'company_block.margin_top_mm', data_get($defaults, 'company_block.margin_top_mm'), 0, 20),
                'margin_bottom_mm' => $this->float($input, 'company_block.margin_bottom_mm', data_get($defaults, 'company_block.margin_bottom_mm'), 0, 20),
                'border_color' => $this->hex($input, 'company_block.border_color', data_get($defaults, 'company_block.border_color')),
                'border_width' => $this->borderWidth($input, 'company_block.border_width', data_get($defaults, 'company_block.border_width')),
                'background_color' => $this->hex($input, 'company_block.background_color', data_get($defaults, 'company_block.background_color')),
                'show_address' => $this->bool($input, 'company_block.show_address', data_get($defaults, 'company_block.show_address')),
                'show_tax_identifier' => $this->bool($input, 'company_block.show_tax_identifier', data_get($defaults, 'company_block.show_tax_identifier')),
                'show_phone' => $this->bool($input, 'company_block.show_phone', data_get($defaults, 'company_block.show_phone')),
                'show_email' => $this->bool($input, 'company_block.show_email', data_get($defaults, 'company_block.show_email')),
                'show_registration_number' => $this->bool($input, 'company_block.show_registration_number', data_get($defaults, 'company_block.show_registration_number')),
                'show_patente_number' => $this->bool($input, 'company_block.show_patente_number', data_get($defaults, 'company_block.show_patente_number')),
                'show_additional_identifiers' => $this->bool($input, 'company_block.show_additional_identifiers', data_get($defaults, 'company_block.show_additional_identifiers')),
                'width_percent' => $this->int($input, 'company_block.width_percent', data_get($defaults, 'company_block.width_percent'), 30, 100),
                'row_spacing_mm' => $this->float($input, 'company_block.row_spacing_mm', data_get($defaults, 'company_block.row_spacing_mm'), 0, 5),
                'label_gap_mm' => $this->float($input, 'company_block.label_gap_mm', data_get($defaults, 'company_block.label_gap_mm'), 0, 10),
                'labels' => $this->textGroup($input, $defaults, 'company_block.labels', true),
                'values' => $this->textGroup($input, $defaults, 'company_block.values', false) + ['wrap' => $this->bool($input, 'company_block.values.wrap', data_get($defaults, 'company_block.values.wrap'))],
            ],
            'recipient_block' => [
                'visible' => $this->bool($input, 'recipient_block.visible', data_get($defaults, 'recipient_block.visible')),
                'font_size' => $this->float($input, 'recipient_block.font_size', data_get($defaults, 'recipient_block.font_size'), 7.5, 12),
                'title_size' => $this->float($input, 'recipient_block.title_size', data_get($defaults, 'recipient_block.title_size'), 7, 11),
                'name_size' => $this->float($input, 'recipient_block.name_size', data_get($defaults, 'recipient_block.name_size'), 8, 14),
                'contact_size' => $this->float($input, 'recipient_block.contact_size', data_get($defaults, 'recipient_block.contact_size'), 7, 12),
                'title_weight' => $this->int($input, 'recipient_block.title_weight', data_get($defaults, 'recipient_block.title_weight'), 400, 800),
                'name_weight' => $this->int($input, 'recipient_block.name_weight', data_get($defaults, 'recipient_block.name_weight'), 400, 800),
                'label_weight' => $this->int($input, 'recipient_block.label_weight', data_get($defaults, 'recipient_block.label_weight'), 400, 800),
                'value_weight' => $this->int($input, 'recipient_block.value_weight', data_get($defaults, 'recipient_block.value_weight'), 400, 800),
                'label_color' => $this->hex($input, 'recipient_block.label_color', data_get($defaults, 'recipient_block.label_color')),
                'value_color' => $this->hex($input, 'recipient_block.value_color', data_get($defaults, 'recipient_block.value_color')),
                'name_color' => $this->hex($input, 'recipient_block.name_color', data_get($defaults, 'recipient_block.name_color')),
                'alignment' => $this->option($input, 'recipient_block.alignment', data_get($defaults, 'recipient_block.alignment'), ['left', 'center', 'right']),
                'padding_mm' => $this->float($input, 'recipient_block.padding_mm', data_get($defaults, 'recipient_block.padding_mm'), 0, 12),
                'margin_top_mm' => $this->float($input, 'recipient_block.margin_top_mm', data_get($defaults, 'recipient_block.margin_top_mm'), 0, 40),
                'margin_bottom_mm' => $this->float($input, 'recipient_block.margin_bottom_mm', data_get($defaults, 'recipient_block.margin_bottom_mm'), 0, 20),
                'border_color' => $this->hex($input, 'recipient_block.border_color', data_get($defaults, 'recipient_block.border_color')),
                'border_width' => $this->borderWidth($input, 'recipient_block.border_width', data_get($defaults, 'recipient_block.border_width')),
                'background_color' => $this->hex($input, 'recipient_block.background_color', data_get($defaults, 'recipient_block.background_color')),
                'show_address' => $this->bool($input, 'recipient_block.show_address', data_get($defaults, 'recipient_block.show_address')),
                'show_phone' => $this->bool($input, 'recipient_block.show_phone', data_get($defaults, 'recipient_block.show_phone')),
                'show_email' => $this->bool($input, 'recipient_block.show_email', data_get($defaults, 'recipient_block.show_email')),
                'show_tax_identifier' => $this->bool($input, 'recipient_block.show_tax_identifier', data_get($defaults, 'recipient_block.show_tax_identifier')),
                'width_percent' => $this->int($input, 'recipient_block.width_percent', data_get($defaults, 'recipient_block.width_percent'), 30, 100),
                'row_spacing_mm' => $this->float($input, 'recipient_block.row_spacing_mm', data_get($defaults, 'recipient_block.row_spacing_mm'), 0, 5),
                'label_gap_mm' => $this->float($input, 'recipient_block.label_gap_mm', data_get($defaults, 'recipient_block.label_gap_mm'), 0, 10),
                'heading_spacing_mm' => $this->float($input, 'recipient_block.heading_spacing_mm', data_get($defaults, 'recipient_block.heading_spacing_mm'), 0, 10),
                'heading' => $this->textGroup($input, $defaults, 'recipient_block.heading', false),
                'name' => $this->textGroup($input, $defaults, 'recipient_block.name', false),
                'contact' => $this->textGroup($input, $defaults, 'recipient_block.contact', false),
                'labels' => $this->textGroup($input, $defaults, 'recipient_block.labels', true),
                'values' => $this->textGroup($input, $defaults, 'recipient_block.values', false) + ['wrap' => $this->bool($input, 'recipient_block.values.wrap', data_get($defaults, 'recipient_block.values.wrap'))],
            ],
            'document_title' => $this->typography($input, $defaults, 'document_title') + [
                'margin_left_mm' => $this->float($input, 'document_title.margin_left_mm', data_get($defaults, 'document_title.margin_left_mm'), 0, 30),
                'margin_right_mm' => $this->float($input, 'document_title.margin_right_mm', data_get($defaults, 'document_title.margin_right_mm'), 0, 30),
                'width_percent' => $this->int($input, 'document_title.width_percent', data_get($defaults, 'document_title.width_percent'), 30, 100),
                'text_transform' => $this->option($input, 'document_title.text_transform', data_get($defaults, 'document_title.text_transform'), ['none', 'uppercase']),
            ],
            'document_info' => [
                'visible' => $this->bool($input, 'document_info.visible', data_get($defaults, 'document_info.visible')),
                'label_font_size' => $this->float($input, 'document_info.label_font_size', data_get($defaults, 'document_info.label_font_size'), 8, 14),
                'value_font_size' => $this->float($input, 'document_info.value_font_size', data_get($defaults, 'document_info.value_font_size'), 8, 12),
                'label_font_family' => $this->option($input, 'document_info.label_font_family', data_get($defaults, 'document_info.label_font_family'), ['DejaVu Sans', 'Helvetica']),
                'value_font_family' => $this->option($input, 'document_info.value_font_family', data_get($defaults, 'document_info.value_font_family'), ['DejaVu Sans', 'Helvetica']),
                'label_weight' => $this->int($input, 'document_info.label_weight', data_get($defaults, 'document_info.label_weight'), 400, 800),
                'value_weight' => $this->int($input, 'document_info.value_weight', data_get($defaults, 'document_info.value_weight'), 400, 800),
                'label_color' => $this->hex($input, 'document_info.label_color', data_get($defaults, 'document_info.label_color')),
                'value_color' => $this->hex($input, 'document_info.value_color', data_get($defaults, 'document_info.value_color')),
                'header_background' => $this->hex($input, 'document_info.header_background', data_get($defaults, 'document_info.header_background')),
                'value_background' => $this->hex($input, 'document_info.value_background', data_get($defaults, 'document_info.value_background')),
                'border_color' => $this->hex($input, 'document_info.border_color', data_get($defaults, 'document_info.border_color')),
                'border_width' => $this->borderWidth($input, 'document_info.border_width', data_get($defaults, 'document_info.border_width')),
                'padding_y' => $this->float($input, 'document_info.padding_y', data_get($defaults, 'document_info.padding_y'), 2, 8),
                'padding_x' => $this->float($input, 'document_info.padding_x', data_get($defaults, 'document_info.padding_x'), 3, 10),
                'margin_bottom_mm' => $this->float($input, 'document_info.margin_bottom_mm', data_get($defaults, 'document_info.margin_bottom_mm'), 0, 15),
                'column_1_width' => $this->int($input, 'document_info.column_1_width', data_get($defaults, 'document_info.column_1_width'), 10, 50),
                'column_2_width' => $this->int($input, 'document_info.column_2_width', data_get($defaults, 'document_info.column_2_width'), 10, 50),
                'column_3_width' => $this->int($input, 'document_info.column_3_width', data_get($defaults, 'document_info.column_3_width'), 10, 50),
                'column_4_width' => $this->int($input, 'document_info.column_4_width', data_get($defaults, 'document_info.column_4_width'), 10, 50),
                'width_percent' => $this->int($input, 'document_info.width_percent', data_get($defaults, 'document_info.width_percent'), 40, 100),
                'row_height_mm' => $this->float($input, 'document_info.row_height_mm', data_get($defaults, 'document_info.row_height_mm'), 4, 20),
                'margin_top_mm' => $this->float($input, 'document_info.margin_top_mm', data_get($defaults, 'document_info.margin_top_mm'), 0, 15),
                'outer_border' => $this->bool($input, 'document_info.outer_border', data_get($defaults, 'document_info.outer_border')),
                'inner_horizontal_borders' => $this->bool($input, 'document_info.inner_horizontal_borders', data_get($defaults, 'document_info.inner_horizontal_borders')),
                'inner_vertical_borders' => $this->bool($input, 'document_info.inner_vertical_borders', data_get($defaults, 'document_info.inner_vertical_borders')),
                'border_opacity' => $this->int($input, 'document_info.border_opacity', data_get($defaults, 'document_info.border_opacity'), 0, 100),
                'border_style' => $this->option($input, 'document_info.border_style', data_get($defaults, 'document_info.border_style'), ['solid', 'dashed', 'dotted']),
                'label_alignment' => $this->option($input, 'document_info.label_alignment', data_get($defaults, 'document_info.label_alignment'), ['left', 'center', 'right']),
                'value_alignment' => $this->option($input, 'document_info.value_alignment', data_get($defaults, 'document_info.value_alignment'), ['left', 'center', 'right']),
            ],
            'item_table' => [
                'header_background' => $this->hex($input, 'item_table.header_background', data_get($defaults, 'item_table.header_background')),
                'header_text' => $this->hex($input, 'item_table.header_text', data_get($defaults, 'item_table.header_text')),
                'header_font_size' => $this->float($input, 'item_table.header_font_size', data_get($defaults, 'item_table.header_font_size'), 6.5, 10),
                'header_font_weight' => $this->int($input, 'item_table.header_font_weight', data_get($defaults, 'item_table.header_font_weight'), 400, 800),
                'body_font_size' => $this->float($input, 'item_table.body_font_size', data_get($defaults, 'item_table.body_font_size'), 7, 11),
                'body_text_color' => $this->hex($input, 'item_table.body_text_color', data_get($defaults, 'item_table.body_text_color')),
                'body_font_weight' => $this->int($input, 'item_table.body_font_weight', data_get($defaults, 'item_table.body_font_weight'), 400, 800),
                'separator_color' => $this->hex($input, 'item_table.separator_color', data_get($defaults, 'item_table.separator_color')),
                'separator_opacity' => $this->int($input, 'item_table.separator_opacity', data_get($defaults, 'item_table.separator_opacity'), 0, 100),
                'separator_thickness' => $this->option($input, 'item_table.separator_thickness', data_get($defaults, 'item_table.separator_thickness'), ['fine', 'normal', 'thick']),
                'separator_style' => $this->option($input, 'item_table.separator_style', data_get($defaults, 'item_table.separator_style'), ['solid', 'dashed', 'dotted']),
                'grid_mode' => $this->option($input, 'item_table.grid_mode', data_get($defaults, 'item_table.grid_mode'), ['horizontal', 'full', 'none']),
                'padding_y' => $this->float($input, 'item_table.padding_y', data_get($defaults, 'item_table.padding_y'), 2, 7),
                'padding_x' => $this->float($input, 'item_table.padding_x', data_get($defaults, 'item_table.padding_x'), 3, 9),
                'row_height_mm' => $this->float($input, 'item_table.row_height_mm', data_get($defaults, 'item_table.row_height_mm'), 3, 12),
                'empty_row_height_mm' => $this->float($input, 'item_table.empty_row_height_mm', data_get($defaults, 'item_table.empty_row_height_mm'), 3, 10),
                'width_percent' => $this->int($input, 'item_table.width_percent', data_get($defaults, 'item_table.width_percent'), 50, 100),
                'header_height_mm' => $this->float($input, 'item_table.header_height_mm', data_get($defaults, 'item_table.header_height_mm'), 4, 15),
                'margin_top_mm' => $this->float($input, 'item_table.margin_top_mm', data_get($defaults, 'item_table.margin_top_mm'), 0, 15),
                'margin_bottom_mm' => $this->float($input, 'item_table.margin_bottom_mm', data_get($defaults, 'item_table.margin_bottom_mm'), 0, 15),
                'horizontal_borders' => $this->bool($input, 'item_table.horizontal_borders', data_get($defaults, 'item_table.horizontal_borders')),
                'vertical_borders' => $this->bool($input, 'item_table.vertical_borders', data_get($defaults, 'item_table.vertical_borders')),
                'columns' => collect(['reference', 'designation', 'presentation', 'quantity', 'unit_price', 'line_total', 'discount', 'total'])
                    ->mapWithKeys(fn (string $column) => [$column => $this->int($input, 'item_table.columns.'.$column, data_get($defaults, 'item_table.columns.'.$column), 4, 50)])
                    ->all(),
            ],
            'totals' => [
                'visible' => $this->bool($input, 'totals.visible', data_get($defaults, 'totals.visible')),
                'width_percent' => $this->int($input, 'totals.width_percent', data_get($defaults, 'totals.width_percent'), 30, 100),
                'alignment' => $this->option($input, 'totals.alignment', data_get($defaults, 'totals.alignment'), ['left', 'center', 'right']),
                'background_color' => $this->hex($input, 'totals.background_color', data_get($defaults, 'totals.background_color')),
                'grand_background_color' => $this->hex($input, 'totals.grand_background_color', data_get($defaults, 'totals.grand_background_color')),
                'label_color' => $this->hex($input, 'totals.label_color', data_get($defaults, 'totals.label_color')),
                'amount_color' => $this->hex($input, 'totals.amount_color', data_get($defaults, 'totals.amount_color')),
                'label_weight' => $this->int($input, 'totals.label_weight', data_get($defaults, 'totals.label_weight'), 400, 800),
                'amount_weight' => $this->int($input, 'totals.amount_weight', data_get($defaults, 'totals.amount_weight'), 400, 800),
                'grand_weight' => $this->int($input, 'totals.grand_weight', data_get($defaults, 'totals.grand_weight'), 400, 800),
                'padding_y' => $this->float($input, 'totals.padding_y', data_get($defaults, 'totals.padding_y'), 1, 10),
                'padding_x' => $this->float($input, 'totals.padding_x', data_get($defaults, 'totals.padding_x'), 2, 14),
                'label_column_width' => $this->int($input, 'totals.label_column_width', data_get($defaults, 'totals.label_column_width'), 20, 80),
                'amount_column_width' => $this->int($input, 'totals.amount_column_width', data_get($defaults, 'totals.amount_column_width'), 20, 80),
                'margin_top_mm' => $this->float($input, 'totals.margin_top_mm', data_get($defaults, 'totals.margin_top_mm'), 0, 20),
                'margin_bottom_mm' => $this->float($input, 'totals.margin_bottom_mm', data_get($defaults, 'totals.margin_bottom_mm'), 0, 20),
                'row_height_mm' => $this->float($input, 'totals.row_height_mm', data_get($defaults, 'totals.row_height_mm'), 3, 15),
                'border_opacity' => $this->int($input, 'totals.border_opacity', data_get($defaults, 'totals.border_opacity'), 0, 100),
                'border_style' => $this->option($input, 'totals.border_style', data_get($defaults, 'totals.border_style'), ['solid', 'dashed', 'dotted']),
                'outer_border' => $this->bool($input, 'totals.outer_border', data_get($defaults, 'totals.outer_border')),
                'horizontal_borders' => $this->bool($input, 'totals.horizontal_borders', data_get($defaults, 'totals.horizontal_borders')),
                'vertical_separator' => $this->bool($input, 'totals.vertical_separator', data_get($defaults, 'totals.vertical_separator')),
                'final_label_font_size' => $this->float($input, 'totals.final_label_font_size', data_get($defaults, 'totals.final_label_font_size'), 8, 18),
                'final_amount_font_size' => $this->float($input, 'totals.final_amount_font_size', data_get($defaults, 'totals.final_amount_font_size'), 8, 18),
                'final_label_color' => $this->hex($input, 'totals.final_label_color', data_get($defaults, 'totals.final_label_color')),
                'final_amount_color' => $this->hex($input, 'totals.final_amount_color', data_get($defaults, 'totals.final_amount_color')),
                'border_color' => $this->hex($input, 'totals.border_color', data_get($defaults, 'totals.border_color')),
                'border_width' => $this->borderWidth($input, 'totals.border_width', data_get($defaults, 'totals.border_width')),
                'label_font_size' => $this->float($input, 'totals.label_font_size', data_get($defaults, 'totals.label_font_size'), 7.5, 12),
                'amount_font_size' => $this->float($input, 'totals.amount_font_size', data_get($defaults, 'totals.amount_font_size'), 7.5, 12),
                'grand_font_size' => $this->float($input, 'totals.grand_font_size', data_get($defaults, 'totals.grand_font_size'), 9, 15),
            ],
            'amount_words' => [
                'visible' => $this->bool($input, 'amount_words.visible', data_get($defaults, 'amount_words.visible')),
                'alignment' => $this->option($input, 'amount_words.alignment', data_get($defaults, 'amount_words.alignment'), ['left', 'center', 'right']),
                'background_color' => $this->hex($input, 'amount_words.background_color', data_get($defaults, 'amount_words.background_color')),
                'intro_color' => $this->hex($input, 'amount_words.intro_color', data_get($defaults, 'amount_words.intro_color')),
                'amount_color' => $this->hex($input, 'amount_words.amount_color', data_get($defaults, 'amount_words.amount_color')),
                'intro_weight' => $this->int($input, 'amount_words.intro_weight', data_get($defaults, 'amount_words.intro_weight'), 400, 800),
                'amount_weight' => $this->int($input, 'amount_words.amount_weight', data_get($defaults, 'amount_words.amount_weight'), 400, 800),
                'padding_y' => $this->float($input, 'amount_words.padding_y', data_get($defaults, 'amount_words.padding_y'), 2, 18),
                'padding_x' => $this->float($input, 'amount_words.padding_x', data_get($defaults, 'amount_words.padding_x'), 2, 20),
                'margin_top_mm' => $this->float($input, 'amount_words.margin_top_mm', data_get($defaults, 'amount_words.margin_top_mm'), 0, 15),
                'margin_bottom_mm' => $this->float($input, 'amount_words.margin_bottom_mm', data_get($defaults, 'amount_words.margin_bottom_mm'), 0, 15),
                'width_percent' => $this->int($input, 'amount_words.width_percent', data_get($defaults, 'amount_words.width_percent'), 30, 100),
                'border_visible' => $this->bool($input, 'amount_words.border_visible', data_get($defaults, 'amount_words.border_visible')),
                'border_opacity' => $this->int($input, 'amount_words.border_opacity', data_get($defaults, 'amount_words.border_opacity'), 0, 100),
                'intro_font_family' => $this->option($input, 'amount_words.intro_font_family', data_get($defaults, 'amount_words.intro_font_family'), ['DejaVu Sans', 'Helvetica']),
                'intro_alignment' => $this->option($input, 'amount_words.intro_alignment', data_get($defaults, 'amount_words.intro_alignment'), ['left', 'center', 'right']),
                'intro_line_height' => $this->float($input, 'amount_words.intro_line_height', data_get($defaults, 'amount_words.intro_line_height'), 1, 2),
                'intro_letter_spacing_em' => $this->float($input, 'amount_words.intro_letter_spacing_em', data_get($defaults, 'amount_words.intro_letter_spacing_em'), 0, .2),
                'amount_font_family' => $this->option($input, 'amount_words.amount_font_family', data_get($defaults, 'amount_words.amount_font_family'), ['DejaVu Sans', 'Helvetica']),
                'amount_alignment' => $this->option($input, 'amount_words.amount_alignment', data_get($defaults, 'amount_words.amount_alignment'), ['left', 'center', 'right']),
                'amount_line_height' => $this->float($input, 'amount_words.amount_line_height', data_get($defaults, 'amount_words.amount_line_height'), 1, 2),
                'amount_letter_spacing_em' => $this->float($input, 'amount_words.amount_letter_spacing_em', data_get($defaults, 'amount_words.amount_letter_spacing_em'), 0, .2),
                'border_color' => $this->hex($input, 'amount_words.border_color', data_get($defaults, 'amount_words.border_color')),
                'border_width' => $this->borderWidth($input, 'amount_words.border_width', data_get($defaults, 'amount_words.border_width')),
                'intro_font_size' => $this->float($input, 'amount_words.intro_font_size', data_get($defaults, 'amount_words.intro_font_size'), 7.5, 12),
                'amount_font_size' => $this->float($input, 'amount_words.amount_font_size', data_get($defaults, 'amount_words.amount_font_size'), 9, 16),
            ],
            'footer' => [
                'visible' => $this->bool($input, 'footer.visible', data_get($defaults, 'footer.visible')),
                'font_size' => $this->float($input, 'footer.font_size', data_get($defaults, 'footer.font_size'), 6.5, 10),
                'text_color' => $this->hex($input, 'footer.text_color', data_get($defaults, 'footer.text_color')),
                'border_color' => $this->hex($input, 'footer.border_color', data_get($defaults, 'footer.border_color')),
                'border_width' => $this->borderWidth($input, 'footer.border_width', data_get($defaults, 'footer.border_width')),
                'margin_top_mm' => $this->float($input, 'footer.margin_top_mm', data_get($defaults, 'footer.margin_top_mm'), 0, 10),
            ],
            'pagination' => [
                'visible' => $this->bool($input, 'pagination.visible', data_get($defaults, 'pagination.visible')),
                'position' => $this->option($input, 'pagination.position', data_get($defaults, 'pagination.position'), ['top', 'bottom']),
                'alignment' => $this->option($input, 'pagination.alignment', data_get($defaults, 'pagination.alignment'), ['left', 'center', 'right']),
                'font_family' => $this->option($input, 'pagination.font_family', data_get($defaults, 'pagination.font_family'), ['DejaVu Sans', 'Helvetica']),
                'font_size' => $this->float($input, 'pagination.font_size', data_get($defaults, 'pagination.font_size'), 6, 11),
                'color' => $this->hex($input, 'pagination.color', data_get($defaults, 'pagination.color')),
                'margin_x_mm' => $this->float($input, 'pagination.margin_x_mm', data_get($defaults, 'pagination.margin_x_mm'), 5, 30),
                'margin_y_mm' => $this->float($input, 'pagination.margin_y_mm', data_get($defaults, 'pagination.margin_y_mm'), 4, 20),
            ],
            'watermark' => [
                'visible' => $this->bool($input, 'watermark.visible', data_get($defaults, 'watermark.visible')),
            ],
            'stamp' => [
                'visible' => $this->bool($input, 'stamp.visible', data_get($defaults, 'stamp.visible')),
                'position_anchor' => $this->option($input, 'stamp.position_anchor', data_get($defaults, 'stamp.position_anchor'), ['bottom_left', 'bottom_right', 'top_left', 'top_right']),
                'offset_x_mm' => $this->float($input, 'stamp.offset_x_mm', data_get($defaults, 'stamp.offset_x_mm'), 0, 120),
                'offset_y_mm' => $this->float($input, 'stamp.offset_y_mm', data_get($defaults, 'stamp.offset_y_mm'), 0, 180),
                'display_width_mm' => $this->float($input, 'stamp.display_width_mm', data_get($defaults, 'stamp.display_width_mm'), 20, 100),
                'display_height_mm' => $this->nullableFloat($input, 'stamp.display_height_mm', data_get($defaults, 'stamp.display_height_mm'), 10, 100),
                'rotation_deg' => $this->float($input, 'stamp.rotation_deg', data_get($defaults, 'stamp.rotation_deg'), -20, 20),
                'opacity' => $this->int($input, 'stamp.opacity', data_get($defaults, 'stamp.opacity'), 10, 100),
                'preserve_aspect_ratio' => $this->bool($input, 'stamp.preserve_aspect_ratio', data_get($defaults, 'stamp.preserve_aspect_ratio')),
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    private function hex(array $input, string $key, mixed $default): string
    {
        $value = strtolower(trim((string) data_get($input, $key, '')));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : (string) $default;
    }

    /** @param array<string, mixed> $input */
    private function bool(array $input, string $key, mixed $default): bool
    {
        $value = data_get($input, $key);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $default;
    }

    /** @param array<string, mixed> $input */
    private function int(array $input, string $key, mixed $default, int $min, int $max): int
    {
        $value = data_get($input, $key);
        if (! is_numeric($value)) {
            return (int) $default;
        }

        return max($min, min($max, (int) $value));
    }

    /** @param array<string, mixed> $input */
    private function float(array $input, string $key, mixed $default, float $min, float $max): float
    {
        $value = data_get($input, $key);
        if (! is_numeric($value)) {
            return (float) $default;
        }

        return round(max($min, min($max, (float) $value)), 2);
    }

    /** @param array<string, mixed> $input */
    private function nullableFloat(array $input, string $key, mixed $default, float $min, float $max): ?float
    {
        $value = data_get($input, $key);
        if ($value === null || $value === '') {
            return $default === null ? null : (float) $default;
        }

        return is_numeric($value) ? round(max($min, min($max, (float) $value)), 2) : ($default === null ? null : (float) $default);
    }

    /** @param array<string, mixed> $input */
    private function option(array $input, string $key, mixed $default, array $allowed): string
    {
        $value = (string) data_get($input, $key, $default);

        return in_array($value, $allowed, true) ? $value : (string) $default;
    }

    /** @param array<string, mixed> $input */
    private function borderWidth(array $input, string $key, mixed $default): string
    {
        return $this->option($input, $key, $default, ['0pt', '.45pt', '.7pt', '.85pt', '.95pt', '1.1pt', '1.4pt']);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $defaults */
    private function typography(array $input, array $defaults, string $prefix): array
    {
        return [
            'visible' => $this->bool($input, $prefix.'.visible', data_get($defaults, $prefix.'.visible')),
            'font_family' => $this->option($input, $prefix.'.font_family', data_get($defaults, $prefix.'.font_family'), ['DejaVu Sans', 'Helvetica']),
            'font_size' => $this->float($input, $prefix.'.font_size', data_get($defaults, $prefix.'.font_size'), 7, 36),
            'font_weight' => $this->int($input, $prefix.'.font_weight', data_get($defaults, $prefix.'.font_weight'), 400, 800),
            'color' => $this->hex($input, $prefix.'.color', data_get($defaults, $prefix.'.color')),
            'letter_spacing_em' => $this->float($input, $prefix.'.letter_spacing_em', data_get($defaults, $prefix.'.letter_spacing_em'), 0, .2),
            'line_height' => $this->float($input, $prefix.'.line_height', data_get($defaults, $prefix.'.line_height'), 1, 2),
            'alignment' => $this->option($input, $prefix.'.alignment', data_get($defaults, $prefix.'.alignment'), ['left', 'center', 'right']),
            'margin_top_mm' => $this->float($input, $prefix.'.margin_top_mm', data_get($defaults, $prefix.'.margin_top_mm'), 0, 30),
            'margin_bottom_mm' => $this->float($input, $prefix.'.margin_bottom_mm', data_get($defaults, $prefix.'.margin_bottom_mm'), 0, 30),
        ];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $defaults */
    private function textGroup(array $input, array $defaults, string $prefix, bool $withWidth): array
    {
        $group = [
            'font_family' => $this->option($input, $prefix.'.font_family', data_get($defaults, $prefix.'.font_family'), ['DejaVu Sans', 'Helvetica']),
            'font_size' => $this->float($input, $prefix.'.font_size', data_get($defaults, $prefix.'.font_size'), 6, 20),
            'font_weight' => $this->int($input, $prefix.'.font_weight', data_get($defaults, $prefix.'.font_weight'), 400, 800),
            'color' => $this->hex($input, $prefix.'.color', data_get($defaults, $prefix.'.color')),
            'line_height' => $this->float($input, $prefix.'.line_height', data_get($defaults, $prefix.'.line_height'), 1, 2),
            'alignment' => $this->option($input, $prefix.'.alignment', data_get($defaults, $prefix.'.alignment'), ['left', 'center', 'right']),
        ];

        if ($withWidth) {
            $group['width_percent'] = $this->int($input, $prefix.'.width_percent', data_get($defaults, $prefix.'.width_percent'), 10, 50);
        }

        return $group;
    }
}
