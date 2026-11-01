<?php

namespace App\Console\Commands;

use App\Support\Email\TransactionalEmails;
use App\Support\System\TaskBudget;
use Illuminate\Console\Command;

class RetryOrderEmails extends Command
{
    protected $signature = 'emails:retry';

    protected $description = 'Send again the order emails (confirmation, shipped, delivered, cancelled) that could not be sent: 15 minutes, 1 hour and 3 hours after each failure, within 24 hours of the first';

    public function handle(): int
    {
        $summary = TransactionalEmails::retryFailed(TaskBudget::until());

        $this->info("{$summary['sent']} sent, {$summary['failed']} failed again, {$summary['dropped']} dropped, {$summary['waiting']} not due yet.");

        return self::SUCCESS;
    }
}
