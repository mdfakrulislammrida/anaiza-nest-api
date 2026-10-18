<?php

namespace App\Console\Commands;

use App\Support\Courier\CourierAlerts;
use Illuminate\Console\Command;

class CourierDigest extends Command
{
    protected $signature = 'couriers:digest';

    protected $description = 'Email the shop a note of courier bookings that failed or have had no news for three days (nothing is sent when there are none)';

    public function handle(): int
    {
        $count = CourierAlerts::digest();

        $this->info($count === 0 ? 'Nothing to report.' : "{$count} line(s) reported.");

        return self::SUCCESS;
    }
}
