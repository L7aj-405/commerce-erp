<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Architecture\Support\FloatPrecisionScan;

/**
 * Financial precision guard (Phase 1 / Step 1, Task C).
 *
 * Core financial code must never compute with floats: money is a decimal string handled by
 * App\Support\Decimal (bcmath) and App\Support\Money. This is a static (AST) check; it changes no
 * business logic and needs no database. Deliberate, reviewed exceptions live in
 * financial-precision-exceptions.php with a written reason.
 */
final class FinancialPrecisionTest extends TestCase
{
    private FloatPrecisionScan $scan;

    protected function setUp(): void
    {
        $this->scan = new FloatPrecisionScan;
    }

    public function test_scope_covers_the_money_code(): void
    {
        $core = $this->scan->coreFiles();

        $this->assertGreaterThan(150, count($core), 'The financial scope shrank unexpectedly — a directory was renamed?');
        foreach ([
            'app/Support/Decimal.php', 'app/Support/Money.php',
            'app/Actions/Payments/RecordPaymentAction.php', 'app/Actions/Payments/RefundPaymentAction.php',
            'app/Actions/Documents/IssueInvoiceAction.php', 'app/Actions/Returns/ReceiveCustomerReturnAction.php',
            'app/Services/SalesOrderPaymentCalculator.php', 'app/Services/CustomerFinancialSummary.php',
            'app/Services/Finance/FinanceReceivablesService.php', 'app/Services/Finance/FinancialAccountBalanceService.php',
        ] as $mustBeCovered) {
            $this->assertContains($mustBeCovered, $core, "{$mustBeCovered} must be inside the float-arithmetic guard.");
        }
    }

    public function test_the_detector_reports_unsafe_float_patterns_and_ignores_safe_code(): void
    {
        $findings = $this->scan->findings(['tests/Unit/Architecture/Fixtures/FloatFixtures.php']);
        $reported = array_map(fn (array $f) => substr($f['unit'], strrpos($f['unit'], '::') + 2).':'.$f['kind'], $findings);

        foreach (['unsafeCast:float-cast', 'unsafeFloatval:floatval', 'unsafeLiteralArithmetic:float-literal-arithmetic', 'unsafeRound:round', 'unsafeArraySum:array_sum'] as $expected) {
            $this->assertContains($expected, $reported, "The detector missed {$expected}.");
        }
        foreach ($reported as $finding) {
            $this->assertStringStartsWith('unsafe', $finding, "False positive: {$finding} is safe code.");
        }
    }

    public function test_core_financial_code_has_no_unreviewed_float_arithmetic(): void
    {
        $exceptions = $this->exceptions();
        $new = array_values(array_filter(
            $this->scan->findings($this->scan->coreFiles()),
            fn (array $f) => ! isset($exceptions[$f['key']]),
        ));

        $this->assertSame([], array_map(fn (array $f) => "{$f['file']}:{$f['line']} {$f['unit']} → {$f['kind']}", $new),
            'Float arithmetic in core financial code. Use App\\Support\\Decimal / Money (decimal strings). If the value is NOT money, '
            .'add a reasoned entry to tests/Unit/Architecture/financial-precision-exceptions.php.');
    }

    public function test_exception_list_is_documented_and_not_stale(): void
    {
        $exceptions = $this->exceptions();
        $current = array_column($this->scan->findings($this->scan->coreFiles()), 'key');

        foreach ($exceptions as $key => $reason) {
            $this->assertGreaterThanOrEqual(60, strlen((string) $reason), "Exception {$key} needs a real written reason.");
            $this->assertContains($key, $current, "Stale exception (no longer matches any float usage) — delete it: {$key}");
        }
    }

    /** @return array<string, string> */
    private function exceptions(): array
    {
        return require __DIR__.'/financial-precision-exceptions.php';
    }
}
