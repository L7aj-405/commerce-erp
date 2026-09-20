<?php

namespace Tests\Unit;

use App\Support\FrenchNumberToWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FrenchNumberToWordsTest extends TestCase
{
    private FrenchNumberToWords $words;

    protected function setUp(): void
    {
        parent::setUp();
        $this->words = new FrenchNumberToWords;
    }

    #[DataProvider('wholeNumbers')]
    public function test_it_spells_whole_numbers(int $number, string $expected): void
    {
        $this->assertSame($expected, $this->words->words($number));
    }

    public static function wholeNumbers(): array
    {
        return [
            [0, 'zéro'],
            [1, 'un'],
            [16, 'seize'],
            [17, 'dix-sept'],
            [21, 'vingt et un'],
            [71, 'soixante et onze'],
            [72, 'soixante-douze'],
            [80, 'quatre-vingts'],
            [81, 'quatre-vingt-un'],
            [90, 'quatre-vingt-dix'],
            [91, 'quatre-vingt-onze'],
            [99, 'quatre-vingt-dix-neuf'],
            [100, 'cent'],
            [200, 'deux cents'],
            [201, 'deux cent un'],
            [1000, 'mille'],
            [2000, 'deux mille'],
            [80000, 'quatre-vingt mille'],
            [3892, 'trois mille huit cent quatre-vingt-douze'],
            [1000000, 'un million'],
            [2000000, 'deux millions'],
        ];
    }

    public function test_it_spells_a_mad_amount_with_centimes(): void
    {
        $this->assertSame(
            'TROIS MILLE HUIT CENT QUATRE-VINGT-DOUZE DIRHAMS ET CINQUANTE CENTIMES',
            $this->words->mad('3892.5000'),
        );
    }

    public function test_it_omits_the_centimes_clause_for_a_whole_amount(): void
    {
        $this->assertSame('CENT DIRHAMS', $this->words->mad('100.0000'));
        $this->assertSame('UN DIRHAM', $this->words->mad('1.00'));
    }

    public function test_it_spells_a_single_centime(): void
    {
        $this->assertSame('UN DIRHAM ET UN CENTIME', $this->words->mad('1.01'));
    }

    public function test_it_rounds_to_two_decimals_before_spelling(): void
    {
        $this->assertSame('DEUX DIRHAMS ET UN CENTIME', $this->words->mad('2.0050'));
        $this->assertSame('ZÉRO DIRHAMS ET SOIXANTE-QUINZE CENTIMES', $this->words->mad('0.7500'));
    }
}
