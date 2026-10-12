<?php

namespace App\Support\System;

use App\Models\SystemEvent;
use Illuminate\Support\Carbon;

/**
 * The server's notes to itself. Everything here is best effort: noting a problem must never become a second problem, so a
 * database that cannot be written to is swallowed (the log still has it).
 *
 * Two kinds of note, kept apart by the type name:
 *  - record(): a line in a log (mail_failed, backup_failed). Many rows.
 *  - mark(): "the last time" (scheduler, backup, low_stock_digest). One row per type, updated in place.
 */
final class SystemEvents
{
    public static function record(string $type, string $message, string $level = 'error'): void
    {
        try {
            SystemEvent::query()->create(['type' => $type, 'level' => $level, 'message' => mb_substr($message, 0, 1000)]);
        } catch (\Throwable) {
            // Not worth failing the work that was being noted.
        }
    }

    public static function mark(string $type, string $message = '', string $level = 'ok'): void
    {
        try {
            $row = SystemEvent::query()->where('type', $type)->orderBy('id')->first();

            if ($row === null) {
                SystemEvent::query()->create(['type' => $type, 'level' => $level, 'message' => mb_substr($message, 0, 1000)]);

                return;
            }

            $row->forceFill(['level' => $level, 'message' => mb_substr($message, 0, 1000), 'updated_at' => now()])->save();
        } catch (\Throwable) {
            //
        }
    }

    public static function latest(string $type): ?SystemEvent
    {
        try {
            return SystemEvent::query()->where('type', $type)->orderByDesc('updated_at')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function countSince(string $type, Carbon $since): int
    {
        try {
            return SystemEvent::query()->where('type', $type)->where('created_at', '>=', $since)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Drops notes nothing has touched for the given number of days. */
    public static function prune(int $days = 90): int
    {
        $cutoff = now()->subDays($days);

        return SystemEvent::query()->where('created_at', '<', $cutoff)->where('updated_at', '<', $cutoff)->delete();
    }
}
