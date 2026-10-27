<?php

namespace App\Console\Commands;

use App\Support\ConversionAttempts;
use App\Support\ConversionEvents;
use App\Support\System\TaskBudget;
use Illuminate\Console\Command;

class RetryConversions extends Command
{
    protected $signature = 'conversions:retry';

    protected $description = 'Send again the Purchases that Meta or TikTok did not get because they could not be reached (at most 3 more tries, within 24 hours), and forget the visitor details of sales first sent over a day ago';

    public function handle(): int
    {
        $summary = ConversionAttempts::retryDue(TaskBudget::until());
        $cleaned = ConversionEvents::eraseStale();

        $this->info("{$summary['retried']} sent again, {$summary['gave_up']} given up, {$summary['left']} left for the next run; visitor details forgotten on {$cleaned} order(s).");

        return self::SUCCESS;
    }
}
