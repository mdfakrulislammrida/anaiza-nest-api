<?php

namespace App\Console\Commands;

use App\Support\Reviews\ReviewRequests;
use Illuminate\Console\Command;

class SendReviewRequests extends Command
{
    protected $signature = 'reviews:request';

    protected $description = 'Email a review request for orders delivered long enough ago (and one reminder, if switched on).';

    public function handle(): int
    {
        $result = ReviewRequests::run();

        $this->info("Review requests: {$result['sent']} sent, {$result['reminded']} reminders, {$result['skipped']} skipped.");

        return self::SUCCESS;
    }
}
