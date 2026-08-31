<?php

namespace App\Services\Pdf;

use App\Contracts\PdfGenerator;
use Dompdf\Dompdf;
use Dompdf\Options;

class DompdfPdfGenerator implements PdfGenerator
{
    public function generate(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', (bool) config('documents.pdf.remote_enabled', false));
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', (string) config('documents.pdf.default_font', 'DejaVu Sans'));
        $options->setChroot([resource_path('views')]);

        $pdf = new Dompdf($options);
        $pdf->setPaper((string) config('documents.pdf.paper', 'a4'), (string) config('documents.pdf.orientation', 'portrait'));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
