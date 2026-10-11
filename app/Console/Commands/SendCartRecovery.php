<?php

namespace App\Console\Commands;

use App\Support\Recovery\CartRecovery;
use Illuminate\Console\Command;

class SendCartRecovery extends Command
{
    protected $signature = 'checkouts:recover';

    protected $description = 'Send the cart reminder emails that are due (never in quiet hours, never past the daily cap).';

    public function handle(): int
    {
        $result = CartRecovery::run();

        $this->info("Cart recovery: {$result['sent']} sent, {$result['skipped']} skipped".($result['waiting'] ? " (waiting: {$result['waiting']})" : '').'.');

        return self::SUCCESS;
    }
}
