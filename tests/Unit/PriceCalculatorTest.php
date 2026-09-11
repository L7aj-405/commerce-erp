<?php

namespace Tests\Unit;

use App\Services\PriceCalculator;
use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $prices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prices = new PriceCalculator;
    }

    public function test_ht_100_at_20_percent_gives_ttc_120(): void
    {
        $this->assertSame('20.0000', $this->prices->taxAmount('100', '20'));
        $this->assertSame('120.0000', $this->prices->inclusive('100', '20'));
    }

    public function test_zero_rate_leaves_price_unchanged(): void
    {
        $this->assertSame('0.0000', $this->prices->taxAmount('149.9900', '0'));
        $this->assertSame('149.9900', $this->prices->inclusive('149.9900', '0'));
    }

    public function test_fractional_rate_and_price_round_half_up_to_four_decimals(): void
    {
        // 99.99 × 19.99% = 19.988001 -> 19.9880
        $this->assertSame('19.9880', $this->prices->taxAmount('99.99', '19.99'));
        $this->assertSame('119.9780', $this->prices->inclusive('99.99', '19.99'));
    }

    public function test_decimal_round_is_half_up_and_returned_in_canonical_four_decimal_form(): void
    {
        $this->assertSame('19.9900', Decimal::round('19.9880', 2));
        $this->assertSame('20.0000', Decimal::round('19.9950', 2));
        $this->assertSame('120.0000', Decimal::round('120.0000', 2));
        $this->assertSame('0.0000', Decimal::round('0.0000', 2));
        $this->assertSame('-1.2300', Decimal::round('-1.2340', 2));
        $this->assertSame('-1.2400', Decimal::round('-1.2350', 2));
    }
}
