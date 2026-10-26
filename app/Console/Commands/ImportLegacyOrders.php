<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Support\Data\SqliteBackup;
use App\Support\Legacy\LegacyOrderImporter;
use App\Support\Legacy\SqlDumpReader;
use Illuminate\Console\Command;

class ImportLegacyOrders extends Command
{
    protected $signature = 'orders:import-legacy
        {file? : The folder holding customers.sql, orders.sql and order_details.sql, or the path of orders.sql (default storage/imports)}
        {--dry-run : Report what would be imported, change nothing}
        {--force : Really import (a copy of the database is made first)}
        {--status-map= : The old order_status codes as code=status, for example 2=confirmed,3=shipped,5=delivered,6=cancelled}
        {--accept-default-status-map : Use the assumed status map for a real import}';

    protected $description = 'Import the old site\'s orders as history. No emails, advertising events, courier bookings or stock changes. Counts only: nothing personal is printed.';

    public function handle(): int
    {
        try {
            $statusMap = filled($this->option('status-map'))
                ? LegacyOrderImporter::parseStatusMap((string) $this->option('status-map'))
                : LegacyOrderImporter::DEFAULT_STATUS_MAP;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $explicitMap = filled($this->option('status-map'));
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run') || ! $force;

        if ($this->option('dry-run') && $force) {
            $this->error('Use either --dry-run or --force, not both.');

            return self::FAILURE;
        }

        $folder = $this->folder();
        $tables = [];
        $found = [];

        foreach (['customers', 'orders', 'order_details'] as $name) {
            $path = $folder.DIRECTORY_SEPARATOR.$name.'.sql';

            if (! is_file($path)) {
                $this->error("Missing {$name}.sql in {$folder}.");

                return self::FAILURE;
            }

            $rows = SqlDumpReader::rows((string) file_get_contents($path));
            $tables[$name] = $rows[$name] ?? [];
            $found[$name] = count($tables[$name]);
        }

        $plan = LegacyOrderImporter::plan($tables, $statusMap);
        $this->report($plan, $found, $explicitMap);

        if ($dry) {
            if (! $this->option('dry-run')) {
                $this->warn('Nothing was changed: add --force to import for real (or --dry-run to say you only want the report).');
            } else {
                $this->info('Dry run: nothing was changed.');
            }

            return self::SUCCESS;
        }

        if (! $explicitMap && ! $this->option('accept-default-status-map')) {
            $this->error('The old status codes are not documented, so the map above is a guess. Give the real one with --status-map="..." (or accept the guess with --accept-default-status-map), then run again.');

            return self::FAILURE;
        }

        if ($plan['orders'] === []) {
            $this->info('Nothing to import.');

            return self::SUCCESS;
        }

        try {
            $backup = SqliteBackup::copy('before-legacy-import');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage().' Nothing was imported.');

            return self::FAILURE;
        }

        $this->line("Database copied to: {$backup}");

        try {
            $result = LegacyOrderImporter::import($plan);
        } catch (\Throwable $e) {
            $this->error('The import failed and was rolled back (nothing was written): '.get_class($e));
            $this->line("The copy made before it is still at: {$backup}");

            return self::FAILURE;
        }

        $this->info("Imported {$result['orders']} order(s) with {$result['items']} line(s). Customers: {$result['customers_matched']} matched by phone, {$result['customers_created']} created.");

        if ($result['skipped_existing'] > 0) {
            $this->line("{$result['skipped_existing']} order(s) were already there and were left alone.");
        }

        return self::SUCCESS;
    }

    private function folder(): string
    {
        $argument = $this->argument('file');

        if (blank($argument)) {
            return storage_path('imports');
        }

        return is_dir($argument) ? rtrim((string) $argument, '\\/') : dirname((string) $argument);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, int>  $found
     */
    private function report(array $plan, array $found, bool $explicitMap): void
    {
        $this->line('Rows in the export: '.$found['customers'].' customers, '.$found['orders'].' orders, '.$found['order_details'].' order lines.');
        $this->line('Status map ('.($explicitMap ? 'given' : 'ASSUMED, the old codes are not documented').'): '.implode(', ', array_map(fn ($code, $status) => "{$code}={$status}", array_keys($plan['status_map']), $plan['status_map'])));
        $this->newLine();
        $this->line('Orders in the file: '.$plan['in_file']);
        $this->line('  already imported (left alone): '.$plan['already_imported']);
        $this->line('  to import: '.count($plan['orders']));
        $this->line('  skipped: '.array_sum($plan['skipped']));

        foreach ($plan['skipped'] as $reason => $count) {
            $this->line("    {$count}  {$reason}");
        }

        $this->line('  order lines belonging to no order in the export (ignored): '.$plan['orphan_lines']);
        $this->newLine();
        $this->line('To import: '.count($plan['orders']).' orders, '.$plan['lines'].' lines, total '.number_format($plan['taka']).' taka');
        $this->line('Date range: '.($plan['date_from'] ? $plan['date_from']->copy()->timezone('Asia/Dhaka')->toDateString().' to '.$plan['date_to']->copy()->timezone('Asia/Dhaka')->toDateString() : 'none'));

        foreach ($plan['by_status'] as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        $this->line('By old status code: '.implode(', ', array_map(fn ($code, $count) => "{$code} -> {$count}", array_keys($plan['by_old_code']), $plan['by_old_code'])));
        $this->newLine();
        $this->line('Worth knowing:');
        $this->line('  orders whose lines do not add up to the old total (the old total is kept): '.$plan['total_not_from_lines']);
        $this->line('  customers whose phone is not a valid mobile number (kept as typed): '.$plan['phone_not_valid']);
        $this->line('  orders with fractional amounts (rounded to whole taka): '.$plan['fractional_amounts']);
        $this->line('  customers the shop already has (matched by phone): '.$this->matchedCustomers($plan));
    }

    /** @param  array<string, mixed>  $plan */
    private function matchedCustomers(array $plan): int
    {
        $known = [];
        foreach (Customer::query()->whereNotNull('phone')->pluck('phone') as $phone) {
            $key = LegacyOrderImporter::phone($phone);
            if ($key !== null) {
                $known[$key] = true;
            }
        }

        $matched = [];
        foreach ($plan['orders'] as $order) {
            if ($order['phone'] !== null && isset($known[$order['phone']])) {
                $matched[$order['phone']] = true;
            }
        }

        return count($matched);
    }
}
