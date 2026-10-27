<?php

use App\Support\System\SystemEvents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// There is no queue worker on the host: everything timed runs from here, and the one cron line that drives it is
//   * * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('reviews:request')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('checkouts:recover')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('checkouts:tidy')->dailyAt('03:30')->timezone('Asia/Dhaka')->withoutOverlapping(10);

// Proof that the cron line is alive, read by Settings > System health.
Schedule::call(fn () => SystemEvents::mark('scheduler', 'ran'))->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping(2);

// A backup of the database and the uploaded pictures, kept on the server (see config/backup.php).
Schedule::command('backup:run')->dailyAt('02:30')->timezone('Asia/Dhaka')->withoutOverlapping(120);

// The morning note about products that are finished or running low.
Schedule::command('stock:digest')->dailyAt('09:00')->timezone('Asia/Dhaka')->withoutOverlapping(10);

Schedule::command('system:tidy')->dailyAt('03:45')->timezone('Asia/Dhaka')->withoutOverlapping(10);

// Follow every booked parcel (the fallback beside Steadfast's webhook, and the only way for Pathao). Built to be light: see CourierSync.
Schedule::command('couriers:sync')->everyFifteenMinutes()->withoutOverlapping(14);

// The morning note of courier bookings that failed or have gone quiet.
Schedule::command('couriers:digest')->dailyAt('09:30')->timezone('Asia/Dhaka')->withoutOverlapping(10);
