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

        $orientation = in_array($options['orientation'] ?? null, ['portrait', 'landscape'], true)
            ? $options['orientation']
            : (string) config('documents.pdf.orientation', 'portrait');

        $pdf = new Dompdf($config);
        $pdf->setPaper((string) config('documents.pdf.paper', 'a4'), $orientation);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        // Discreet "Page X / Y" in the bottom-right margin. Done on the canvas
        // (not in CSS) because Dompdf only knows the final page count after the
        // layout pass — CSS `counter(pages)` renders as 0 here.
        if ($options['pageNumbers'] ?? false) {
            $canvas = $pdf->getCanvas();
            $pagination = is_array($options['pagination'] ?? null) ? $options['pagination'] : [];
            $fontFamily = in_array($pagination['font_family'] ?? null, ['DejaVu Sans', 'Helvetica'], true)
                ? $pagination['font_family']
                : (string) config('documents.pdf.default_font', 'DejaVu Sans');
            $font = $pdf->getFontMetrics()->getFont(
                $fontFamily,
                'normal',
            );
            $size = max(6.0, min(11.0, (float) ($pagination['font_size'] ?? 7)));
            $marginX = max(5.0, min(30.0, (float) ($pagination['margin_x_mm'] ?? 13))) * 2.83465;
            $marginY = max(4.0, min(20.0, (float) ($pagination['margin_y_mm'] ?? 8))) * 2.83465;
            $textWidth = 76.0;
            $x = match ($pagination['alignment'] ?? 'right') {
                'left' => $marginX,
                'center' => ($canvas->get_width() - $textWidth) / 2,
                default => $canvas->get_width() - $marginX - $textWidth,
            };
            $y = ($pagination['position'] ?? 'bottom') === 'top'
                ? $marginY
                : $canvas->get_height() - $marginY - $size;
            $color = $this->rgb((string) ($pagination['color'] ?? '#999999'));
            $canvas->page_text(
                $x,
                $y,
                'Page {PAGE_NUM} / {PAGE_COUNT}',
                $font,
                $size,
                $color,
            );
        }

        return $pdf->output();
    }

    /** @return array{0:float,1:float,2:float} */
    private function rgb(string $hex): array
    {
        if (preg_match('/^#([0-9a-f]{6})$/i', $hex, $matches) !== 1) {
            return [0.6, 0.6, 0.6];
        }

        return [
            hexdec(substr($matches[1], 0, 2)) / 255,
            hexdec(substr($matches[1], 2, 2)) / 255,
            hexdec(substr($matches[1], 4, 2)) / 255,
        ];
    }
}
