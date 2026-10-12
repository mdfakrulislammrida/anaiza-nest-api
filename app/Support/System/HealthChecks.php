<?php

namespace App\Support\System;

use App\Models\Order;
use App\Support\Backup\BackupService;
use App\Support\Dhaka;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The questions behind Settings > System health, each answered from what the server can see. Every check returns
 * status ok | warn | fail, a plain sentence about it, and (when it is not ok) what to do.
 */
final class HealthChecks
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, hint: string}>
     */
    public static function all(): array
    {
        return [
            self::scheduler(),
            self::backup(),
            self::disk(),
            self::database(),
            self::mail(),
            self::orders(),
            self::logs(),
            self::storageLink(),
        ];
    }

    /** The worst status among the checks, for a single summary line. */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK);
    }

    private static function row(string $key, string $label, string $status, string $detail, string $hint = ''): array
    {
        return compact('key', 'label', 'status', 'detail', 'hint');
    }

    private static function ago(\DateTimeInterface $time): string
    {
        $minutes = (int) abs(now()->diffInMinutes($time));

        return match (true) {
            $minutes < 1 => 'just now',
            $minutes < 60 => $minutes.' minutes ago',
            $minutes < 60 * 48 => (int) round($minutes / 60).' hours ago',
            default => (int) round($minutes / 1440).' days ago',
        };
    }

    public static function scheduler(): array
    {
        $beat = SystemEvents::latest('scheduler');

        if ($beat === null) {
            return self::row('scheduler', 'Scheduled jobs', self::FAIL, 'They have never run.',
                'Review requests, cart recovery, backups and stock notes all wait on the one cron line on the server: * * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1');
        }

        $minutes = now()->diffInMinutes($beat->updated_at, true);

        if ($minutes > 10) {
            return self::row('scheduler', 'Scheduled jobs', self::FAIL, 'Last ran '.self::ago($beat->updated_at).'.',
                'The cron line has stopped running. Check it in the hosting panel (Advanced > Cron jobs); it should run every minute.');
        }

        return self::row('scheduler', 'Scheduled jobs', self::OK, 'Running; last ran '.self::ago($beat->updated_at).'.');
    }

    public static function backup(): array
    {
        if (! config('backup.enabled')) {
            return self::row('backup', 'Nightly backup', self::WARN, 'Switched off (BACKUP_ENABLED).', 'Turn it back on so the shop is never one mistake away from losing its orders.');
        }

        $backups = new BackupService;
        $newest = $backups->all()[0] ?? null;
        $last = SystemEvents::latest('backup');

        if ($last !== null && $last->level === 'error') {
            return self::row('backup', 'Nightly backup', self::FAIL, 'The last attempt failed: '.$last->message, 'Fix the cause, then press "Back up now" on this page.');
        }

        if ($newest === null) {
            return self::row('backup', 'Nightly backup', self::WARN, 'No backup has been made yet.', 'The first one is made at 02:30 Dhaka time, or press "Back up now" on this page.');
        }

        $age = now()->diffInHours(Carbon::createFromTimestamp($newest['modified']), true);
        $detail = 'Newest: '.self::ago(Carbon::createFromTimestamp($newest['modified'])).', '.BackupService::megabytes($newest['size']).'; '.count($backups->all()).' kept.';

        if ($age > (int) config('backup.stale_after_hours')) {
            return self::row('backup', 'Nightly backup', self::FAIL, $detail, 'The newest backup is too old: the nightly job is not running. Check the cron line, then press "Back up now".');
        }

        if (! config('backup.sftp.enabled')) {
            return self::row('backup', 'Nightly backup', self::OK, $detail.' Kept on this server only: download one now and then.');
        }

        return self::row('backup', 'Nightly backup', self::OK, $detail.' A copy also goes to another server.');
    }

    public static function disk(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if ($free === false || $total === false || $total <= 0) {
            return self::row('disk', 'Disk space', self::WARN, 'Could not be read on this server.');
        }

        $detail = BackupService::megabytes($free).' free of '.BackupService::megabytes($total).'.';

        if ($free < 200 * 1048576) {
            return self::row('disk', 'Disk space', self::FAIL, $detail, 'Almost full: new orders and backups can fail. Remove old logs and backups, or ask the host for more space.');
        }

        if ($free < 1024 * 1048576 || $free / $total < 0.1) {
            return self::row('disk', 'Disk space', self::WARN, $detail, 'Getting low. Old backups and logs are the usual cause.');
        }

        return self::row('disk', 'Disk space', self::OK, $detail);
    }

    public static function database(): array
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver !== 'sqlite') {
                $connection->select('select 1');

                return self::row('database', 'Database', self::OK, 'Answering ('.$driver.').');
            }

            $check = (string) ($connection->selectOne('PRAGMA quick_check')->quick_check ?? '');
            $mode = strtolower((string) $connection->selectOne('PRAGMA journal_mode')->journal_mode);
            $file = (string) config('database.connections.sqlite.database');
            $size = is_file($file) ? BackupService::megabytes((int) filesize($file)) : 'in memory';

            if (strtolower($check) !== 'ok') {
                return self::row('database', 'Database', self::FAIL, 'Its own check reported: '.$check, 'Restore the newest backup (docs/backup-and-restore.md) and tell the developer.');
            }

            if ($mode !== 'wal') {
                return self::row('database', 'Database', self::WARN, "Healthy, {$size}, but journal mode is \"{$mode}\".", 'Set DB_JOURNAL_MODE=WAL so two buyers ordering at once do not collide.');
            }

            return self::row('database', 'Database', self::OK, "Healthy, {$size}.");
        } catch (\Throwable $e) {
            return self::row('database', 'Database', self::FAIL, 'Not answering: '.$e->getMessage());
        }
    }

    public static function mail(): array
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            return self::row('mail', 'Email', self::FAIL, "Emails are being written to the log, not sent (MAIL_MAILER={$mailer}).",
                'Set the mail settings in .env (MAIL_MAILER=smtp and the host, port, username and password from your email provider). Until then no customer gets a confirmation.');
        }

        $failed = SystemEvents::countSince('mail_failed', now()->subDays(7));

        if ($failed > 0) {
            $last = SystemEvents::latest('mail_failed');

            return self::row('mail', 'Email', self::WARN, "{$failed} email(s) could not be sent in the last 7 days. Latest: ".($last?->message ?? ''),
                'Check the mail password and the sending address in .env. Customers whose email failed can still be reached on the phone number on the order.');
        }

        return self::row('mail', 'Email', self::OK, "Sending through {$mailer}; no failures in the last 7 days.");
    }

    public static function orders(): array
    {
        try {
            $last = Order::query()->latest('id')->first();
            $waiting = Order::query()->where('status', 'pending')->where('created_at', '<', now()->subDay())->count();
        } catch (\Throwable) {
            return self::row('orders', 'Orders', self::WARN, 'Could not be read.');
        }

        $detail = $last === null ? 'No orders yet.' : 'Latest order #'.$last->id.', '.Dhaka::readable($last->created_at).'.';

        if ($waiting > 0) {
            return self::row('orders', 'Orders', self::WARN, $detail." {$waiting} still Pending after a day.", 'Open Orders, filter by Pending, and confirm or cancel them so customers are not left waiting.');
        }

        return self::row('orders', 'Orders', self::OK, $detail.' Nothing left waiting for more than a day.');
    }

    public static function logs(): array
    {
        $bytes = 0;

        foreach (glob(storage_path('logs').DIRECTORY_SEPARATOR.'*.log') ?: [] as $file) {
            $bytes += (int) filesize($file);
        }

        $detail = 'Logs take '.BackupService::megabytes($bytes).'.';

        if ($bytes > 200 * 1048576) {
            return self::row('logs', 'Log files', self::WARN, $detail, 'Set LOG_STACK=daily so old logs are removed after two weeks, and delete the large file in storage/logs.');
        }

        return self::row('logs', 'Log files', self::OK, $detail);
    }

    public static function storageLink(): array
    {
        if (is_link(public_path('storage')) || is_dir(public_path('storage'))) {
            return self::row('storage', 'Picture links', self::OK, 'public/storage is in place.');
        }

        return self::row('storage', 'Picture links', self::FAIL, 'public/storage is missing, so uploaded pictures will not show.', 'Run: php artisan storage:link');
    }
}
