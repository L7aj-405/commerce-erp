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
     *                                          - orientation ('portrait'|'landscape'): page
     *                                            orientation for this render, overriding
     *                                            config('documents.pdf.orientation'). Any
     *                                            other value is ignored and falls back to
     *                                            that config default.
     */
    public function generate(string $html, array $options = []): string;
}
