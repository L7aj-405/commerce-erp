@php
    $style = $seller['pdf_style'] ?? [];
    $blend = static function (string $hex, int $opacity): string {
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) return '#000000';
        $alpha = max(0, min(100, $opacity)) / 100;
        $channels = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        return sprintf('#%02x%02x%02x', ...array_map(fn ($channel) => (int) round($channel * $alpha + 255 * (1 - $alpha)), $channels));
    };
    $itemLineColor = data_get($style, 'item_table.separator_css_color', $seller['item_line_css_color'] ?? '#c6c6c6');
    $itemLineWidth = data_get($style, 'item_table.separator_css_width', $seller['item_line_css_width'] ?? '.45pt');
    $bodyFontSize = data_get($style, 'page.font_size', 9.5);
    $bodyLineHeight = data_get($style, 'page.line_height', 1.43);
    $bodyTextColor = data_get($style, 'page.text_color', $ink ?? '#1f211d');
    $headerVisible = filter_var(data_get($style, 'document_header.visible', $seller['show_document_header'] ?? true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    $pageTopMargin = $headerVisible
        ? data_get($style, 'page.margin_top_mm', 20) + data_get($style, 'document_header.margin_bottom_mm', 0)
        : min(13, data_get($style, 'page.margin_top_mm', 20));
    $pageRightMargin = data_get($style, 'page.margin_right_mm', 13);
    $pageBottomMargin = data_get($style, 'page.margin_bottom_mm', 30);
    $pageLeftMargin = data_get($style, 'page.margin_left_mm', 13);
    $logoVisible = filter_var(data_get($style, 'logo.visible', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    $logoMaxWidth = data_get($style, 'logo.max_width_mm', 79);
    $logoMaxHeight = data_get($style, 'logo.max_height_mm', 26);
    $logoMarginBottom = data_get($style, 'logo.margin_bottom_mm', 4);
    $logoAlignment = data_get($style, 'logo.alignment', 'left');
    $logoPreserveAspect = filter_var(data_get($style, 'logo.preserve_aspect_ratio', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    $runheadFontSize = data_get($style, 'document_header.font_size', 8.5);
    $runheadTextColor = data_get($style, 'document_header.text_color', '#3f423b');
    $runheadBorderColor = data_get($style, 'document_header.border_color', '#d7d7cf');
    $runheadBorderWidth = data_get($style, 'document_header.border_width', '.7pt');
    $runheadHeight = data_get($style, 'document_header.height_mm', 12);
    $footerFontSize = data_get($style, 'footer.font_size', 7.8);
    $footerTextColor = data_get($style, 'footer.text_color', '#4f524a');
    $footerBorderColor = data_get($style, 'footer.border_color', '#d7d7cf');
    $footerBorderWidth = data_get($style, 'footer.border_width', '.7pt');
    $footerMarginTop = data_get($style, 'footer.margin_top_mm', 0);
    $footerVisible = filter_var(data_get($style, 'footer.visible', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    $companyFontSize = data_get($style, 'company_block.font_size', 9.2);
    $companyLabelColor = data_get($style, 'company_block.label_color', '#4c4f47');
    $companyValueColor = data_get($style, 'company_block.value_color', $ink ?? '#1f211d');
    $companyLabelWeight = data_get($style, 'company_block.label_weight', 600);
    $companyValueWeight = data_get($style, 'company_block.value_weight', 700);
    $companyVisible = data_get($style, 'company_block.visible', true);
    $companyLineHeight = data_get($style, 'company_block.line_height', 1.42);
    $companyAlignment = data_get($style, 'company_block.alignment', 'left');
    $companyPadding = data_get($style, 'company_block.padding_mm', 0);
    $companyMarginTop = data_get($style, 'company_block.margin_top_mm', 0);
    $companyMarginBottom = data_get($style, 'company_block.margin_bottom_mm', 0);
    $companyBorder = data_get($style, 'company_block.border_width', '0pt').' solid '.data_get($style, 'company_block.border_color', '#ffffff');
    $companyBackground = data_get($style, 'company_block.background_color', '#ffffff');
    $companyName = data_get($style, 'company_name', []);
    $recipientFontSize = data_get($style, 'recipient_block.font_size', 9.2);
    $recipientTitleSize = data_get($style, 'recipient_block.title_size', 8.8);
    $recipientNameSize = data_get($style, 'recipient_block.name_size', 10.8);
    $recipientLabelColor = data_get($style, 'recipient_block.label_color', '#4c4f47');
    $recipientValueColor = data_get($style, 'recipient_block.value_color', $ink ?? '#1f211d');
    $recipientVisible = data_get($style, 'recipient_block.visible', true);
    $recipientContactSize = data_get($style, 'recipient_block.contact_size', 9.2);
    $recipientAlignment = data_get($style, 'recipient_block.alignment', 'left');
    $recipientPadding = data_get($style, 'recipient_block.padding_mm', 0);
    $recipientMarginTop = data_get($style, 'recipient_block.margin_top_mm', 18);
    $recipientMarginBottom = data_get($style, 'recipient_block.margin_bottom_mm', 0);
    $recipientBorder = data_get($style, 'recipient_block.border_width', '0pt').' solid '.data_get($style, 'recipient_block.border_color', '#ffffff');
    $recipientBackground = data_get($style, 'recipient_block.background_color', '#ffffff');
    $documentTitle = data_get($style, 'document_title', []);
    $metaLabelFontSize = data_get($style, 'document_info.label_font_size', 10.8);
    $metaValueFontSize = data_get($style, 'document_info.value_font_size', 9.4);
    $metaBorderColor = $blend(data_get($style, 'document_info.border_color', '#000000'), data_get($style, 'document_info.border_opacity', 100));
    $metaBorderWidth = data_get($style, 'document_info.border_width', '.85pt');
    $metaPaddingY = data_get($style, 'document_info.padding_y', 4);
    $metaPaddingX = data_get($style, 'document_info.padding_x', 6);
    $metaVisible = data_get($style, 'document_info.visible', true);
    $itemHeaderBackground = data_get($style, 'item_table.header_background', $accent);
    $itemHeaderText = data_get($style, 'item_table.header_text', '#ffffff');
    $itemHeaderFontSize = data_get($style, 'item_table.header_font_size', 7.9);
    $itemBodyFontSize = data_get($style, 'item_table.body_font_size', 8.8);
    $itemHeaderWeight = data_get($style, 'item_table.header_font_weight', 700);
    $itemBodyColor = data_get($style, 'item_table.body_text_color', $bodyTextColor);
    $itemBodyWeight = data_get($style, 'item_table.body_font_weight', 400);
    $itemPaddingY = data_get($style, 'item_table.padding_y', 3);
    $itemPaddingX = data_get($style, 'item_table.padding_x', 5);
    $itemRowHeight = data_get($style, 'item_table.row_height_mm', 6);
    $emptyRowHeight = data_get($style, 'item_table.empty_row_height_mm', 6);
    $separatorStyle = data_get($style, 'item_table.separator_style', 'solid');
    $gridMode = data_get($style, 'item_table.grid_mode', 'horizontal');
    $totalsBorderColor = $blend(data_get($style, 'totals.border_color', '#000000'), data_get($style, 'totals.border_opacity', 100));
    $totalsBorderWidth = data_get($style, 'totals.border_width', '.85pt');
    $totalsLabelFontSize = data_get($style, 'totals.label_font_size', 9.2);
    $totalsAmountFontSize = data_get($style, 'totals.amount_font_size', 9.2);
    $totalsGrandFontSize = data_get($style, 'totals.grand_font_size', 10.8);
    $totalsVisible = data_get($style, 'totals.visible', true);
    $wordsBorderColor = $blend(data_get($style, 'amount_words.border_color', '#000000'), data_get($style, 'amount_words.border_opacity', 100));
    $wordsBorderWidth = data_get($style, 'amount_words.border_width', '.85pt');
    $wordsIntroFontSize = data_get($style, 'amount_words.intro_font_size', 9.2);
    $wordsAmountFontSize = data_get($style, 'amount_words.amount_font_size', 12);
    $wordsVisible = filter_var(data_get($style, 'amount_words.visible', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
@endphp
@page { margin: {{ $pageTopMargin }}mm {{ $pageRightMargin }}mm {{ $pageBottomMargin }}mm {{ $pageLeftMargin }}mm; }
body { color: {{ $bodyTextColor }}; font-family: "DejaVu Sans", sans-serif; font-size: {{ $bodyFontSize }}px; line-height: {{ $bodyLineHeight }}; }
.runhead { position: fixed; top: -15mm; left: 0; right: 0; height: {{ $runheadHeight }}mm; border-bottom: {{ $runheadBorderWidth }} solid {{ $runheadBorderColor }}; font-size: {{ $runheadFontSize }}px; color: {{ $runheadTextColor }}; }
.runhead .r1 { display: block; }
.runhead .r1 .who { font-weight: bold; color: {{ $bodyTextColor }}; text-transform: uppercase; }
.runhead .r1 .doc { float: right; font-weight: bold; color: {{ $accent }}; }
.runhead .r2 { display: block; margin-top: 2px; }
.runhead .r2 .sep { color: #9ca096; }
.runfoot, .footer { border-top: {{ $footerBorderWidth }} solid {{ $footerBorderColor }}; margin-top: {{ $footerMarginTop }}mm; padding-top: 4px; font-size: {{ $footerFontSize }}px; font-style: italic; color: {{ $footerTextColor }}; text-align: center; line-height: 1.45; }
@if (! $footerVisible)
.runfoot, .footer { display: none; }
@endif
.watermark-text { position: fixed; top: 40%; left: 12%; font-size: 90px; font-weight: bold; color: #f6e9e7; transform: rotate(-26deg); }
.watermark-logo { position: fixed; top: 0; left: 0; right: 0; text-align: center; z-index: -1; }
.watermark-logo img { width: 95mm; margin-top: 118mm; opacity: 0.05; }
.masthead, table.masthead { width: 100%; border-collapse: collapse; }
.masthead td, table.masthead td { vertical-align: top; }
.logo { min-width: 0; max-height: {{ $logoMaxHeight }}mm; max-width: {{ $logoMaxWidth }}mm; margin-bottom: {{ $logoMarginBottom }}mm; {{ $logoPreserveAspect ? 'height: auto;' : 'width: '.$logoMaxWidth.'mm; height: '.$logoMaxHeight.'mm;' }} }
.masthead td:first-child { text-align: {{ $logoAlignment }}; }
.masthead .seller { text-align: left; }
@if (! $logoVisible)
.masthead .logo { display: none; }
@endif
.company-name { display: {{ data_get($companyName, 'visible', true) ? 'block' : 'none' }}; width: {{ data_get($companyName, 'width_percent', 100) }}%; font-family: "{{ data_get($companyName, 'font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($companyName, 'font_size', 17) }}px; font-weight: {{ data_get($companyName, 'font_weight', 700) }}; color: {{ data_get($companyName, 'color', $bodyTextColor) }}; letter-spacing: {{ data_get($companyName, 'letter_spacing_em', 0) }}em; line-height: {{ data_get($companyName, 'line_height', 1.2) }}; text-transform: {{ data_get($companyName, 'text_transform', 'none') }}; text-align: {{ data_get($companyName, 'alignment', 'left') }}; margin: {{ data_get($companyName, 'margin_top_mm', 0) }}mm {{ data_get($companyName, 'margin_right_mm', 0) }}mm {{ data_get($companyName, 'margin_bottom_mm', 2) }}mm {{ data_get($companyName, 'margin_left_mm', 0) + data_get($companyName, 'horizontal_offset_mm', 0) }}mm; }
@if (data_get($companyName, 'position') === 'beside_logo')
.masthead .logo, .top .logo { display: inline-block; vertical-align: middle; margin-right: {{ data_get($companyName, 'logo_spacing_mm', 2) }}mm; }
.masthead .company-name, .top .company-name { display: inline-block; vertical-align: middle; }
@endif
.seller { display: {{ $companyVisible ? 'block' : 'none' }}; width: {{ data_get($style, 'company_block.width_percent', 100) }}%; color: #343730; font-size: {{ $companyFontSize }}px; line-height: {{ $companyLineHeight }}; text-align: {{ $companyAlignment }}; padding: {{ $companyPadding }}mm; margin: {{ $companyMarginTop }}mm 0 {{ $companyMarginBottom }}mm; border: {{ $companyBorder }}; background: {{ $companyBackground }}; }
.info-label { font-weight: {{ $companyLabelWeight }}; color: {{ $companyLabelColor }}; }
.info-value { font-weight: {{ $companyValueWeight }}; color: {{ $companyValueColor }}; }
.info-kv { width: 100%; border: 0; border-collapse: collapse; border-spacing: 0; table-layout: fixed; }
.info-kv tr, .info-kv td { border: 0 !important; background: transparent !important; }
.info-kv td { height: auto !important; vertical-align: top; padding-top: 0 !important; padding-left: 0 !important; padding-bottom: 0 !important; }
.company-info-table td { padding-bottom: {{ data_get($style, 'company_block.row_spacing_mm', .7) }}mm !important; }
.company-info-table tr:last-child td { padding-bottom: 0 !important; }
.company-info-table .info-label { width: {{ data_get($style, 'company_block.labels.width_percent', 18) }}%; padding-right: {{ data_get($style, 'company_block.label_gap_mm', 1) }}mm !important; font-family: "{{ data_get($style, 'company_block.labels.font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($style, 'company_block.labels.font_size', $companyFontSize) }}px; line-height: {{ data_get($style, 'company_block.labels.line_height', $companyLineHeight) }}; text-align: {{ data_get($style, 'company_block.labels.alignment', 'left') }}; font-weight: {{ data_get($style, 'company_block.labels.font_weight', $companyLabelWeight) }}; color: {{ data_get($style, 'company_block.labels.color', $companyLabelColor) }}; white-space: normal; overflow-wrap: break-word; }
.company-info-table .info-value { width: auto; padding-right: 0 !important; font-family: "{{ data_get($style, 'company_block.values.font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($style, 'company_block.values.font_size', $companyFontSize) }}px; line-height: {{ data_get($style, 'company_block.values.line_height', $companyLineHeight) }}; text-align: {{ data_get($style, 'company_block.values.alignment', 'left') }}; font-weight: {{ data_get($style, 'company_block.values.font_weight', $companyValueWeight) }}; color: {{ data_get($style, 'company_block.values.color', $companyValueColor) }}; {{ data_get($style, 'company_block.values.wrap', true) ? 'white-space: normal; overflow-wrap: break-word; word-wrap: break-word;' : 'white-space: nowrap;' }} }
.seller-identity { margin-bottom: {{ data_get($style, 'company_block.row_spacing_mm', .7) }}mm; }
.dest { display: {{ $recipientVisible ? 'block' : 'none' }}; width: {{ data_get($style, 'recipient_block.width_percent', 100) }}%; font-size: {{ $recipientFontSize }}px; line-height: 1.42; color: #343730; text-align: {{ $recipientAlignment }}; padding: {{ $recipientPadding }}mm; margin: {{ $recipientMarginTop }}mm 0 {{ $recipientMarginBottom }}mm; border: {{ $recipientBorder }}; background: {{ $recipientBackground }}; }
.dest .lbl { font-family: "{{ data_get($style, 'recipient_block.heading.font_family', 'DejaVu Sans') }}", sans-serif; font-weight: {{ data_get($style, 'recipient_block.heading.font_weight', 700) }}; font-size: {{ data_get($style, 'recipient_block.heading.font_size', $recipientTitleSize) }}px; line-height: {{ data_get($style, 'recipient_block.heading.line_height', 1.2) }}; text-align: {{ data_get($style, 'recipient_block.heading.alignment', 'left') }}; color: {{ data_get($style, 'recipient_block.heading.color', $recipientLabelColor) }}; margin-bottom: {{ data_get($style, 'recipient_block.heading_spacing_mm', 1) }}mm; }
.dest .name { font-family: "{{ data_get($style, 'recipient_block.name.font_family', 'DejaVu Sans') }}", sans-serif; font-weight: {{ data_get($style, 'recipient_block.name.font_weight', 700) }}; color: {{ data_get($style, 'recipient_block.name.color', data_get($style, 'recipient_block.name_color', $accent)) }}; font-size: {{ data_get($style, 'recipient_block.name.font_size', $recipientNameSize) }}px; line-height: {{ data_get($style, 'recipient_block.name.line_height', 1.25) }}; text-align: {{ data_get($style, 'recipient_block.name.alignment', 'left') }}; margin: 3px 0 2px; }
.dest-details { font-family: "{{ data_get($style, 'recipient_block.contact.font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($style, 'recipient_block.contact.font_size', $recipientContactSize) }}px; font-weight: {{ data_get($style, 'recipient_block.contact.font_weight', 700) }}; color: {{ data_get($style, 'recipient_block.contact.color', $recipientValueColor) }}; line-height: {{ data_get($style, 'recipient_block.contact.line_height', 1.42) }}; text-align: {{ data_get($style, 'recipient_block.contact.alignment', 'left') }}; }
.dest .recipient-contact { font-family: "{{ data_get($style, 'recipient_block.contact.font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($style, 'recipient_block.contact.font_size', $recipientContactSize) }}px; font-weight: {{ data_get($style, 'recipient_block.contact.font_weight', 700) }}; color: {{ data_get($style, 'recipient_block.contact.color', $recipientValueColor) }}; line-height: {{ data_get($style, 'recipient_block.contact.line_height', 1.42) }}; }
.recipient-info-table td { padding-bottom: {{ data_get($style, 'recipient_block.row_spacing_mm', .7) }}mm !important; }
.recipient-info-table tr:last-child td { padding-bottom: 0 !important; }
.recipient-info-table .info-label { width: {{ data_get($style, 'recipient_block.labels.width_percent', 18) }}%; padding-right: {{ data_get($style, 'recipient_block.label_gap_mm', 1) }}mm !important; font-family: "{{ data_get($style, 'recipient_block.labels.font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($style, 'recipient_block.labels.font_size', $recipientContactSize) }}px; line-height: {{ data_get($style, 'recipient_block.labels.line_height', 1.42) }}; text-align: {{ data_get($style, 'recipient_block.labels.alignment', 'left') }}; color: {{ data_get($style, 'recipient_block.labels.color', $recipientLabelColor) }}; font-weight: {{ data_get($style, 'recipient_block.labels.font_weight', 600) }}; white-space: normal; overflow-wrap: break-word; }
.recipient-info-table .info-value { width: auto; padding-right: 0 !important; font-family: "{{ data_get($style, 'recipient_block.values.font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($style, 'recipient_block.values.font_size', $recipientContactSize) }}px; line-height: {{ data_get($style, 'recipient_block.values.line_height', 1.42) }}; text-align: {{ data_get($style, 'recipient_block.values.alignment', 'left') }}; color: {{ data_get($style, 'recipient_block.values.color', $recipientValueColor) }}; font-weight: {{ data_get($style, 'recipient_block.values.font_weight', 700) }}; {{ data_get($style, 'recipient_block.values.wrap', true) ? 'white-space: normal; overflow-wrap: break-word; word-wrap: break-word;' : 'white-space: nowrap;' }} }
h1.title { display: {{ data_get($documentTitle, 'visible', true) ? 'block' : 'none' }}; width: {{ data_get($documentTitle, 'width_percent', 100) }}%; margin: {{ data_get($documentTitle, 'margin_top_mm', 5) }}mm {{ data_get($documentTitle, 'margin_right_mm', 0) }}mm {{ data_get($documentTitle, 'margin_bottom_mm', 4) }}mm {{ data_get($documentTitle, 'margin_left_mm', 0) }}mm; text-align: {{ data_get($documentTitle, 'alignment', 'center') }}; text-transform: {{ data_get($documentTitle, 'text_transform', 'none') }}; font-family: "{{ data_get($documentTitle, 'font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ data_get($documentTitle, 'font_size', 26) }}px; font-weight: {{ data_get($documentTitle, 'font_weight', 700) }}; letter-spacing: {{ data_get($documentTitle, 'letter_spacing_em', .06) }}em; line-height: {{ data_get($documentTitle, 'line_height', 1.2) }}; color: {{ data_get($documentTitle, 'color', $bodyTextColor) }}; }
table.meta { display: {{ $metaVisible ? 'table' : 'none' }}; width: {{ data_get($style, 'document_info.width_percent', 100) }}%; border-collapse: collapse; margin-top: {{ data_get($style, 'document_info.margin_top_mm', 0) }}mm; margin-bottom: {{ data_get($style, 'document_info.margin_bottom_mm', 3) }}mm; border: {{ data_get($style, 'document_info.outer_border', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; }
table.meta th { height: {{ data_get($style, 'document_info.row_height_mm', 8) }}mm; border-top: {{ data_get($style, 'document_info.inner_horizontal_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; border-bottom: {{ data_get($style, 'document_info.inner_horizontal_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; border-left: {{ data_get($style, 'document_info.inner_vertical_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; border-right: {{ data_get($style, 'document_info.inner_vertical_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; padding: {{ $metaPaddingY }}px {{ $metaPaddingX }}px; font-family: "{{ data_get($style, 'document_info.label_font_family', 'DejaVu Sans') }}", sans-serif; font-size: {{ $metaLabelFontSize }}px; font-weight: {{ data_get($style, 'document_info.label_weight', 700) }}; text-align: {{ data_get($style, 'document_info.label_alignment', 'center') }}; color: {{ data_get($style, 'document_info.label_color', '#111111') }}; background: {{ data_get($style, 'document_info.header_background', '#ffffff') }}; vertical-align: middle; }
table.meta td { height: {{ data_get($style, 'document_info.row_height_mm', 8) }}mm; border-top: {{ data_get($style, 'document_info.inner_horizontal_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; border-bottom: {{ data_get($style, 'document_info.inner_horizontal_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; border-left: {{ data_get($style, 'document_info.inner_vertical_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; border-right: {{ data_get($style, 'document_info.inner_vertical_borders', true) ? $metaBorderWidth.' '.data_get($style, 'document_info.border_style', 'solid').' '.$metaBorderColor : '0' }}; padding: {{ $metaPaddingY + 2 }}px {{ $metaPaddingX }}px; font-family: "{{ data_get($style, 'document_info.value_font_family', 'DejaVu Sans') }}", sans-serif; text-align: {{ data_get($style, 'document_info.value_alignment', 'center') }}; font-size: {{ $metaValueFontSize }}px; font-weight: {{ data_get($style, 'document_info.value_weight', 600) }}; color: {{ data_get($style, 'document_info.value_color', $bodyTextColor) }}; background: {{ data_get($style, 'document_info.value_background', '#ffffff') }}; vertical-align: middle; }
table.meta th:nth-child(1), table.meta td:nth-child(1) { width: {{ data_get($style, 'document_info.column_1_width', 20) }}% !important; }
table.meta th:nth-child(2), table.meta td:nth-child(2) { width: {{ data_get($style, 'document_info.column_2_width', 28) }}% !important; }
table.meta th:nth-child(3), table.meta td:nth-child(3) { width: {{ data_get($style, 'document_info.column_3_width', 32) }}% !important; }
table.meta th:nth-child(4), table.meta td:nth-child(4) { width: {{ data_get($style, 'document_info.column_4_width', 20) }}% !important; }
table.items { width: {{ data_get($style, 'item_table.width_percent', 100) }}%; margin-top: {{ data_get($style, 'item_table.margin_top_mm', 0) }}mm; margin-bottom: {{ data_get($style, 'item_table.margin_bottom_mm', 0) }}mm; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
table.items thead { display: table-header-group; }
table.items tr { page-break-inside: avoid; }
table.items { border: 0; }
table.items th { height: {{ data_get($style, 'item_table.header_height_mm', 7) }}mm; background: {{ $itemHeaderBackground }}; color: {{ $itemHeaderText }}; border-left: {{ data_get($style, 'item_table.vertical_borders', false) ? $itemLineWidth.' '.$separatorStyle.' '.$itemLineColor : '0' }}; border-right: {{ data_get($style, 'item_table.vertical_borders', false) ? $itemLineWidth.' '.$separatorStyle.' '.$itemLineColor : '0' }}; border-bottom: {{ data_get($style, 'item_table.horizontal_borders', true) ? $itemLineWidth.' '.$separatorStyle.' '.$itemLineColor : '0' }}; font-size: {{ $itemHeaderFontSize }}px; font-weight: {{ $itemHeaderWeight }}; text-transform: uppercase; letter-spacing: .03em; padding: {{ $itemPaddingY + 3 }}px {{ $itemPaddingX }}px; vertical-align: middle; }
table.items td { color: {{ $itemBodyColor }}; font-weight: {{ $itemBodyWeight }}; border-left: {{ data_get($style, 'item_table.vertical_borders', false) ? $itemLineWidth.' '.$separatorStyle.' '.$itemLineColor : '0' }}; border-right: {{ data_get($style, 'item_table.vertical_borders', false) ? $itemLineWidth.' '.$separatorStyle.' '.$itemLineColor : '0' }}; border-bottom: {{ data_get($style, 'item_table.horizontal_borders', true) ? $itemLineWidth.' '.$separatorStyle.' '.$itemLineColor : '0' }}; min-height: {{ $itemRowHeight }}mm; padding: {{ $itemPaddingY }}px {{ $itemPaddingX }}px; font-size: {{ $itemBodyFontSize }}px; line-height: 1.3; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
table.items th:nth-child(1), table.items td:nth-child(1) { width: {{ data_get($style, 'item_table.columns.reference', 10) }}% !important; }
table.items th:nth-child(2), table.items td:nth-child(2) { width: {{ data_get($style, 'item_table.columns.designation', 33) }}% !important; }
table.items th:nth-child(3), table.items td:nth-child(3) { width: {{ data_get($style, 'item_table.columns.presentation', 9) }}% !important; }
table.items th:nth-child(4), table.items td:nth-child(4) { width: {{ data_get($style, 'item_table.columns.quantity', 6) }}% !important; }
table.items th:nth-child(5), table.items td:nth-child(5) { width: {{ data_get($style, 'item_table.columns.unit_price', 11) }}% !important; }
table.items th:nth-child(6), table.items td:nth-child(6) { width: {{ data_get($style, 'item_table.columns.line_total', 11) }}% !important; }
@if ($has_discount ?? false)
table.items th:nth-child(7), table.items td:nth-child(7) { width: {{ data_get($style, 'item_table.columns.discount', 9) }}% !important; }
table.items th:nth-child(8), table.items td:nth-child(8) { width: {{ data_get($style, 'item_table.columns.total', 11) }}% !important; }
@else
table.items th:nth-child(7), table.items td:nth-child(7) { width: {{ data_get($style, 'item_table.columns.total', 11) }}% !important; }
@endif
table.items tr.empty-ruled-row td { height: {{ $emptyRowHeight }}mm; padding-top: 0; padding-bottom: 0; color: transparent; }
.num { text-align: right; white-space: nowrap; }
.ctr { text-align: center; }
.des-sub, .small { color: #555950; font-size: 8.2px; }
.notes { margin-top: 12px; white-space: pre-line; page-break-inside: avoid; }
table.totals { display: {{ $totalsVisible ? 'table' : 'none' }}; width: {{ data_get($style, 'totals.width_percent', 45) }}%; margin-top: {{ data_get($style, 'totals.margin_top_mm', 0) }}mm; margin-bottom: {{ data_get($style, 'totals.margin_bottom_mm', 0) }}mm; margin-left: {{ data_get($style, 'totals.alignment', 'right') === 'right' ? 'auto' : '0' }}; margin-right: {{ data_get($style, 'totals.alignment', 'right') === 'left' ? 'auto' : (data_get($style, 'totals.alignment', 'right') === 'center' ? 'auto' : '0') }}; border-collapse: collapse; border: {{ data_get($style, 'totals.outer_border', true) ? $totalsBorderWidth.' '.data_get($style, 'totals.border_style', 'solid').' '.$totalsBorderColor : '0' }}; background: {{ data_get($style, 'totals.background_color', '#ffffff') }}; }
.closing-inner > tbody > tr > td:first-child { width: {{ 100 - data_get($style, 'totals.width_percent', 45) }}% !important; }
.closing-inner > tbody > tr > td:last-child { width: {{ data_get($style, 'totals.width_percent', 45) }}% !important; }
.closing-inner table.totals { width: 100%; }
table.totals td { height: {{ data_get($style, 'totals.row_height_mm', 6) }}mm; border-top: {{ data_get($style, 'totals.horizontal_borders', true) ? $totalsBorderWidth.' '.data_get($style, 'totals.border_style', 'solid').' '.$totalsBorderColor : '0' }}; border-bottom: {{ data_get($style, 'totals.horizontal_borders', true) ? $totalsBorderWidth.' '.data_get($style, 'totals.border_style', 'solid').' '.$totalsBorderColor : '0' }}; padding: {{ data_get($style, 'totals.padding_y', 4) }}px {{ data_get($style, 'totals.padding_x', 6) }}px; font-size: {{ $totalsLabelFontSize }}px; vertical-align: middle; }
table.totals .lbl { width: {{ data_get($style, 'totals.label_column_width', 56) }}%; border-right: {{ data_get($style, 'totals.vertical_separator', true) ? $totalsBorderWidth.' '.data_get($style, 'totals.border_style', 'solid').' '.$totalsBorderColor : '0' }}; text-align: left; color: {{ data_get($style, 'totals.label_color', '#111111') }}; font-weight: {{ data_get($style, 'totals.label_weight', 600) }}; }
table.totals .val { width: {{ data_get($style, 'totals.amount_column_width', 44) }}%; text-align: right; white-space: nowrap; color: {{ data_get($style, 'totals.amount_color', $bodyTextColor) }}; font-weight: {{ data_get($style, 'totals.amount_weight', 700) }}; font-size: {{ $totalsAmountFontSize }}px; }
table.totals tr.strong td { font-weight: 700; }
table.totals tr.grand .lbl, table.totals tr.total .lbl { color: {{ data_get($style, 'totals.final_label_color', '#111111') }}; font-size: {{ data_get($style, 'totals.final_label_font_size', $totalsGrandFontSize) }}px; }
table.totals tr.grand .val, table.totals tr.total .val { color: {{ data_get($style, 'totals.final_amount_color', $bodyTextColor) }}; font-size: {{ data_get($style, 'totals.final_amount_font_size', $totalsGrandFontSize) }}px; }
table.totals tr.grand td, table.totals tr.total td { border-top: 1.1pt solid {{ $totalsBorderColor }}; font-weight: {{ data_get($style, 'totals.grand_weight', 800) }}; background: {{ data_get($style, 'totals.grand_background_color', '#ffffff') }}; }
.words-box { width: {{ data_get($style, 'amount_words.width_percent', 100) }}%; border: {{ data_get($style, 'amount_words.border_visible', true) ? $wordsBorderWidth.' solid '.$wordsBorderColor : '0' }}; padding: {{ data_get($style, 'amount_words.padding_y', 8) }}px {{ data_get($style, 'amount_words.padding_x', 10) }}px; margin-top: {{ data_get($style, 'amount_words.margin_top_mm', 3) }}mm; margin-bottom: {{ data_get($style, 'amount_words.margin_bottom_mm', 0) }}mm; text-align: {{ data_get($style, 'amount_words.alignment', 'center') }}; background: {{ data_get($style, 'amount_words.background_color', '#ffffff') }}; page-break-inside: avoid; }
@if (! $wordsVisible)
.words-box { display: none; }
@endif
.words-rule { display: none; }
.words-intro { font-family: "{{ data_get($style, 'amount_words.intro_font_family', 'DejaVu Sans') }}", sans-serif; font-weight: {{ data_get($style, 'amount_words.intro_weight', 700) }}; font-size: {{ $wordsIntroFontSize }}px; color: {{ data_get($style, 'amount_words.intro_color', '#111111') }}; text-align: {{ data_get($style, 'amount_words.intro_alignment', 'center') }}; line-height: {{ data_get($style, 'amount_words.intro_line_height', 1.3) }}; letter-spacing: {{ data_get($style, 'amount_words.intro_letter_spacing_em', 0) }}em; }
.words-value { margin-top: 7px; font-family: "{{ data_get($style, 'amount_words.amount_font_family', 'DejaVu Sans') }}", sans-serif; font-style: italic; font-weight: {{ data_get($style, 'amount_words.amount_weight', 800) }}; font-size: {{ $wordsAmountFontSize }}px; text-transform: uppercase; color: {{ data_get($style, 'amount_words.amount_color', $bodyTextColor) }}; text-align: {{ data_get($style, 'amount_words.amount_alignment', 'center') }}; line-height: {{ data_get($style, 'amount_words.amount_line_height', 1.35) }}; letter-spacing: {{ data_get($style, 'amount_words.amount_letter_spacing_em', 0) }}em; }
@if (! data_get($style, 'company_block.show_address', true)) .seller-address { display: none; } @endif
@if (! data_get($style, 'company_block.show_tax_identifier', true)) .seller-tax { display: none; } @endif
@if (! data_get($style, 'company_block.show_phone', true)) .seller-phone { display: none; } @endif
@if (! data_get($style, 'company_block.show_email', true)) .seller-email { display: none; } @endif
@if (! data_get($style, 'company_block.show_registration_number', true)) .seller-registration { display: none; } @endif
@if (! data_get($style, 'company_block.show_patente_number', true)) .seller-patente { display: none; } @endif
@if (! data_get($style, 'company_block.show_additional_identifiers', true)) .seller-additional { display: none; } @endif
@if (! data_get($style, 'recipient_block.show_address', true)) .recipient-address { display: none; } @endif
@if (! data_get($style, 'recipient_block.show_phone', true)) .recipient-phone { display: none; } @endif
@if (! data_get($style, 'recipient_block.show_email', true)) .recipient-email { display: none; } @endif
@if (! data_get($style, 'recipient_block.show_tax_identifier', true)) .recipient-tax { display: none; } @endif
.issued-meta { margin: 12px 0 0; color: #666a60; font-size: 8.2px; text-align: left; }
