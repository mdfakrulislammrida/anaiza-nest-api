<?php

namespace App\Console\Commands;

use App\Support\Data\SqliteBackup;
use App\Support\Data\TestDataPurge;
use Illuminate\Console\Command;

class PurgeTestData extends Command
{
    protected $signature = 'data:purge-test
        {--dry-run : Print how many rows would go from each table, change nothing}
        {--force : Really delete (a copy of the database is made first)}
        {--start-order-number= : The number the first real order gets (default 1)}';

    protected $description = 'Delete the orders, customers, reviews and other rows made while testing, keeping the catalogue, pages and all settings. Counts only.';

    public function handle(): int
    {
        $start = $this->option('start-order-number');

        if ($start !== null && (! ctype_digit((string) $start) || (int) $start < 1)) {
            $this->error('--start-order-number must be a whole number of 1 or more.');

            return self::FAILURE;
        }

        $start = $start === null ? null : (int) $start;
        $force = (bool) $this->option('force');

        if ($this->option('dry-run') && $force) {
            $this->error('Use either --dry-run or --force, not both.');

            return self::FAILURE;
        }

        $counts = TestDataPurge::counts();

        $this->line('Rows that '.($force ? 'will be deleted' : 'would be deleted').':');
        foreach ($counts as $row) {
            $this->line(sprintf('  %6d  %s', $row['rows'], $row['label']));
        }
        $this->line(sprintf('  %6d  in total', array_sum(array_column($counts, 'rows'))));
        $this->newLine();

        $this->line('Looked at and left alone:');
        foreach (TestDataPurge::leftAloneCounts() as $table => $rows) {
            $this->line(sprintf('  %6d  %s: %s', $rows, $table, TestDataPurge::leftAlone()[$table]));
        }
        $this->line('  Kept, never touched: products, variants, categories, pages, FAQs, banners, popups, every settings table, admin users and roles, email templates, shipping zones.');
        $this->line('The next order number will be '.($start ?? 1).'.');

        if (! $force) {
            $this->newLine();
            $this->{$this->option('dry-run') ? 'info' : 'warn'}($this->option('dry-run') ? 'Dry run: nothing was changed.' : 'Nothing was changed: add --force to delete for real (or --dry-run to say you only want the report).');

            return self::SUCCESS;
        }

        try {
            $backup = SqliteBackup::copy('before-purge-test');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage().' Nothing was deleted.');

            return self::FAILURE;
        }

        $this->line("Database copied to: {$backup}");

        try {
            $done = TestDataPurge::run($start);
        } catch (\Throwable $e) {
            $this->error('The purge failed and was rolled back (nothing was deleted): '.get_class($e));
            $this->line("The copy made before it is still at: {$backup}");

            return self::FAILURE;
        }

        $this->info('Deleted '.array_sum(array_column($done, 'rows')).' row(s). The next order number is '.($start ?? 1).'.');

        return self::SUCCESS;
    }
}
