<?php

namespace App\Console\Commands;

use App\Support\Courier\CourierEffectPreview;
use App\Support\Courier\CourierStatusApplier;
use Illuminate\Console\Command;

class PreviewCourierEffects extends Command
{
    protected $signature = 'couriers:preview-effects
        {--apply : Make the changes listed (asks first). Without it nothing is written.}
        {--notify : With --apply, also send the customer emails for old news (more than 24 hours old). Normally none is sent.}';

    protected $description = 'List the orders the courier status mapping would change (a dry run), or change them with --apply';

    public function handle(): int
    {
        $rows = CourierEffectPreview::rows((bool) $this->option('notify'));

        if ($rows === []) {
            $this->info('No order would change under the current mapping.');

            return self::SUCCESS;
        }

        $this->table(
            ['Order', 'Courier', 'Consignment', 'Courier status', 'Order now', 'Order would become', 'Cash recorded', 'Email', 'SMS', 'Armed'],
            array_map(fn (array $row): array => [
                $row['number'], $row['courier'], $row['consignment'], $row['courier_status'], $row['status'], $row['new_status'],
                $row['cash'] ? 'yes' : 'no', $row['email'], $row['sms'], $row['armed'] ? 'yes' : 'no (acts only when armed)',
            ], $rows),
        );
        $this->line(count($rows).' order(s) would change.');

        if (! $this->option('apply')) {
            $this->line('Nothing was changed. Run with --apply to make these changes (it asks first).');

            return self::SUCCESS;
        }

        if (! $this->confirm('Change these '.count($rows).' order(s) now?')) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $done = 0;

        foreach ($rows as $row) {
            try {
                CourierStatusApplier::apply($row['order'], $row['courier'], $row['courier_status'], 'preview', null, true, (bool) $this->option('notify'));
                $done++;
            } catch (\Throwable $e) {
                $this->error('Order '.$row['number'].' could not be changed ('.get_class($e).').');
            }
        }

        $this->info("{$done} order(s) changed.");

        return self::SUCCESS;
    }
}
