<?php

namespace App\Contracts;

interface PdfGenerator
{
    /**
     * Render an HTML document to PDF bytes.
     *
     * @param  array<string, mixed>  $options  Renderer hints. Supported keys:
     *                                          - pageNumbers (bool): stamp a discreet
     *                                            "Page X / Y" in the bottom-right of every
     *                                            page (resolved after pagination).
     */
    public function generate(string $html, array $options = []): string;
}
