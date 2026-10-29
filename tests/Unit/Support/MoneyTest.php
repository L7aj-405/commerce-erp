<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_scale_follows_iso_4217_minor_units_with_a_default_of_two(): void
    {
        $this->assertSame(2, Money::scale('MAD'));
        $this->assertSame(2, Money::scale('mad'));
        $this->assertSame(2, Money::scale('EUR'));
        $this->assertSame(3, Money::scale('TND'));
        $this->assertSame(0, Money::scale('JPY'));
        $this->assertSame(2, Money::scale(null));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function outstandingCases(): array
    {
        return [
            'sub-centime residue is settled' => ['13306.0001', '13306.0000', 'MAD', '0.0000'],
            'just under half a centime is settled' => ['100.0049', '100.0000', 'MAD', '0.0000'],
            'half a centime is still owed' => ['100.0050', '100.0000', 'MAD', '0.0050'],
            'a genuine centime is owed' => ['13306.0100', '13306.0000', 'MAD', '0.0100'],
            'partial keeps 4-decimal precision' => ['100.0000', '35.1250', 'MAD', '64.8750'],
            'overpaid is zero' => ['100.0000', '100.0100', 'MAD', '0.0000'],
            'exactly paid is zero' => ['100.0000', '100.0000', 'MAD', '0.0000'],
            'three-decimal currency keeps 0.001' => ['10.0010', '10.0000', 'TND', '0.0010'],
            'three-decimal currency settles 0.0004' => ['10.0004', '10.0000', 'TND', '0.0000'],
            'zero-decimal currency settles 0.4' => ['1000.4000', '1000.0000', 'JPY', '0.0000'],
            'zero-decimal currency owes 0.5' => ['1000.5000', '1000.0000', 'JPY', '0.5000'],
        ];
    }

    #[DataProvider('outstandingCases')]
    public function test_outstanding_is_zero_only_when_nothing_payable_remains(string $total, string $paid, string $currency, string $expected): void
    {
        $this->assertSame($expected, Money::outstanding($total, $paid, $currency));
    }

    public function test_compare_is_currency_aware_and_never_uses_floats(): void
    {
        $this->assertSame(0, Money::compare('13306.0001', '13306.0000', 'MAD'));
        $this->assertSame(0, Money::compare('13306.0000', '13306.0001', 'MAD'));
        $this->assertSame(1, Money::compare('13306.0100', '13306.0000', 'MAD'));
        $this->assertSame(-1, Money::compare('13306.0000', '13306.0100', 'MAD'));
        $this->assertTrue(Money::isZero('-0.0049', 'MAD'));
        $this->assertFalse(Money::isZero('-0.0050', 'MAD'));
    }
}
