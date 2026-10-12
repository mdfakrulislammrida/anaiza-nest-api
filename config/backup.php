<?php

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| A nightly backup is made on the server itself (php artisan backup:run, scheduled at 02:30 Dhaka time) and kept in
| storage/app/backups. It holds a consistent copy of the database and the uploaded pictures. It does NOT contain .env: the
| settings file holds the keys that unlock saved secrets, so keep your own copy of it somewhere private.
|
| A backup that lives only on the server it protects is not enough if the server itself is lost. Download one from
| Settings > System health now and then, or switch on the off-server copy below.
*/

return [
    'enabled' => (bool) env('BACKUP_ENABLED', true),

    'directory' => env('BACKUP_DIRECTORY') ?: storage_path('app/backups'),

    // Where the admin's uploads live.
    'files_root' => storage_path('app/public'),

    // Uploaded pictures (storage/app/public) go into each backup. Turn off if the pictures are large and already kept elsewhere.
    'include_files' => (bool) env('BACKUP_INCLUDE_FILES', true),

    // How many of each are kept; older ones are removed. A weekly one is made on Sundays.
    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 7),
    'keep_weekly' => (int) env('BACKUP_KEEP_WEEKLY', 4),
    'keep_manual' => (int) env('BACKUP_KEEP_MANUAL', 3),

    // A backup is not attempted with less free disk than this (megabytes), so a full disk is never made fuller.
    'min_free_mb' => (int) env('BACKUP_MIN_FREE_MB', 300),

    // A backup older than this many hours is shown as a problem on the health page.
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 36),

    /*
    | Off-server copy over SFTP. OFF by default. Needs the package league/flysystem-sftp-v3 (composer require
    | league/flysystem-sftp-v3), which is not installed unless you choose to turn this on.
    */
    'sftp' => [
        'enabled' => (bool) env('BACKUP_SFTP_ENABLED', false),
        'host' => env('BACKUP_SFTP_HOST'),
        'port' => (int) env('BACKUP_SFTP_PORT', 22),
        'username' => env('BACKUP_SFTP_USERNAME'),
        'password' => env('BACKUP_SFTP_PASSWORD'),
        // Path to a private key file on this server, instead of a password.
        'private_key' => env('BACKUP_SFTP_PRIVATE_KEY'),
        'passphrase' => env('BACKUP_SFTP_PASSPHRASE'),
        // The folder on the other server the backups are placed in.
        'root' => env('BACKUP_SFTP_ROOT', '/anaiza-nest-backups'),
        // How many seconds to wait for the other server.
        'timeout' => (int) env('BACKUP_SFTP_TIMEOUT', 30),
    ],
];
