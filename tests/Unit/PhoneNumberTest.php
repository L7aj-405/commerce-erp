<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @dataProvider numbers
     */
    public function test_it_normalises_moroccan_numbers_for_whatsapp(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::forWhatsApp($raw));
    }

    public static function numbers(): array
    {
        return [
            'local trunk 0'        => ['0612345678', '212612345678'],
            'local with spaces'    => ['06 12 34 56 78', '212612345678'],
            'local with dashes'    => ['0612-345-678', '212612345678'],
            'international +'       => ['+212612345678', '212612345678'],
            'international + spaced'=> ['+212 6 12 34 56 78', '212612345678'],
            'double zero prefix'   => ['00212612345678', '212612345678'],
            'already normalised'   => ['212612345678', '212612345678'],
            'bare national mobile' => ['612345678', '212612345678'],
            'null'                 => [null, null],
            'empty'                => ['   ', null],
            'junk'                 => ['not-a-number', null],
            'too short'            => ['12345', null],
        ];
    }
}
