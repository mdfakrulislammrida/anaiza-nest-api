<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\Utm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanOrderUtm extends Command
{
    protected $signature = 'orders:utm-clean {--apply : Update the orders (without it, nothing is changed)}';

    protected $description = 'Show the orders whose campaign text (utm_source, utm_medium, utm_campaign, utm_content, utm_term) would change under the clean-up rule, with counts and sample before/after pairs. Changes nothing unless --apply is given.';

    public function handle(): int
    {
        $orders = 0;
        $perField = array_fill_keys(Utm::FIELDS, 0);
        $samples = [];
        $updates = [];

        Order::query()->where(function ($q): void {
            foreach (Utm::FIELDS as $field) {
                $q->orWhereNotNull($field);
            }
        })->orderBy('id')->chunkById(500, function ($chunk) use (&$orders, &$perField, &$samples, &$updates): void {
            foreach ($chunk as $order) {
                $changes = [];

                foreach (Utm::FIELDS as $field) {
                    $before = $order->{$field};

                    if ($before === null) {
                        continue;
                    }

                    $after = Utm::clean($before);

                    if ($after !== $before) {
                        $changes[$field] = $after;
                        $perField[$field]++;
                        $samples[] = [$field, $before, $after ?? '(empty: removed)'];
                    }
                }

                if ($changes !== []) {
                    $orders++;
                    $updates[$order->id] = $changes;
                }
            }
        });

        $this->line("Orders whose campaign text would change: {$orders}");
        $this->table(['Field', 'Values that would change'], array_map(fn (string $field, int $count): array => [$field, $count], array_keys($perField), $perField));

        if ($samples !== []) {
            $this->line('Samples (before / after):');
            $this->table(['Field', 'Before', 'After'], array_slice($samples, 0, 3));
        }

        if (! $this->option('apply')) {
            $this->info($orders === 0 ? 'Nothing to clean.' : 'Dry run: nothing was changed. Run with --apply to update these orders (only the five campaign fields, nothing else).');

            return self::SUCCESS;
        }

        foreach ($updates as $id => $changes) {
            // Straight to the table: only these fields, and the order's own updated time is left alone.
            DB::table('orders')->where('id', $id)->update($changes);
        }

        $this->info("Updated {$orders} order(s).");

        return self::SUCCESS;
    }
}
