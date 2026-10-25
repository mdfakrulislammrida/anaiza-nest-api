<?php

namespace App\Support;

use App\Models\Order;
use App\Models\SiteSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Log;

/**
 * The invoice PDF, set in Hind Siliguri.
 *
 * dompdf's built-in fonts (Helvetica and the like) have no taka sign, so every ৳ on the invoice came out as "?". Hind Siliguri (SIL Open
 * Font License, files in resources/fonts) has ৳ (U+09F3) next to Latin letters and digits, and Bangla letters too, so the whole invoice is set in
 * it. dompdf has to be told where the font is: ensureFonts() installs it into its font folder (storage/fonts) the first time, and after that
 * only checks that it is still there. A fresh server therefore needs nothing but the code; `php artisan invoice:install-fonts` does the same
 * step on purpose and reports.
 */
final class InvoicePdf
{
    public const FAMILY = 'Hind Siliguri';

    /** weight => the file in resources/fonts */
    private const FILES = ['normal' => 'HindSiliguri-Regular.ttf', 'bold' => 'HindSiliguri-Bold.ttf'];

    public static function make(Order $order, ?SiteSetting $siteSetting = null): PdfDocument
    {
        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'siteSetting' => $siteSetting ?? SiteSetting::query()->first(),
        ]);

        self::ensureFonts($pdf->getDomPDF());

        // Only the letters the invoice uses go into the file (a few KB), not the whole 250 KB font.
        $pdf->setOption(['enable_font_subsetting' => true]);

        return $pdf;
    }

    /**
     * Makes sure the font is installed for dompdf. Returns false (and logs) when it could not be, in which case the invoice still
     * renders, in its fallback font, with "?" for the taka sign: a failure here must never stop an order email.
     */
    public static function ensureFonts(Dompdf $dompdf): bool
    {
        try {
            $metrics = $dompdf->getFontMetrics();
            $fontDir = $dompdf->getOptions()->getFontDir();

            if (! is_dir($fontDir) && ! @mkdir($fontDir, 0775, true) && ! is_dir($fontDir)) {
                throw new \RuntimeException("The font folder {$fontDir} does not exist and could not be made.");
            }

            $changed = false;

            foreach (self::FILES as $weight => $file) {
                if (self::installed($dompdf, $weight)) {
                    continue;
                }

                $installed = $metrics->registerFont(['family' => self::FAMILY, 'style' => 'normal', 'weight' => $weight], resource_path('fonts/'.$file));

                if (! $installed) {
                    throw new \RuntimeException("dompdf could not install resources/fonts/{$file} into {$fontDir} (is the folder writable?).");
                }

                $changed = true;
            }

            if ($changed) {
                self::tidyCache($dompdf);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('The invoice font could not be installed.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * dompdf saves the second font of a family with the first one's full path in front of it (the folder it was copied to), which it then fails
     * to find again if the folder is spelled differently, and which would be wrong on any other server. Every entry is made a plain file name,
     * relative to the font folder, as the first one is.
     */
    private static function tidyCache(Dompdf $dompdf): void
    {
        $metrics = $dompdf->getFontMetrics();
        $file = $metrics->getUserFontsFilePath();
        $cache = json_decode((string) @file_get_contents($file), true);
        $key = mb_strtolower(self::FAMILY);

        if (! is_array($cache) || ! isset($cache[$key]) || ! is_array($cache[$key])) {
            return;
        }

        foreach ($cache[$key] as $weight => $path) {
            $cache[$key][$weight] = basename(str_replace(chr(92), '/', (string) $path));
        }

        file_put_contents($file, json_encode($cache, JSON_PRETTY_PRINT));

        // Read it back, so the lookups dompdf makes in this run use the tidy entries too.
        $metrics->loadFontFamilies();
        $metrics->setFontFamilies();
    }

    /** Whether this weight of the font is in dompdf's font cache with its files still on disk. */
    public static function installed(Dompdf $dompdf, string $weight): bool
    {
        $families = $dompdf->getFontMetrics()->getFontFamilies();
        $path = $families[mb_strtolower(self::FAMILY)][$weight] ?? null;

        return is_string($path) && is_file($path.'.ttf') && is_file($path.'.ufm');
    }
}
