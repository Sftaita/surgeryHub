<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class PdfService
{
    public function __construct(private readonly Environment $twig) {}

    /**
     * @param 'portrait'|'landscape' $orientation
     * @param string|null $pageFooter si renseigné, imprimé en bas de chaque page suivi de « Page X / Y »
     */
    public function generateFromTemplate(string $template, array $data, string $orientation = 'portrait', ?string $pageFooter = null): string
    {
        $html = $this->twig->render($template, $data);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        if ($pageFooter !== null) {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            $text = $pageFooter . ' — Page {PAGE_NUM} / {PAGE_COUNT}';
            $canvas->page_text(26, $canvas->get_height() - 18, $text, $font, 6.5, [0.55, 0.55, 0.55]);
        }

        return $dompdf->output();
    }
}
