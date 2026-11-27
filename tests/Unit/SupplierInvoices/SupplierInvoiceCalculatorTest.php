<?php

namespace Tests\Unit\SupplierInvoices;

use App\Services\SupplierInvoices\SupplierInvoiceCalculator;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierInvoiceCalculatorTest extends TestCase
{
    private SupplierInvoiceCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new SupplierInvoiceCalculator;
    }

    private function line(string $qty, string $price, string $rate): array
    {
        return ['quantity' => $qty, 'unit_price_ht' => $price, 'tax_rate_percent' => $rate];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> */
    public static function lineCases(): array
    {
        return [
            'plain 20%' => ['2', '10.00', '20', '20.0000', '4.0000', '24.0000'],
            '0% rate' => ['3', '9.99', '0', '29.9700', '0.0000', '29.9700'],
            'zero price' => ['5', '0', '20', '0.0000', '0.0000', '0.0000'],
            'ht boundary .005 rounds up' => ['1', '0.005', '0', '0.0100', '0.0000', '0.0100'],
            'ht boundary 1.005' => ['1', '1.005', '0', '1.0100', '0.0000', '1.0100'],
            'ht just below boundary' => ['1', '1.0049', '0', '1.0000', '0.0000', '1.0000'],
            'exact product below boundary (no double rounding)' => ['1.0001', '0.4999', '0', '0.5000', '0.0000', '0.5000'],
            'exact 0.004995 stays down (no 4dp double rounding)' => ['0.0999', '0.05', '0', '0.0000', '0.0000', '0.0000'],
            'fractional qty' => ['0.3333', '10', '0', '3.3300', '0.0000', '3.3300'],
            'tva boundary .005 rounds up' => ['1', '0.25', '10', '0.2500', '0.0300', '0.2800'],
            'tva boundary 0.15*10%=0.015' => ['1', '0.15', '10', '0.1500', '0.0200', '0.1700'],
            'tva just below boundary' => ['1', '0.14', '10', '0.1400', '0.0100', '0.1500'],
            'rate 14%' => ['1', '33.33', '14', '33.3300', '4.6700', '38.0000'],
            'rate 8.5%' => ['10', '1.17', '8.5', '11.7000', '0.9900', '12.6900'],
            'rate 7% large' => ['1000', '123456.7891', '7', '123456789.1000', '8641975.2400', '132098764.3400'],
        ];
    }

    #[DataProvider('lineCases')]
    public function test_line_rounding_half_up(string $qty, string $price, string $rate, string $ht, string $tva, string $ttc): void
    {
        $this->assertSame(
            ['line_ht' => $ht, 'line_tva' => $tva, 'line_ttc' => $ttc],
            $this->calc->calculateLine($this->line($qty, $price, $rate)),
        );
    }

    public function test_multi_line_totals_are_sums_of_rounded_lines(): void
    {
        // Three lines of 0.005 HT each: each rounds to 0.01 (sum 0.03), NOT round(0.015)=0.02.
        $result = $this->calc->calculate([
            $this->line('1', '0.005', '0'), $this->line('1', '0.005', '0'), $this->line('1', '0.005', '0'),
        ]);

        $this->assertSame('0.0300', $result['subtotal_ht']);
        $this->assertSame('0.0000', $result['total_tva']);
        $this->assertSame('0.0300', $result['total_ttc']);
        $this->assertSame('0.0000', $result['rounding_adjustment']);
        $this->assertCount(3, $result['lines']);
    }

    public function test_mixed_rates_totals(): void
    {
        $result = $this->calc->calculate([
            $this->line('2', '10.00', '20'),   // 20.00 / 4.00 / 24.00
            $this->line('3', '9.99', '0'),     // 29.97 / 0 / 29.97
            $this->line('1', '33.33', '14'),   // 33.33 / 4.67 / 38.00
        ]);

        $this->assertSame('83.3000', $result['subtotal_ht']);
        $this->assertSame('8.6700', $result['total_tva']);
        $this->assertSame('91.9700', $result['total_ttc']);
    }

    public function test_empty_lines_total_zero(): void
    {
        $result = $this->calc->calculate([]);
        $this->assertSame('0.0000', $result['total_ttc']);
        $this->assertSame([], $result['lines']);
    }

    #[DataProvider('adjustmentCases')]
    public function test_adjustment_within_tolerance(string $adjustment, string $tolerance, string $expectedTotal): void
    {
        $result = $this->calc->calculate([$this->line('1', '100', '20')], $adjustment, $tolerance);

        $this->assertSame($expectedTotal, $result['total_ttc']);
        $this->assertSame(Decimal::normalize($adjustment), $result['rounding_adjustment']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function adjustmentCases(): array
    {
        return [
            'zero' => ['0', '0.05', '120.0000'],
            'positive edge' => ['0.05', '0.05', '120.0500'],
            'negative edge' => ['-0.05', '0.05', '119.9500'],
            'inside' => ['0.03', '0.05', '120.0300'],
            'custom tolerance 0.50' => ['-0.50', '0.50', '119.5000'],
            'zero tolerance accepts zero' => ['0', '0', '120.0000'],
        ];
    }

    #[DataProvider('overToleranceCases')]
    public function test_adjustment_over_tolerance_is_rejected(string $adjustment, string $tolerance): void
    {
        try {
            $this->calc->calculate([$this->line('1', '100', '20')], $adjustment, $tolerance);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rounding_adjustment', $e->errors());
            $this->assertStringContainsString('arrondi', mb_strtolower($e->errors()['rounding_adjustment'][0]));
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function overToleranceCases(): array
    {
        return [
            'positive just over' => ['0.0501', '0.05'],
            'negative just over' => ['-0.0501', '0.05'],
            'one dirham' => ['1', '0.05'],
            'custom tolerance' => ['0.51', '0.50'],
            'zero tolerance' => ['0.0001', '0'],
        ];
    }

    public function test_adjustment_cannot_make_total_negative(): void
    {
        $this->expectException(ValidationException::class);
        $this->calc->calculate([$this->line('1', '0.01', '0')], '-0.05', '0.05');
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_lines_are_rejected(array $line, string $errorKey): void
    {
        try {
            $this->calc->calculate([$line]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($errorKey, $e->errors());
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidLines(): array
    {
        $ok = ['quantity' => '1', 'unit_price_ht' => '1', 'tax_rate_percent' => '20'];

        return [
            'zero qty' => [[...$ok, 'quantity' => '0'], 'lines.0.quantity'],
            'negative qty' => [[...$ok, 'quantity' => '-1'], 'lines.0.quantity'],
            'negative price' => [[...$ok, 'unit_price_ht' => '-0.01'], 'lines.0.unit_price_ht'],
            'rate over 100' => [[...$ok, 'tax_rate_percent' => '100.0001'], 'lines.0.tax_rate_percent'],
            'negative rate' => [[...$ok, 'tax_rate_percent' => '-1'], 'lines.0.tax_rate_percent'],
            'five decimals' => [[...$ok, 'unit_price_ht' => '1.00001'], 'lines.0.unit_price_ht'],
            'garbage' => [[...$ok, 'quantity' => 'abc'], 'lines.0.quantity'],
            'float refused' => [[...$ok, 'quantity' => 1.5], 'lines.0.quantity'],
            'missing price' => [['quantity' => '1', 'tax_rate_percent' => '20'], 'lines.0.unit_price_ht'],
        ];
    }

    public function test_control_total_requires_exact_equality(): void
    {
        $this->assertTrue($this->calc->controlTotalMatches('120.00', '120.0000'));
        $this->assertFalse($this->calc->controlTotalMatches('120.01', '120.0000'));
        $this->assertFalse($this->calc->controlTotalMatches('119.9999', '120.0000'));

        $this->calc->assertControlTotal('120.00', '120.0000');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('badControlTotals')]
    public function test_control_total_mismatch_or_missing_is_rejected(?string $control): void
    {
        try {
            $this->calc->assertControlTotal($control, '120.0000');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('control_total_ttc', $e->errors());
        }
    }

    /** @return array<string, array{0: string|null}> */
    public static function badControlTotals(): array
    {
        return ['null' => [null], 'empty' => [''], 'one cent off' => ['120.01'], 'under' => ['119.99'], 'garbage' => ['x']];
    }
}
