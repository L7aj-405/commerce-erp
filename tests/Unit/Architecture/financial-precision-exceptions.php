<?php

/**
 * Documented exceptions to the financial-precision architecture test (FinancialPrecisionTest).
 *
 * A key is `<repo-relative file>::<Class::method>::<kind>`. An entry means: "this method of the core financial code uses
 * float arithmetic or a float conversion, and a reviewer accepted why it is NOT a monetary computation". Rules:
 *  - the reason must explain why the value never feeds a stored or displayed monetary calculation;
 *  - the test FAILS when an entry stops matching (the list cannot rot) and when new float usage appears (it cannot
 *    silently grow);
 *  - money is computed only with App\Support\Decimal / App\Support\Money (decimal strings, bcmath).
 */
$layout = 'Not money: millimetre/percentage layout values of the document-template studio (stamp offsets, sizes). Clamped and stored as layout numbers, never added to an amount.';
$count = 'Not money: sums the QUANTITY of transfer-request lines into a display-only unit count on the order page (no stored or financial effect). Debt: use InventoryQuantity for consistency.';
$f04 = 'KNOWN DEBT, Phase-1 finding F-04: last-resort fallback for SQLite sums returned in scientific notation (test driver only); MySQL returns exact decimals and takes the exact branch above it. To be replaced by exact parsing in a later step; do not copy this pattern.';
$xlsx = 'Output boundary only: the already-computed exact decimal string is converted ONCE to a float because an XLSX numeric cell stores a double. No arithmetic is performed on the float and it is never read back into a calculation.';
$label = 'Display label only: formats a commission tier bound as text (e.g. "12.5 %") for an export header; the float is discarded after formatting.';

return [
    'app/Http/Controllers/Documents/DocumentTemplateStudioController.php::App\\Http\\Controllers\\Documents\\DocumentTemplateStudioController::edit::float-cast' => $layout,
    'app/Http/Controllers/Documents/DocumentTemplateStudioController.php::App\\Http\\Controllers\\Documents\\DocumentTemplateStudioController::float::float-cast' => $layout,
    'app/Http/Controllers/Documents/DocumentTemplateStudioController.php::App\\Http\\Controllers\\Documents\\DocumentTemplateStudioController::float::round' => $layout,
    'app/Http/Controllers/Documents/DocumentTemplateStudioController.php::App\\Http\\Controllers\\Documents\\DocumentTemplateStudioController::nullableFloat::float-cast' => $layout,
    'app/Http/Controllers/Documents/DocumentTemplateStudioController.php::App\\Http\\Controllers\\Documents\\DocumentTemplateStudioController::nullableFloat::round' => $layout,
    'app/Http/Controllers/Sales/SalesOrderController.php::App\\Http\\Controllers\\Sales\\SalesOrderController::show::float-cast' => $count,
    'app/Services/Commissions/CommissionDashboardService.php::App\\Services\\Commissions\\CommissionDashboardService::money::float-cast' => $f04,
    'app/Services/Finance/Export/CommissionReportExcelExport.php::App\\Services\\Finance\\Export\\CommissionReportExcelExport::number::float-cast' => $xlsx,
    'app/Services/Finance/Export/CommissionReportExcelExport.php::App\\Services\\Finance\\Export\\CommissionReportExcelExport::number::round' => $xlsx,
    'app/Services/Finance/Export/CommissionReportExcelExport.php::App\\Services\\Finance\\Export\\CommissionReportExcelExport::tierLabel::float-cast' => $label,
    'app/Services/Finance/Export/FinanceCaEncaisseExcelExport.php::App\\Services\\Finance\\Export\\FinanceCaEncaisseExcelExport::amount::float-cast' => $xlsx,
    'app/Services/Finance/Export/FinanceCaEncaisseExcelExport.php::App\\Services\\Finance\\Export\\FinanceCaEncaisseExcelExport::amount::round' => $xlsx,
    'app/Services/Finance/Export/FinanceCaEncaisseExcelExport.php::App\\Services\\Finance\\Export\\FinanceCaEncaisseExcelExport::quantity::float-cast' => $xlsx,
    'app/Services/Finance/Export/FinanceInvoiceExcelExport.php::App\\Services\\Finance\\Export\\FinanceInvoiceExcelExport::number::float-cast' => $xlsx,
    'app/Services/Finance/Export/FinanceInvoiceExcelExport.php::App\\Services\\Finance\\Export\\FinanceInvoiceExcelExport::number::round' => $xlsx,
    'app/Services/Finance/Export/FinanceMonthlySituationExcelExport.php::App\\Services\\Finance\\Export\\FinanceMonthlySituationExcelExport::money::float-cast' => $xlsx,
    'app/Services/Finance/Export/FinanceMonthlySituationExcelExport.php::App\\Services\\Finance\\Export\\FinanceMonthlySituationExcelExport::money::round' => $xlsx,
    'app/Services/Finance/Export/FinanceSituationExcelExport.php::App\\Services\\Finance\\Export\\FinanceSituationExcelExport::amount::float-cast' => $xlsx,
    'app/Services/Finance/Export/FinanceSituationExcelExport.php::App\\Services\\Finance\\Export\\FinanceSituationExcelExport::amount::round' => $xlsx,
];
