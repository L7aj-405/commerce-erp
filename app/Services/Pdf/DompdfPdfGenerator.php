<?php

namespace App\Services\Pdf;

use App\Contracts\PdfGenerator;
use Dompdf\Dompdf;
use Dompdf\Options;

class DompdfPdfGenerator implements PdfGenerator
{
    public function generate(string $html, array $options = []): string
    {
        $config = new Options;
        $config->set('isRemoteEnabled', (bool) config('documents.pdf.remote_enabled', false));
        $config->set('isPhpEnabled', false);
        $config->set('isJavascriptEnabled', false);
        $config->set('defaultFont', (string) config('documents.pdf.default_font', 'DejaVu Sans'));
        $config->setChroot([resource_path('views')]);

        $pdf = new Dompdf($config);
        $pdf->setPaper((string) config('documents.pdf.paper', 'a4'), (string) config('documents.pdf.orientation', 'portrait'));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        // Discreet "Page X / Y" in the bottom-right margin. Done on the canvas
        // (not in CSS) because Dompdf only knows the final page count after the
        // layout pass — CSS `counter(pages)` renders as 0 here.
        if ($options['pageNumbers'] ?? false) {
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont(
                (string) config('documents.pdf.default_font', 'DejaVu Sans'),
                'normal',
            );
            $canvas->page_text(
                $canvas->get_width() - 96,
                $canvas->get_height() - 26,
                'Page {PAGE_NUM} / {PAGE_COUNT}',
                $font,
                7,
                [0.6, 0.6, 0.6],
            );
        }

        return $pdf->output();
    }
}
