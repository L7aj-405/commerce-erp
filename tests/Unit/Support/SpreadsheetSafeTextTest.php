<?php

namespace Tests\Unit\Support;

use App\Support\SpreadsheetSafeText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpreadsheetSafeTextTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function formulaPayloads(): array
    {
        return [
            'equals' => ['=HYPERLINK("https://example.test")'],
            'plus' => ['+cmd|calc'],
            'minus' => ['-1+2'],
            'at' => ['@SUM(A1:A2)'],
            'leading spaces' => ['  =1+1'],
            'tab' => ["\t=1+1"],
            'carriage return' => ["\r=1+1"],
            'newline' => ["\n=1+1"],
        ];
    }

    #[DataProvider('formulaPayloads')]
    public function test_formula_like_text_is_escaped(string $payload): void
    {
        $this->assertSame("'".$payload, SpreadsheetSafeText::escape($payload));
    }

    public function test_normal_text_and_non_strings_are_unchanged(): void
    {
        $this->assertSame('Produit normal', SpreadsheetSafeText::escape('Produit normal'));
        $this->assertSame(42, SpreadsheetSafeText::escape(42));
    }
}
