<?php

return [
    // The status check (php artisan couriers:sync, every 15 minutes from the scheduler) never works harder than this, so a
    // courier outage or a flood of parcels cannot slow the shop.
    'sync' => [
        // Parcels checked in one run, newest first.
        'per_run' => (int) env('COURIER_SYNC_PER_RUN', 40),
        // Seconds after which a run stops starting new checks.
        'seconds' => (int) env('COURIER_SYNC_SECONDS', 45),
        // Calls to one courier in a day (Dhaka time), for status checks. Steadfast allows 1,000 a minute; this is far under.
        'daily_cap' => (int) env('COURIER_SYNC_DAILY_CAP', 800),
        // Failures in a row, for one courier in one run, after which that courier is left alone until the next run.
        'stop_after_failures' => 3,
    ],
];
