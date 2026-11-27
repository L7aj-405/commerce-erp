<?php

namespace Tests\Unit\SupplierInvoices;

use App\Services\SupplierInvoices\SupplierInvoiceDuplicateGuard as Guard;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierInvoiceDuplicateGuardTest extends TestCase
{
    private Guard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new Guard;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function normalizeCases(): array
    {
        return [
            'case' => ['fa-12a', 'FA12A'],
            'spaces' => ['  FA 12  ', 'FA12'],
            'dashes and slashes' => ['FA/2026-0012', 'FA20260012'],
            'dots and underscores' => ['F.A_12', 'FA12'],
            'leading zeros kept' => ['000123', '000123'],
            'leading zeros kept with prefix' => ['F-00012', 'F00012'],
            'fullwidth NFKC' => ['ＦＡ１２', 'FA12'],
            'ligature NFKC' => ['ﬁ12', 'FI12'],
            'accents kept, uppercased' => ['facture é1', 'FACTUREÉ1'],
            'arabic letters kept' => ['فاتورة 12', 'فاتورة12'],
            'arabic-indic digits NFKC kept as digits' => ['١٢٣', '١٢٣'],
            'only symbols' => ['--//  ', ''],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->guard->normalize($input));
    }

    public function test_normalization_collapses_equivalent_spellings(): void
    {
        $this->assertSame($this->guard->normalize('fa 2026/0012'), $this->guard->normalize('FA-2026-0012'));
        $this->assertNotSame($this->guard->normalize('0012'), $this->guard->normalize('12'));
    }

    public function test_key_uses_invoice_year_with_boundary(): void
    {
        $this->assertSame('FA12|2026', $this->guard->key('fa-12', '2026-12-31'));
        $this->assertSame('FA12|2027', $this->guard->key('fa-12', '2027-01-01'));
        $this->assertSame('FA12|2026', $this->guard->key('FA 12', CarbonImmutable::parse('2026-12-31 23:59:59')));
        $this->assertSame('000123|2026', $this->guard->key('000123', '2026-06-01'));
        $this->assertNotSame($this->guard->key('FA12', '2026-12-31'), $this->guard->key('FA12', '2027-01-01'));
    }

    public function test_key_rejects_number_without_letters_or_digits(): void
    {
        $this->expectException(ValidationException::class);
        $this->guard->key('---', '2026-01-01');
    }

    private function candidate(int $id, string $number, string $date, string $total): array
    {
        return ['id' => $id, 'invoice_number' => $number, 'invoice_date' => $date, 'total_ttc' => $total];
    }

    public function test_same_total_different_number_within_window(): void
    {
        $candidates = [
            $this->candidate(1, 'B-200', '2026-03-05', '120.0000'),   // match (4 days)
            $this->candidate(2, 'B-201', '2026-03-12', '120.0000'),   // 11 days: outside
            $this->candidate(3, 'B-202', '2026-03-03', '120.0100'),   // other total
            $this->candidate(4, 'A 100', '2026-03-03', '120.0000'),   // same number => hard-duplicate matter, not this warning
            $this->candidate(5, 'B-203', '2026-02-22', '120.0000'),   // exactly 7 days before: inside
            $this->candidate(6, 'B-204', '2026-02-21', '120.0000'),   // 8 days: outside
        ];

        $out = $this->guard->sameTotalDifferentNumber('A-100', '2026-03-01', '120.00', $candidates, 7);

        $this->assertSame([1, 5], array_column($out, 'invoice_id'));
        $this->assertSame(Guard::SAME_TOTAL, $out[0]['code']);
    }

    public function test_same_total_window_zero_only_same_day(): void
    {
        $candidates = [$this->candidate(1, 'X1', '2026-03-01', '5.0000'), $this->candidate(2, 'X2', '2026-03-02', '5.0000')];

        $this->assertSame([1], array_column($this->guard->sameTotalDifferentNumber('Y', '2026-03-01', '5', $candidates, 0), 'invoice_id'));
    }

    public function test_same_number_other_year(): void
    {
        $candidates = [
            $this->candidate(1, 'fa/12', '2025-12-31', '1.0000'),  // other year, same number
            $this->candidate(2, 'FA-12', '2026-01-01', '1.0000'),  // same year => hard duplicate, no warning
            $this->candidate(3, 'FA-13', '2025-05-05', '1.0000'),  // different number
        ];

        $out = $this->guard->sameNumberOtherYear('FA 12', '2026-06-01', $candidates);

        $this->assertSame([1], array_column($out, 'invoice_id'));
        $this->assertSame(Guard::OTHER_YEAR, $out[0]['code']);
    }

    public function test_same_digits_ignoring_leading_zeros(): void
    {
        $candidates = [
            $this->candidate(1, '123', '2026-01-01', '1.0000'),
            $this->candidate(2, '00123', '2026-01-01', '1.0000'),   // identical normalized => not a leading-zero warning
            $this->candidate(3, '0000123', '2026-01-01', '1.0000'),
            $this->candidate(4, 'F-123', '2026-01-01', '1.0000'),   // prefix differs
            $this->candidate(5, '1230', '2026-01-01', '1.0000'),
        ];

        $out = $this->guard->sameDigitsIgnoringLeadingZeros('00123', '2026-01-01', $candidates);

        $this->assertSame([1, 3], array_column($out, 'invoice_id'));
        $this->assertSame(Guard::LEADING_ZEROS, $out[0]['code']);
    }

    public function test_all_zero_numbers_never_trigger_leading_zero_warning(): void
    {
        $out = $this->guard->sameDigitsIgnoringLeadingZeros('000', '2026-01-01', [$this->candidate(1, '0', '2026-01-01', '1.0000')]);
        $this->assertSame([], $out);
    }

    public function test_warnings_aggregates_and_is_empty_without_candidates(): void
    {
        $this->assertSame([], $this->guard->warnings('A1', '2026-01-01', '10', [], 7));

        $out = $this->guard->warnings('0100', '2026-01-10', '10.00', [
            $this->candidate(1, '100', '2025-01-10', '10.0000'),
        ], 7);

        // other year? numbers differ ("0100" vs "100"), so: leading zeros + (other-year no) + same-total (366 days: no)
        $this->assertSame([Guard::LEADING_ZEROS], array_column($out, 'code'));
    }
}
