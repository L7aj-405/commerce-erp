<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentSellerProfile
{
    /** Accent colour applied to the document title, table headers and small accents. */
    public const DEFAULT_ACCENT_COLOR = '#2b3a30';

    /**
     * Safe, allow-listed visual template defaults for official commercial PDFs.
     *
     * These values are intentionally constrained to simple colours, sizes and
     * booleans so organization users can tune presentation without storing raw
     * CSS/HTML or changing document data, numbering, snapshots or calculations.
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_PDF_TEMPLATE = [
        'page' => [
            'base_font_size' => 9.5,
            'text_color' => '#1f211d',
            'line_height' => 1.43,
            'margin_top_mm' => 20,
            'margin_right_mm' => 13,
            'margin_bottom_mm' => 30,
            'margin_left_mm' => 13,
        ],
        'brand' => [
            'accent_color' => self::DEFAULT_ACCENT_COLOR,
        ],
        'logo' => [
            'visible' => true,
            'max_width_mm' => 79,
            'max_height_mm' => 26,
            'margin_bottom_mm' => 4,
            'alignment' => 'left',
            'preserve_aspect_ratio' => true,
        ],
        'company_name' => [
            'visible' => true,
            'font_family' => 'DejaVu Sans',
            'font_size' => 17,
            'font_weight' => 700,
            'color' => '#1f211d',
            'letter_spacing_em' => 0,
            'line_height' => 1.2,
            'alignment' => 'left',
            'margin_top_mm' => 0,
            'margin_bottom_mm' => 2,
            'margin_left_mm' => 0,
            'margin_right_mm' => 0,
            'horizontal_offset_mm' => 0,
            'text_transform' => 'none',
            'position' => 'below_logo',
            'logo_spacing_mm' => 2,
            'width_percent' => 100,
        ],
        'document_header' => [
            'visible' => true,
            'font_size' => 8.5,
            'text_color' => '#3f423b',
            'border_color' => '#d7d7cf',
            'border_width' => '.7pt',
            'height_mm' => 12,
            'margin_bottom_mm' => 0,
        ],
        'company_block' => [
            'visible' => true,
            'font_size' => 9.2,
            'line_height' => 1.42,
            'label_color' => '#4c4f47',
            'value_color' => '#1f211d',
            'label_weight' => 600,
            'value_weight' => 700,
            'alignment' => 'left',
            'padding_mm' => 0,
            'margin_top_mm' => 0,
            'margin_bottom_mm' => 0,
            'border_color' => '#ffffff',
            'border_width' => '0pt',
            'background_color' => '#ffffff',
            'show_address' => true,
            'show_tax_identifier' => true,
            'show_phone' => true,
            'show_email' => true,
            'show_registration_number' => true,
            'show_patente_number' => true,
            'show_additional_identifiers' => true,
            'width_percent' => 100,
            'row_spacing_mm' => .7,
            'label_gap_mm' => 1,
            'labels' => ['font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 600, 'color' => '#4c4f47', 'line_height' => 1.42, 'width_percent' => 18, 'alignment' => 'left'],
            'values' => ['font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 700, 'color' => '#1f211d', 'line_height' => 1.42, 'alignment' => 'left', 'wrap' => true],
        ],
        'recipient_block' => [
            'visible' => true,
            'font_size' => 9.2,
            'title_size' => 8.8,
            'name_size' => 10.8,
            'contact_size' => 9.2,
            'title_weight' => 700,
            'name_weight' => 700,
            'label_weight' => 600,
            'value_weight' => 700,
            'label_color' => '#4c4f47',
            'value_color' => '#1f211d',
            'name_color' => self::DEFAULT_ACCENT_COLOR,
            'alignment' => 'left',
            'padding_mm' => 0,
            'margin_top_mm' => 18,
            'margin_bottom_mm' => 0,
            'border_color' => '#ffffff',
            'border_width' => '0pt',
            'background_color' => '#ffffff',
            'show_address' => true,
            'show_phone' => true,
            'show_email' => true,
            'show_tax_identifier' => true,
            'width_percent' => 100,
            'row_spacing_mm' => .7,
            'label_gap_mm' => 1,
            'heading_spacing_mm' => 1,
            'heading' => ['font_family' => 'DejaVu Sans', 'font_size' => 8.8, 'font_weight' => 700, 'color' => '#4c4f47', 'line_height' => 1.2, 'alignment' => 'left'],
            'name' => ['font_family' => 'DejaVu Sans', 'font_size' => 10.8, 'font_weight' => 700, 'color' => self::DEFAULT_ACCENT_COLOR, 'line_height' => 1.25, 'alignment' => 'left'],
            'contact' => ['font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 700, 'color' => '#1f211d', 'line_height' => 1.42, 'alignment' => 'left'],
            'labels' => ['font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 600, 'color' => '#4c4f47', 'line_height' => 1.42, 'width_percent' => 18, 'alignment' => 'left'],
            'values' => ['font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 700, 'color' => '#1f211d', 'line_height' => 1.42, 'alignment' => 'left', 'wrap' => true],
        ],
        'document_title' => [
            'visible' => true,
            'font_family' => 'DejaVu Sans',
            'font_size' => 26,
            'font_weight' => 700,
            'color' => '#1f211d',
            'letter_spacing_em' => 0.06,
            'line_height' => 1.2,
            'alignment' => 'center',
            'margin_top_mm' => 5,
            'margin_bottom_mm' => 4,
            'margin_left_mm' => 0,
            'margin_right_mm' => 0,
            'width_percent' => 100,
            'text_transform' => 'none',
        ],
        'document_info' => [
            'visible' => true,
            'label_font_size' => 10.8,
            'value_font_size' => 9.4,
            'label_font_family' => 'DejaVu Sans',
            'value_font_family' => 'DejaVu Sans',
            'label_weight' => 700,
            'value_weight' => 600,
            'label_color' => '#111111',
            'value_color' => '#1f211d',
            'header_background' => '#ffffff',
            'value_background' => '#ffffff',
            'border_color' => '#000000',
            'border_width' => '.85pt',
            'padding_y' => 4,
            'padding_x' => 6,
            'margin_bottom_mm' => 3,
            'column_1_width' => 20,
            'column_2_width' => 28,
            'column_3_width' => 32,
            'column_4_width' => 20,
            'width_percent' => 100,
            'row_height_mm' => 8,
            'margin_top_mm' => 0,
            'outer_border' => true,
            'inner_horizontal_borders' => true,
            'inner_vertical_borders' => true,
            'border_opacity' => 100,
            'border_style' => 'solid',
            'label_alignment' => 'center',
            'value_alignment' => 'center',
        ],
        'item_table' => [
            'header_background' => self::DEFAULT_ACCENT_COLOR,
            'header_text' => '#ffffff',
            'header_font_size' => 7.9,
            'header_font_weight' => 700,
            'body_font_size' => 8.8,
            'body_text_color' => '#1f211d',
            'body_font_weight' => 400,
            'separator_color' => '#8c8c8c',
            'separator_opacity' => 50,
            'separator_thickness' => 'fine',
            'separator_style' => 'solid',
            'grid_mode' => 'horizontal',
            'padding_y' => 3,
            'padding_x' => 5,
            'row_height_mm' => 6,
            'empty_row_height_mm' => 6,
            'width_percent' => 100,
            'header_height_mm' => 7,
            'margin_top_mm' => 0,
            'margin_bottom_mm' => 0,
            'horizontal_borders' => true,
            'vertical_borders' => false,
            'columns' => ['reference' => 10, 'designation' => 33, 'presentation' => 9, 'quantity' => 6, 'unit_price' => 11, 'line_total' => 11, 'discount' => 9, 'total' => 11],
        ],
        'totals' => [
            'visible' => true,
            'width_percent' => 45,
            'alignment' => 'right',
            'background_color' => '#ffffff',
            'grand_background_color' => '#ffffff',
            'label_color' => '#111111',
            'amount_color' => '#1f211d',
            'label_weight' => 600,
            'amount_weight' => 700,
            'grand_weight' => 800,
            'padding_y' => 4,
            'padding_x' => 6,
            'label_column_width' => 56,
            'amount_column_width' => 44,
            'margin_top_mm' => 0,
            'margin_bottom_mm' => 0,
            'row_height_mm' => 6,
            'border_opacity' => 100,
            'border_style' => 'solid',
            'outer_border' => true,
            'horizontal_borders' => true,
            'vertical_separator' => true,
            'final_label_font_size' => 10.8,
            'final_amount_font_size' => 10.8,
            'final_label_color' => '#111111',
            'final_amount_color' => '#1f211d',
            'border_color' => '#000000',
            'border_width' => '.85pt',
            'label_font_size' => 9.2,
            'amount_font_size' => 9.2,
            'grand_font_size' => 10.8,
        ],
        'amount_words' => [
            'visible' => true,
            'alignment' => 'center',
            'background_color' => '#ffffff',
            'intro_color' => '#111111',
            'amount_color' => '#1f211d',
            'intro_weight' => 700,
            'amount_weight' => 800,
            'padding_y' => 8,
            'padding_x' => 10,
            'margin_top_mm' => 3,
            'margin_bottom_mm' => 0,
            'width_percent' => 100,
            'border_visible' => true,
            'border_opacity' => 100,
            'intro_font_family' => 'DejaVu Sans',
            'intro_alignment' => 'center',
            'intro_line_height' => 1.3,
            'intro_letter_spacing_em' => 0,
            'amount_font_family' => 'DejaVu Sans',
            'amount_alignment' => 'center',
            'amount_line_height' => 1.35,
            'amount_letter_spacing_em' => 0,
            'border_color' => '#000000',
            'border_width' => '.85pt',
            'intro_font_size' => 9.2,
            'amount_font_size' => 12,
        ],
        'footer' => [
            'visible' => true,
            'font_size' => 7.8,
            'text_color' => '#4f524a',
            'border_color' => '#d7d7cf',
            'border_width' => '.7pt',
            'margin_top_mm' => 0,
        ],
        'pagination' => [
            'visible' => true,
            'position' => 'bottom',
            'alignment' => 'right',
            'font_family' => 'DejaVu Sans',
            'font_size' => 7,
            'color' => '#999999',
            'margin_x_mm' => 13,
            'margin_y_mm' => 8,
        ],
        'watermark' => [
            'visible' => false,
        ],
        'stamp' => [
            'visible' => true,
            'position_anchor' => 'bottom_left',
            'offset_x_mm' => 0,
            'offset_y_mm' => 5,
            'display_width_mm' => 35,
            'display_height_mm' => null,
            'rotation_deg' => 0,
            'opacity' => 100,
            'preserve_aspect_ratio' => true,
        ],
    ];

    /**
     * Freeze the seller identity into an immutable, self-contained array.
     *
     * Everything a document ever needs to render is captured here at draft
     * creation, including the logo embedded as a base64 data URI. Renderers read
     * this snapshot and never the live profile or the logo file, so an issued
     * invoice keeps rendering identically even if the logo is later replaced or
     * deleted.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Organization $organization, Store $store): array
    {
        $organizationProfile = data_get($organization->settings, 'document_profile', []);
        $storeProfile = data_get($store->settings, 'document_profile', []);
        $presentation = $this->presentationSettings($organization);

        return [
            'legal_name' => $this->value($organizationProfile, 'legal_name') ?: $organization->name,
            'trade_name' => $this->value($organizationProfile, 'trade_name'),
            'address' => $this->value($organizationProfile, 'address'),
            'phone' => $this->value($organizationProfile, 'phone'),
            'fax' => $this->value($organizationProfile, 'fax'),
            'email' => $this->value($organizationProfile, 'email'),
            'tax_identifier' => $this->value($organizationProfile, 'tax_identifier'),
            'registration_number' => $this->value($organizationProfile, 'registration_number'),
            'patente_number' => $this->value($organizationProfile, 'patente_number'),
            'website' => $this->value($organizationProfile, 'website'),
            'bank' => [
                'name' => $this->value($organizationProfile, 'bank_name'),
                'rib' => $this->value($organizationProfile, 'bank_rib'),
            ],
            'footer_text' => $this->value($organizationProfile, 'footer_text'),
            'accent_color' => $this->accentColor($organizationProfile),
            'logo' => $this->embedLogo($organizationProfile),
            'show_invoice_watermark' => $presentation['show_invoice_watermark'],
            'show_document_header' => $presentation['show_document_header'],
            'show_pdf_pagination' => $presentation['show_pdf_pagination'],
            'item_line_color' => $presentation['item_line_color'],
            'item_line_opacity' => $presentation['item_line_opacity'],
            'item_line_thickness' => $presentation['item_line_thickness'],
            'item_line_css_color' => $presentation['item_line_css_color'],
            'item_line_css_width' => $presentation['item_line_css_width'],
            'pdf_style' => $presentation['pdf_style'],
            'additional_identifiers' => collect($organizationProfile['additional_identifiers'] ?? [])->map(fn ($item) => [
                'label' => trim((string) ($item['label'] ?? '')),
                'value' => trim((string) ($item['value'] ?? '')),
            ])->filter(fn ($item) => $item['label'] !== '' && $item['value'] !== '')->values()->all(),
            'store' => [
                'name' => $store->name,
                'code' => $store->code,
                'address' => $this->value($storeProfile, 'address'),
                'phone' => $this->value($storeProfile, 'phone'),
                'email' => $this->value($storeProfile, 'email'),
            ],
        ];
    }

    /**
     * The organisation identity block only (no store sub-block), for internal
     * logistics documents such as the Bon de sortie which are not tied to a
     * store. Read from the current profile — a warehouse paper document is not a
     * historical financial record.
     *
     * @return array<string, mixed>
     */
    public function organizationIdentity(Organization $organization): array
    {
        $profile = data_get($organization->settings, 'document_profile', []);

        return [
            'legal_name' => $this->value($profile, 'legal_name') ?: $organization->name,
            'trade_name' => $this->value($profile, 'trade_name'),
            'address' => $this->value($profile, 'address'),
            'phone' => $this->value($profile, 'phone'),
            'email' => $this->value($profile, 'email'),
            'tax_identifier' => $this->value($profile, 'tax_identifier'),
            'registration_number' => $this->value($profile, 'registration_number'),
            'accent_color' => $this->accentColor($profile),
            'logo' => $this->embedLogo($profile),
        ];
    }

    /**
     * Presentation-only controls are intentionally live settings: changing line
     * style, running-header visibility, or pagination must not mutate historical
     * seller/customer/financial snapshots, but it should affect the current PDF
     * rendering immediately.
     *
     * @param  array<string, mixed>  $sellerSnapshot
     * @return array<string, mixed>
     */
    public function withCurrentPresentationSettings(array $sellerSnapshot, Organization $organization): array
    {
        return array_replace($sellerSnapshot, $this->presentationSettings($organization));
    }

    /** @return array<string, mixed> */
    public function presentationSettings(Organization $organization): array
    {
        $profile = data_get($organization->settings, 'document_profile', []);
        return $this->presentationSettingsForTemplate($organization, $this->effectivePdfTemplate($profile));
    }

    /**
     * Resolve an allow-listed draft template through the exact same style
     * mapper used by exported PDFs. The Studio preview uses this without
     * publishing or mutating historical document snapshots.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public function presentationSettingsForTemplate(Organization $organization, array $template): array
    {
        $profile = data_get($organization->settings, 'document_profile', []);
        $template = array_replace_recursive(self::DEFAULT_PDF_TEMPLATE, $template);
        $lineColor = $this->nestedHexColor($template, 'item_table.separator_color', $this->hexColor($profile, 'item_line_color', '#8c8c8c'));
        $lineOpacity = $this->nestedInt($template, 'item_table.separator_opacity', $this->opacity($profile, 'item_line_opacity', 50), 0, 100);
        $lineThickness = $this->nestedOption($template, 'item_table.separator_thickness', $this->lineThickness($profile, 'item_line_thickness'), ['fine', 'normal', 'thick']);

        return [
            'accent_color' => $this->nestedHexColor($template, 'brand.accent_color', $this->accentColor($profile)),
            'show_invoice_watermark' => $this->nestedBoolean($template, 'watermark.visible', $this->boolean($profile, 'show_invoice_watermark', false)),
            'show_document_header' => $this->nestedBoolean($template, 'document_header.visible', $this->boolean($profile, 'show_document_header', true)),
            'show_pdf_pagination' => $this->nestedBoolean($template, 'pagination.visible', $this->boolean($profile, 'show_pdf_pagination', true)),
            'item_line_color' => $lineColor,
            'item_line_opacity' => $lineOpacity,
            'item_line_thickness' => $lineThickness,
            'item_line_css_color' => $this->blendedColor($lineColor, $lineOpacity),
            'item_line_css_width' => $this->lineThicknessWidth($lineThickness),
            // The controller persists an explicit allow-listed schema. Merge
            // those validated nested controls first, then overlay normalized
            // renderer values (colours, ranges and compatibility fallbacks).
            'pdf_style' => array_replace_recursive($template, $this->resolvedPdfStyle($profile, $template, $lineColor, $lineOpacity, $lineThickness)),
        ];
    }

    /** @return array<string, mixed> */
    public function defaultPdfTemplate(): array
    {
        return self::DEFAULT_PDF_TEMPLATE;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function effectivePdfTemplate(array $profile): array
    {
        $published = data_get($profile, 'pdf_template.published', []);
        $legacy = [
            'brand' => ['accent_color' => $this->accentColor($profile)],
            'document_header' => ['visible' => $this->boolean($profile, 'show_document_header', true)],
            'pagination' => ['visible' => $this->boolean($profile, 'show_pdf_pagination', true)],
            'watermark' => ['visible' => $this->boolean($profile, 'show_invoice_watermark', false)],
            'item_table' => [
                'separator_color' => $this->hexColor($profile, 'item_line_color', '#8c8c8c'),
                'separator_opacity' => $this->opacity($profile, 'item_line_opacity', 50),
                'separator_thickness' => $this->lineThickness($profile, 'item_line_thickness'),
            ],
        ];

        return array_replace_recursive(self::DEFAULT_PDF_TEMPLATE, $legacy, is_array($published) ? $published : []);
    }

    /** @param array<string, mixed>|null $snapshot */
    public function validate(?array $snapshot): void
    {
        if (trim((string) ($snapshot['legal_name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'seller_profile' => 'The document seller legal name must be configured before issuance.',
            ]);
        }
    }

    /** @param array<string, mixed> $profile */
    private function accentColor(array $profile): string
    {
        $value = strtolower(trim((string) ($profile['accent_color'] ?? '')));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : self::DEFAULT_ACCENT_COLOR;
    }

    /** @param array<string, mixed> $profile */
    private function boolean(array $profile, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $profile)) {
            return $default;
        }

        $value = filter_var($profile[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    /** @param array<string, mixed> $profile */
    private function hexColor(array $profile, string $key, string $default): string
    {
        $value = strtolower(trim((string) ($profile[$key] ?? '')));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : $default;
    }

    /** @param array<string, mixed> $profile */
    private function opacity(array $profile, string $key, int $default): int
    {
        if (! is_numeric($profile[$key] ?? null)) {
            return $default;
        }

        return max(0, min(100, (int) $profile[$key]));
    }

    /** @param array<string, mixed> $profile */
    private function lineThickness(array $profile, string $key): string
    {
        $value = (string) ($profile[$key] ?? 'fine');

        return in_array($value, ['fine', 'normal', 'thick'], true) ? $value : 'fine';
    }

    private function lineThicknessWidth(string $thickness): string
    {
        return match ($thickness) {
            'thick' => '.95pt',
            'normal' => '.7pt',
            default => '.45pt',
        };
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function resolvedPdfStyle(array $profile, array $template, string $lineColor, int $lineOpacity, string $lineThickness): array
    {
        $accent = $this->nestedHexColor($template, 'brand.accent_color', $this->accentColor($profile));
        $ink = $this->nestedHexColor($template, 'page.text_color', '#1f211d');

        return [
            'page' => [
                'font_size' => $this->nestedFloat($template, 'page.base_font_size', 9.5, 8, 12),
                'text_color' => $ink,
                'line_height' => $this->nestedFloat($template, 'page.line_height', 1.43, 1.1, 1.8),
                'margin_top_mm' => $this->nestedFloat($template, 'page.margin_top_mm', 20, 10, 35),
                'margin_right_mm' => $this->nestedFloat($template, 'page.margin_right_mm', 13, 8, 30),
                'margin_bottom_mm' => $this->nestedFloat($template, 'page.margin_bottom_mm', 30, 15, 40),
                'margin_left_mm' => $this->nestedFloat($template, 'page.margin_left_mm', 13, 8, 30),
            ],
            'brand' => ['accent_color' => $accent],
            'logo' => [
                'visible' => $this->nestedBoolean($template, 'logo.visible', true),
                'max_width_mm' => $this->nestedFloat($template, 'logo.max_width_mm', 79, 30, 100),
                'max_height_mm' => $this->nestedFloat($template, 'logo.max_height_mm', 26, 12, 40),
                'margin_bottom_mm' => $this->nestedFloat($template, 'logo.margin_bottom_mm', 4, 0, 12),
                'alignment' => $this->nestedOption($template, 'logo.alignment', 'left', ['left', 'center', 'right']),
                'preserve_aspect_ratio' => $this->nestedBoolean($template, 'logo.preserve_aspect_ratio', true),
            ],
            'company_name' => $this->resolvedTypography($template, 'company_name', [
                'visible' => true, 'font_size' => 17, 'font_weight' => 700, 'color' => $ink,
                'letter_spacing_em' => 0, 'line_height' => 1.2, 'alignment' => 'left',
                'margin_top_mm' => 0, 'margin_bottom_mm' => 2,
            ]) + [
                'position' => $this->nestedOption($template, 'company_name.position', 'below_logo', ['above_logo', 'below_logo', 'beside_logo']),
                'logo_spacing_mm' => $this->nestedFloat($template, 'company_name.logo_spacing_mm', 2, 0, 20),
                'width_percent' => $this->nestedInt($template, 'company_name.width_percent', 100, 20, 100),
            ],
            'document_header' => [
                'visible' => $this->nestedBoolean($template, 'document_header.visible', true),
                'font_size' => $this->nestedFloat($template, 'document_header.font_size', 8.5, 7, 11),
                'text_color' => $this->nestedHexColor($template, 'document_header.text_color', '#3f423b'),
                'border_color' => $this->nestedHexColor($template, 'document_header.border_color', '#d7d7cf'),
                'border_width' => $this->nestedBorderWidth($template, 'document_header.border_width', '.7pt'),
                'height_mm' => $this->nestedFloat($template, 'document_header.height_mm', 12, 8, 20),
                'margin_bottom_mm' => $this->nestedFloat($template, 'document_header.margin_bottom_mm', 0, 0, 10),
            ],
            'company_block' => [
                'visible' => $this->nestedBoolean($template, 'company_block.visible', true),
                'font_size' => $this->nestedFloat($template, 'company_block.font_size', 9.2, 7.5, 12),
                'line_height' => $this->nestedFloat($template, 'company_block.line_height', 1.42, 1, 2),
                'label_color' => $this->nestedHexColor($template, 'company_block.label_color', '#4c4f47'),
                'value_color' => $this->nestedHexColor($template, 'company_block.value_color', $ink),
                'label_weight' => $this->nestedInt($template, 'company_block.label_weight', 600, 400, 800),
                'value_weight' => $this->nestedInt($template, 'company_block.value_weight', 700, 400, 800),
                'alignment' => $this->nestedOption($template, 'company_block.alignment', 'left', ['left', 'center', 'right']),
                'padding_mm' => $this->nestedFloat($template, 'company_block.padding_mm', 0, 0, 12),
                'margin_top_mm' => $this->nestedFloat($template, 'company_block.margin_top_mm', 0, 0, 20),
                'margin_bottom_mm' => $this->nestedFloat($template, 'company_block.margin_bottom_mm', 0, 0, 20),
                'border_color' => $this->nestedHexColor($template, 'company_block.border_color', '#ffffff'),
                'border_width' => $this->nestedBorderWidth($template, 'company_block.border_width', '0pt'),
                'background_color' => $this->nestedHexColor($template, 'company_block.background_color', '#ffffff'),
                'show_address' => $this->nestedBoolean($template, 'company_block.show_address', true),
                'show_tax_identifier' => $this->nestedBoolean($template, 'company_block.show_tax_identifier', true),
                'show_phone' => $this->nestedBoolean($template, 'company_block.show_phone', true),
                'show_email' => $this->nestedBoolean($template, 'company_block.show_email', true),
                'show_registration_number' => $this->nestedBoolean($template, 'company_block.show_registration_number', true),
                'show_patente_number' => $this->nestedBoolean($template, 'company_block.show_patente_number', true),
                'show_additional_identifiers' => $this->nestedBoolean($template, 'company_block.show_additional_identifiers', true),
                'width_percent' => $this->nestedInt($template, 'company_block.width_percent', 100, 30, 100),
                'row_spacing_mm' => $this->nestedFloat($template, 'company_block.row_spacing_mm', .7, 0, 5),
                'label_gap_mm' => $this->nestedFloat($template, 'company_block.label_gap_mm', 1, 0, 10),
                'labels' => $this->resolvedTextGroup($template, 'company_block.labels', [
                    'font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 600,
                    'color' => '#4c4f47', 'line_height' => 1.42, 'alignment' => 'left', 'width_percent' => 18,
                ], true),
                'values' => $this->resolvedTextGroup($template, 'company_block.values', [
                    'font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 700,
                    'color' => $ink, 'line_height' => 1.42, 'alignment' => 'left',
                ], false) + ['wrap' => $this->nestedBoolean($template, 'company_block.values.wrap', true)],
            ],
            'recipient_block' => [
                'visible' => $this->nestedBoolean($template, 'recipient_block.visible', true),
                'font_size' => $this->nestedFloat($template, 'recipient_block.font_size', 9.2, 7.5, 12),
                'title_size' => $this->nestedFloat($template, 'recipient_block.title_size', 8.8, 7, 11),
                'name_size' => $this->nestedFloat($template, 'recipient_block.name_size', 10.8, 8, 14),
                'contact_size' => $this->nestedFloat($template, 'recipient_block.contact_size', 9.2, 7, 12),
                'title_weight' => $this->nestedInt($template, 'recipient_block.title_weight', 700, 400, 800),
                'name_weight' => $this->nestedInt($template, 'recipient_block.name_weight', 700, 400, 800),
                'label_weight' => $this->nestedInt($template, 'recipient_block.label_weight', 600, 400, 800),
                'value_weight' => $this->nestedInt($template, 'recipient_block.value_weight', 700, 400, 800),
                'label_color' => $this->nestedHexColor($template, 'recipient_block.label_color', '#4c4f47'),
                'value_color' => $this->nestedHexColor($template, 'recipient_block.value_color', $ink),
                'name_color' => $this->nestedHexColor($template, 'recipient_block.name_color', $accent),
                'alignment' => $this->nestedOption($template, 'recipient_block.alignment', 'left', ['left', 'center', 'right']),
                'padding_mm' => $this->nestedFloat($template, 'recipient_block.padding_mm', 0, 0, 12),
                'margin_top_mm' => $this->nestedFloat($template, 'recipient_block.margin_top_mm', 18, 0, 40),
                'margin_bottom_mm' => $this->nestedFloat($template, 'recipient_block.margin_bottom_mm', 0, 0, 20),
                'border_color' => $this->nestedHexColor($template, 'recipient_block.border_color', '#ffffff'),
                'border_width' => $this->nestedBorderWidth($template, 'recipient_block.border_width', '0pt'),
                'background_color' => $this->nestedHexColor($template, 'recipient_block.background_color', '#ffffff'),
                'show_address' => $this->nestedBoolean($template, 'recipient_block.show_address', true),
                'show_phone' => $this->nestedBoolean($template, 'recipient_block.show_phone', true),
                'show_email' => $this->nestedBoolean($template, 'recipient_block.show_email', true),
                'show_tax_identifier' => $this->nestedBoolean($template, 'recipient_block.show_tax_identifier', true),
                'width_percent' => $this->nestedInt($template, 'recipient_block.width_percent', 100, 30, 100),
                'row_spacing_mm' => $this->nestedFloat($template, 'recipient_block.row_spacing_mm', .7, 0, 5),
                'label_gap_mm' => $this->nestedFloat($template, 'recipient_block.label_gap_mm', 1, 0, 10),
                'heading_spacing_mm' => $this->nestedFloat($template, 'recipient_block.heading_spacing_mm', 1, 0, 10),
                'heading' => $this->resolvedTextGroup($template, 'recipient_block.heading', [
                    'font_family' => 'DejaVu Sans', 'font_size' => 8.8, 'font_weight' => 700,
                    'color' => '#4c4f47', 'line_height' => 1.2, 'alignment' => 'left',
                ], false),
                'name' => $this->resolvedTextGroup($template, 'recipient_block.name', [
                    'font_family' => 'DejaVu Sans', 'font_size' => 10.8, 'font_weight' => 700,
                    'color' => $accent, 'line_height' => 1.25, 'alignment' => 'left',
                ], false),
                'contact' => $this->resolvedTextGroup($template, 'recipient_block.contact', [
                    'font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 700,
                    'color' => $ink, 'line_height' => 1.42, 'alignment' => 'left',
                ], false),
                'labels' => $this->resolvedTextGroup($template, 'recipient_block.labels', [
                    'font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 600,
                    'color' => '#4c4f47', 'line_height' => 1.42, 'alignment' => 'left', 'width_percent' => 18,
                ], true),
                'values' => $this->resolvedTextGroup($template, 'recipient_block.values', [
                    'font_family' => 'DejaVu Sans', 'font_size' => 9.2, 'font_weight' => 700,
                    'color' => $ink, 'line_height' => 1.42, 'alignment' => 'left',
                ], false) + ['wrap' => $this->nestedBoolean($template, 'recipient_block.values.wrap', true)],
            ],
            'document_title' => $this->resolvedTypography($template, 'document_title', [
                'visible' => true, 'font_size' => 26, 'font_weight' => 700, 'color' => $ink,
                'letter_spacing_em' => .06, 'line_height' => 1.2, 'alignment' => 'center',
                'margin_top_mm' => 5, 'margin_bottom_mm' => 4,
            ]),
            'document_info' => [
                'visible' => $this->nestedBoolean($template, 'document_info.visible', true),
                'label_font_size' => $this->nestedFloat($template, 'document_info.label_font_size', 10.8, 8, 14),
                'value_font_size' => $this->nestedFloat($template, 'document_info.value_font_size', 9.4, 8, 12),
                'label_weight' => $this->nestedInt($template, 'document_info.label_weight', 700, 400, 800),
                'value_weight' => $this->nestedInt($template, 'document_info.value_weight', 600, 400, 800),
                'label_color' => $this->nestedHexColor($template, 'document_info.label_color', '#111111'),
                'value_color' => $this->nestedHexColor($template, 'document_info.value_color', $ink),
                'header_background' => $this->nestedHexColor($template, 'document_info.header_background', '#ffffff'),
                'value_background' => $this->nestedHexColor($template, 'document_info.value_background', '#ffffff'),
                'border_color' => $this->nestedHexColor($template, 'document_info.border_color', '#000000'),
                'border_width' => $this->nestedBorderWidth($template, 'document_info.border_width', '.85pt'),
                'padding_y' => $this->nestedFloat($template, 'document_info.padding_y', 4, 2, 8),
                'padding_x' => $this->nestedFloat($template, 'document_info.padding_x', 6, 3, 10),
                'margin_bottom_mm' => $this->nestedFloat($template, 'document_info.margin_bottom_mm', 3, 0, 15),
                'column_1_width' => $this->nestedInt($template, 'document_info.column_1_width', 20, 10, 50),
                'column_2_width' => $this->nestedInt($template, 'document_info.column_2_width', 28, 10, 50),
                'column_3_width' => $this->nestedInt($template, 'document_info.column_3_width', 32, 10, 50),
                'column_4_width' => $this->nestedInt($template, 'document_info.column_4_width', 20, 10, 50),
            ],
            'item_table' => [
                'header_background' => $this->nestedHexColor($template, 'item_table.header_background', $accent),
                'header_text' => $this->nestedHexColor($template, 'item_table.header_text', '#ffffff'),
                'header_font_size' => $this->nestedFloat($template, 'item_table.header_font_size', 7.9, 6.5, 10),
                'header_font_weight' => $this->nestedInt($template, 'item_table.header_font_weight', 700, 400, 800),
                'body_font_size' => $this->nestedFloat($template, 'item_table.body_font_size', 8.8, 7, 11),
                'body_text_color' => $this->nestedHexColor($template, 'item_table.body_text_color', $ink),
                'body_font_weight' => $this->nestedInt($template, 'item_table.body_font_weight', 400, 400, 800),
                'separator_color' => $lineColor,
                'separator_opacity' => $lineOpacity,
                'separator_thickness' => $lineThickness,
                'separator_style' => $this->nestedOption($template, 'item_table.separator_style', 'solid', ['solid', 'dashed', 'dotted']),
                'grid_mode' => $this->nestedOption($template, 'item_table.grid_mode', 'horizontal', ['horizontal', 'full', 'none']),
                'separator_css_color' => $this->blendedColor($lineColor, $lineOpacity),
                'separator_css_width' => $this->lineThicknessWidth($lineThickness),
                'padding_y' => $this->nestedFloat($template, 'item_table.padding_y', 3, 2, 7),
                'padding_x' => $this->nestedFloat($template, 'item_table.padding_x', 5, 3, 9),
                'row_height_mm' => $this->nestedFloat($template, 'item_table.row_height_mm', 6, 3, 12),
                'empty_row_height_mm' => $this->nestedFloat($template, 'item_table.empty_row_height_mm', 6, 3, 10),
            ],
            'totals' => [
                'visible' => $this->nestedBoolean($template, 'totals.visible', true),
                'width_percent' => $this->nestedInt($template, 'totals.width_percent', 45, 30, 100),
                'alignment' => $this->nestedOption($template, 'totals.alignment', 'right', ['left', 'center', 'right']),
                'background_color' => $this->nestedHexColor($template, 'totals.background_color', '#ffffff'),
                'grand_background_color' => $this->nestedHexColor($template, 'totals.grand_background_color', '#ffffff'),
                'label_color' => $this->nestedHexColor($template, 'totals.label_color', '#111111'),
                'amount_color' => $this->nestedHexColor($template, 'totals.amount_color', $ink),
                'label_weight' => $this->nestedInt($template, 'totals.label_weight', 600, 400, 800),
                'amount_weight' => $this->nestedInt($template, 'totals.amount_weight', 700, 400, 800),
                'grand_weight' => $this->nestedInt($template, 'totals.grand_weight', 800, 400, 800),
                'padding_y' => $this->nestedFloat($template, 'totals.padding_y', 4, 1, 10),
                'padding_x' => $this->nestedFloat($template, 'totals.padding_x', 6, 2, 14),
                'border_color' => $this->nestedHexColor($template, 'totals.border_color', '#000000'),
                'border_width' => $this->nestedBorderWidth($template, 'totals.border_width', '.85pt'),
                'label_font_size' => $this->nestedFloat($template, 'totals.label_font_size', 9.2, 7.5, 12),
                'amount_font_size' => $this->nestedFloat($template, 'totals.amount_font_size', 9.2, 7.5, 12),
                'grand_font_size' => $this->nestedFloat($template, 'totals.grand_font_size', 10.8, 9, 15),
            ],
            'amount_words' => [
                'visible' => $this->nestedBoolean($template, 'amount_words.visible', true),
                'alignment' => $this->nestedOption($template, 'amount_words.alignment', 'center', ['left', 'center', 'right']),
                'background_color' => $this->nestedHexColor($template, 'amount_words.background_color', '#ffffff'),
                'intro_color' => $this->nestedHexColor($template, 'amount_words.intro_color', '#111111'),
                'amount_color' => $this->nestedHexColor($template, 'amount_words.amount_color', $ink),
                'intro_weight' => $this->nestedInt($template, 'amount_words.intro_weight', 700, 400, 800),
                'amount_weight' => $this->nestedInt($template, 'amount_words.amount_weight', 800, 400, 800),
                'padding_y' => $this->nestedFloat($template, 'amount_words.padding_y', 8, 2, 18),
                'padding_x' => $this->nestedFloat($template, 'amount_words.padding_x', 10, 2, 20),
                'margin_top_mm' => $this->nestedFloat($template, 'amount_words.margin_top_mm', 3, 0, 15),
                'border_color' => $this->nestedHexColor($template, 'amount_words.border_color', '#000000'),
                'border_width' => $this->nestedBorderWidth($template, 'amount_words.border_width', '.85pt'),
                'intro_font_size' => $this->nestedFloat($template, 'amount_words.intro_font_size', 9.2, 7.5, 12),
                'amount_font_size' => $this->nestedFloat($template, 'amount_words.amount_font_size', 12, 9, 16),
            ],
            'footer' => [
                'visible' => $this->nestedBoolean($template, 'footer.visible', true),
                'font_size' => $this->nestedFloat($template, 'footer.font_size', 7.8, 6.5, 10),
                'text_color' => $this->nestedHexColor($template, 'footer.text_color', '#4f524a'),
                'border_color' => $this->nestedHexColor($template, 'footer.border_color', '#d7d7cf'),
                'border_width' => $this->nestedBorderWidth($template, 'footer.border_width', '.7pt'),
                'margin_top_mm' => $this->nestedFloat($template, 'footer.margin_top_mm', 0, 0, 10),
            ],
            'pagination' => [
                'visible' => $this->nestedBoolean($template, 'pagination.visible', true),
                'position' => $this->nestedOption($template, 'pagination.position', 'bottom', ['top', 'bottom']),
                'alignment' => $this->nestedOption($template, 'pagination.alignment', 'right', ['left', 'center', 'right']),
                'font_family' => $this->nestedOption($template, 'pagination.font_family', 'DejaVu Sans', ['DejaVu Sans', 'Helvetica']),
                'font_size' => $this->nestedFloat($template, 'pagination.font_size', 7, 6, 11),
                'color' => $this->nestedHexColor($template, 'pagination.color', '#999999'),
                'margin_x_mm' => $this->nestedFloat($template, 'pagination.margin_x_mm', 13, 5, 30),
                'margin_y_mm' => $this->nestedFloat($template, 'pagination.margin_y_mm', 8, 4, 20),
            ],
            'stamp' => [
                'visible' => $this->nestedBoolean($template, 'stamp.visible', true),
                'position_anchor' => $this->nestedOption($template, 'stamp.position_anchor', 'bottom_left', ['bottom_left', 'bottom_right', 'top_left', 'top_right']),
                'offset_x_mm' => $this->nestedFloat($template, 'stamp.offset_x_mm', 0, 0, 120),
                'offset_y_mm' => $this->nestedFloat($template, 'stamp.offset_y_mm', 5, 0, 180),
                'display_width_mm' => $this->nestedFloat($template, 'stamp.display_width_mm', 35, 20, 100),
                'display_height_mm' => $this->nestedNullableFloat($template, 'stamp.display_height_mm', null, 10, 100),
                'rotation_deg' => $this->nestedFloat($template, 'stamp.rotation_deg', 0, -20, 20),
                'opacity' => $this->nestedInt($template, 'stamp.opacity', 100, 10, 100),
                'preserve_aspect_ratio' => $this->nestedBoolean($template, 'stamp.preserve_aspect_ratio', true),
            ],
        ];
    }

    /** @param array<string, mixed> $template */
    private function nestedNullableFloat(array $template, string $key, ?float $default, float $min, float $max): ?float
    {
        $value = data_get($template, $key);
        if ($value === null || $value === '') {
            return $default;
        }

        return is_numeric($value) ? round(max($min, min($max, (float) $value)), 2) : $default;
    }

    /** @param array<string, mixed> $template */
    private function nestedBoolean(array $template, string $key, bool $default): bool
    {
        $value = data_get($template, $key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @param array<string, mixed> $template */
    private function nestedHexColor(array $template, string $key, string $default): string
    {
        $value = strtolower(trim((string) data_get($template, $key, '')));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : $default;
    }

    /** @param array<string, mixed> $template */
    private function nestedFloat(array $template, string $key, float $default, float $min, float $max): float
    {
        $value = data_get($template, $key);
        if (! is_numeric($value)) {
            return $default;
        }

        return round(max($min, min($max, (float) $value)), 2);
    }

    /** @param array<string, mixed> $template */
    private function nestedInt(array $template, string $key, int $default, int $min, int $max): int
    {
        $value = data_get($template, $key);
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<int, string>  $allowed
     */
    private function nestedOption(array $template, string $key, string $default, array $allowed): string
    {
        $value = (string) data_get($template, $key, $default);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** @param array<string, mixed> $template */
    private function nestedBorderWidth(array $template, string $key, string $default): string
    {
        $value = (string) data_get($template, $key, $default);

        return in_array($value, ['0pt', '.45pt', '.7pt', '.85pt', '.95pt', '1.1pt', '1.4pt'], true) ? $value : $default;
    }

    /** @param array<string, mixed> $template @param array<string, mixed> $defaults */
    private function resolvedTypography(array $template, string $prefix, array $defaults): array
    {
        return [
            'visible' => $this->nestedBoolean($template, $prefix.'.visible', $defaults['visible']),
            'font_family' => $this->nestedOption($template, $prefix.'.font_family', 'DejaVu Sans', ['DejaVu Sans', 'Helvetica']),
            'font_size' => $this->nestedFloat($template, $prefix.'.font_size', $defaults['font_size'], 7, 36),
            'font_weight' => $this->nestedInt($template, $prefix.'.font_weight', $defaults['font_weight'], 400, 800),
            'color' => $this->nestedHexColor($template, $prefix.'.color', $defaults['color']),
            'letter_spacing_em' => $this->nestedFloat($template, $prefix.'.letter_spacing_em', $defaults['letter_spacing_em'], 0, .2),
            'line_height' => $this->nestedFloat($template, $prefix.'.line_height', $defaults['line_height'], 1, 2),
            'alignment' => $this->nestedOption($template, $prefix.'.alignment', $defaults['alignment'], ['left', 'center', 'right']),
            'margin_top_mm' => $this->nestedFloat($template, $prefix.'.margin_top_mm', $defaults['margin_top_mm'], 0, 30),
            'margin_bottom_mm' => $this->nestedFloat($template, $prefix.'.margin_bottom_mm', $defaults['margin_bottom_mm'], 0, 30),
        ];
    }

    /** @param array<string, mixed> $template @param array<string, mixed> $defaults */
    private function resolvedTextGroup(array $template, string $prefix, array $defaults, bool $withWidth): array
    {
        $group = [
            'font_family' => $this->nestedOption($template, $prefix.'.font_family', $defaults['font_family'], ['DejaVu Sans', 'Helvetica']),
            'font_size' => $this->nestedFloat($template, $prefix.'.font_size', $defaults['font_size'], 6, 20),
            'font_weight' => $this->nestedInt($template, $prefix.'.font_weight', $defaults['font_weight'], 400, 800),
            'color' => $this->nestedHexColor($template, $prefix.'.color', $defaults['color']),
            'line_height' => $this->nestedFloat($template, $prefix.'.line_height', $defaults['line_height'], 1, 2),
            'alignment' => $this->nestedOption($template, $prefix.'.alignment', $defaults['alignment'], ['left', 'center', 'right']),
        ];

        if ($withWidth) {
            $group['width_percent'] = $this->nestedInt($template, $prefix.'.width_percent', $defaults['width_percent'], 10, 50);
        }

        return $group;
    }

    private function blendedColor(string $hex, int $opacity): string
    {
        $alpha = max(0, min(100, $opacity)) / 100;
        $red = hexdec(substr($hex, 1, 2));
        $green = hexdec(substr($hex, 3, 2));
        $blue = hexdec(substr($hex, 5, 2));

        $blend = fn (int $channel): int => (int) round(($channel * $alpha) + (255 * (1 - $alpha)));

        return sprintf('#%02x%02x%02x', $blend($red), $blend($green), $blend($blue));
    }

    /**
     * Read the currently configured logo and return it as a `data:` URI so the
     * snapshot is portable and the PDF renderer needs no filesystem access.
     *
     * @param  array<string, mixed>  $profile
     */
    private function embedLogo(array $profile): ?string
    {
        $path = trim((string) ($profile['logo_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
        if ($mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    /** @param array<string, mixed> $profile */
    private function value(array $profile, string $key): ?string
    {
        $value = trim((string) ($profile[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
