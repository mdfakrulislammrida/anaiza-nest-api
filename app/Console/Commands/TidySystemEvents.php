<?php

namespace App\Console\Commands;

use App\Support\System\SystemEvents;
use Illuminate\Console\Command;

class TidySystemEvents extends Command
{
    protected $signature = 'system:tidy {--days=90 : how many days of notes to keep}';

    protected $description = 'Remove old system notes (failed emails, failed backups).';

    public function handle(): int
    {
        $removed = SystemEvents::prune(max(7, (int) $this->option('days')));

        $this->line("Removed {$removed} old system note(s).");

        return self::SUCCESS;
    }
}
