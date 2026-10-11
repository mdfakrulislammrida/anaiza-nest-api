<?php

namespace App\Console\Commands;

use App\Support\Recovery\CartRecovery;
use Illuminate\Console\Command;

class TidyCheckouts extends Command
{
    protected $signature = 'checkouts:tidy';

    protected $description = 'Mark old open checkouts expired and delete those past the retention period.';

    public function handle(): int
    {
        $result = CartRecovery::tidy();

        $this->info("Checkouts: {$result['expired']} expired, {$result['deleted']} deleted.");

        return self::SUCCESS;
    }
}
