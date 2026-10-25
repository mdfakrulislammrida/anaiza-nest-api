<?php

namespace App\Console\Commands;

use App\Support\InvoicePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

class InstallInvoiceFonts extends Command
{
    protected $signature = 'invoice:install-fonts';

    protected $description = 'Install the invoice font (Hind Siliguri, which has the taka sign) for the PDF maker. Safe to run again; the invoice also does this by itself the first time.';

    public function handle(): int
    {
        $dompdf = Pdf::loadHTML('<p></p>')->getDomPDF();

        $ok = InvoicePdf::ensureFonts($dompdf);

        foreach (['normal', 'bold'] as $weight) {
            $this->line(sprintf('%-7s %s', $weight, InvoicePdf::installed($dompdf, $weight) ? 'installed' : 'MISSING'));
        }

        if (! $ok) {
            $this->error('The font could not be installed. See storage/logs for the reason (usually: storage/fonts is not writable).');

            return self::FAILURE;
        }

        $this->info('The invoice font is installed in '.$dompdf->getOptions()->getFontDir().'.');

        return self::SUCCESS;
    }
}
