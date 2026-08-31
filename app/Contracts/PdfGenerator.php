<?php

namespace App\Contracts;

interface PdfGenerator
{
    public function generate(string $html): string;
}
