<?php

namespace App\Console\Commands;

use App\Support\System\ShopAlerts;
use Illuminate\Console\Command;

/**
 * The morning note about products that are finished or running low (scheduled in routes/console.php).
 */
class SendStockDigest extends Command
{
    protected $signature = 'stock:digest {--force : send even if today\'s note has already gone}';

    protected $description = 'Email the shop a once-a-day note listing products that are out or running low.';

    public function handle(): int
    {
        $this->line(ShopAlerts::sendStockDigest((bool) $this->option('force')));

        return self::SUCCESS;
    }
}
