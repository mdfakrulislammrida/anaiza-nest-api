<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupService;
use App\Support\Dhaka;
use App\Support\System\ShopAlerts;
use App\Support\System\SystemEvents;
use Illuminate\Console\Command;

/**
 * The nightly backup (scheduled in routes/console.php), and the same thing by hand: php artisan backup:run
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run {--kind=daily : daily, weekly or manual}';

    protected $description = 'Make a backup of the database and uploaded pictures, and remove old ones.';

    public function handle(BackupService $backups): int
    {
        if (! config('backup.enabled')) {
            $this->line('Backups are switched off (BACKUP_ENABLED).');

            return self::SUCCESS;
        }

        $kind = (string) $this->option('kind');

        try {
            $made = $backups->run($kind);

            // Sundays also keep a weekly one, which lives longer than the daily ones.
            if ($kind === 'daily' && Dhaka::now()->isSunday()) {
                $weekly = preg_replace('/^anaiza-daily-/', 'anaiza-weekly-', $made['name']);
                copy($made['path'], dirname($made['path']).DIRECTORY_SEPARATOR.$weekly);
                $backups->prune();
            }
        } catch (\Throwable $e) {
            SystemEvents::mark('backup', $e->getMessage(), 'error');
            SystemEvents::record('backup_failed', $e->getMessage());
            ShopAlerts::backupFailed($e->getMessage());

            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $summary = sprintf('%s, %s%s', $made['name'], BackupService::megabytes($made['size']), $made['offsite'] ? ", copied to {$made['offsite']}" : '');
        SystemEvents::mark('backup', $summary, 'ok');

        $this->info('Backup made: '.$summary);

        return self::SUCCESS;
    }
}
