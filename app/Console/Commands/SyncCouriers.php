<?php

namespace App\Console\Commands;

use App\Support\Courier\CourierSync;
use Illuminate\Console\Command;

class SyncCouriers extends Command
{
    protected $signature = 'couriers:sync {--limit= : Parcels to check in this run (default from config/couriers.php)}';

    protected $description = 'Check the status of booked parcels with Steadfast and Pathao (read-only; safe beside the Steadfast webhook)';

    public function handle(): int
    {
        $summary = CourierSync::run($this->option('limit') !== null ? (int) $this->option('limit') : null);

        $this->info("{$summary['checked']} checked, {$summary['changed']} changed, {$summary['failed']} failed".($summary['stopped'] ? ' ('.implode(', ', $summary['stopped']).')' : '').'.');

        return self::SUCCESS;
    }
}
