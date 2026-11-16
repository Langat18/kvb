<?php

declare(strict_types=1);

namespace VetBoard;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Turns a page's own HTML into a downloadable PDF, so the PDF carries the same
 * look as the printed page. Images must be inline (data URIs); nothing is
 * fetched from the network.
 */
final class PdfRenderer
{
    /** Inline an image file so the PDF engine needs no file or network access. */
    public static function dataUri(string $path): string
    {
        $bytes = is_file($path) ? @file_get_contents($path) : false;

        if ($bytes === false) {
            return '';
        }

        $mime = str_ends_with(strtolower($path), '.jpg') || str_ends_with(strtolower($path), '.jpeg')
            ? 'image/jpeg'
            : 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    public static function render(string $html, string $paper = 'A4', string $orientation = 'landscape'): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $options->set('chroot', dirname(__DIR__, 2) . '/public');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // "Page X of Y" in the bottom-right corner of every page.
        $canvas = $dompdf->getCanvas();
        $font   = $dompdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text(
            $canvas->get_width() - 90,
            $canvas->get_height() - 22,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            7,
            [0.45, 0.45, 0.45]
        );

        return (string) $dompdf->output();
    }

    /**
     * A very long list needs more memory than this server can spare, so stop with
     * a clear message instead of failing half way. Excel, Word and Print still work.
     */
    public static function refuseIfTooLarge(int $rows, int $limit): void
    {
        if ($rows <= $limit) {
            return;
        }

        http_response_code(413);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><meta charset="UTF-8"><title>Too large for PDF</title>'
            . '<body style="font-family:Arial,sans-serif;max-width:34rem;margin:3rem auto;padding:0 1rem;color:#0f172a">'
            . '<h2>Too large to download as a PDF</h2>'
            . '<p>This list has ' . $rows . ' people, and a PDF can hold up to ' . $limit . ' on this server.</p>'
            . '<p>Please use <b>Download Excel</b> or <b>Print</b> instead.</p>'
            . '<p><a href="javascript:history.back()">Go back</a></p></body>';
        exit;
    }

    /** Render and send the PDF as a download, then stop. */
    public static function stream(string $html, string $filename, string $orientation = 'landscape'): never
    {
        // Large registers with signature images need more room than a normal page view.
        @set_time_limit(120);
        @ini_set('memory_limit', '384M');

        $pdf = self::render($html, 'A4', $orientation);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $pdf;
        exit;
    }
}
