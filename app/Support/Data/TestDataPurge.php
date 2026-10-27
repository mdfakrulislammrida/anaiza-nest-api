<?php

namespace App\Support\Data;

use App\Models\Customer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clears what was made while the shop was being tried out, so it opens with a clean slate: orders and everything that hangs off
 * them, customer accounts, reviews, abandoned checkouts, newsletter sign-ups, visit counters, queued and failed jobs and the
 * mail / courier failure notes.
 *
 * It never touches what the shop is made of: products, variants, categories, pages, FAQs, banners, popups, every settings
 * table, the admin users and roles, the email templates, the shipping zones, the blocked contacts and the opt-outs.
 * A coupon you made stays (only its "used" marks are cleared); a coupon made for one abandoned checkout goes with it.
 *
 * Counts only, never a row. Plain queries, so no model event (and so no email) can run.
 */
final class TestDataPurge
{
    /** System notes that belong to test orders and mail; the "last time" notes (scheduler, backup) stay. */
    private const NOTE_TYPES = ['mail_failed', 'courier_auth', 'courier_booking_failed', 'courier_webhook_failed', 'capi_failed'];

    /**
     * Tables that are looked at but left alone, with a reason, so a person running the purge sees what stays.
     *
     * @return array<string, string>
     */
    public static function leftAlone(): array
    {
        return [
            'contact_submissions' => 'messages from the contact form (not part of the purge: read and delete them yourself)',
            'corporate_enquiries' => 'gift enquiries (not part of the purge: read and delete them yourself)',
            'email_opt_outs' => 'people who asked not to be emailed (kept on purpose)',
            'blocked_contacts' => 'blocked phones and emails (kept on purpose)',
        ];
    }

    /**
     * The steps, in the order they run. `table` is the one the step changes; `reset` says it is emptied completely, so its counter
     * starts again at 1.
     *
     * @return list<array{table: string, label: string, reset: bool, count: \Closure(): int, run: \Closure(): int}>
     */
    private static function steps(): array
    {
        $all = fn (string $table): array => [
            'table' => $table,
            'label' => $table,
            'reset' => true,
            'count' => fn (): int => DB::table($table)->count(),
            'run' => fn (): int => DB::table($table)->delete(),
        ];

        $partial = fn (string $table, string $label, \Closure $scope, bool $delete): array => [
            'table' => $table,
            'label' => $label,
            'reset' => false,
            'count' => fn (): int => $scope(DB::table($table))->count(),
            'run' => fn (): int => $delete ? $scope(DB::table($table))->delete() : $scope(DB::table($table))->update(['used_count' => 0, 'used_at' => null, 'used_order_id' => null]),
        ];

        return [
            $all('product_reviews'),
            $all('courier_events'),
            $all('order_items'),
            $all('orders'),
            $all('abandoned_checkouts'),
            $partial('coupons', 'coupons made for an abandoned checkout (deleted)', fn (Builder $q): Builder => $q->whereNotNull('abandoned_checkout_id'), true),
            $partial('coupons', 'coupons with a "used" mark (the coupon stays, the mark is cleared)', fn (Builder $q): Builder => $q->where(fn (Builder $w) => $w->where('used_count', '>', 0)->orWhereNotNull('used_at')->orWhereNotNull('used_order_id')), false),
            $partial('personal_access_tokens', 'personal_access_tokens (customers\' sign-ins only)', fn (Builder $q): Builder => $q->where('tokenable_type', Customer::class), true),
            $all('customers'),
            $all('newsletter_subscribers'),
            $all('checkout_daily_counts'),
            $all('landing_page_stats'),
            $all('jobs'),
            $all('failed_jobs'),
            $all('job_batches'),
            $all('password_reset_tokens'),
            $partial('system_events', 'system_events (mail and courier failure notes only)', fn (Builder $q): Builder => $q->whereIn('type', self::NOTE_TYPES), true),
        ];
    }

    /**
     * What would go, per step.
     *
     * @return list<array{table: string, label: string, rows: int}>
     */
    public static function counts(): array
    {
        $rows = [];

        foreach (self::steps() as $step) {
            if (! Schema::hasTable($step['table'])) {
                continue;
            }

            $rows[] = ['table' => $step['table'], 'label' => $step['label'], 'rows' => $step['count']()];
        }

        return $rows;
    }

    /**
     * Rows in the tables that are looked at but left alone.
     *
     * @return array<string, int>
     */
    public static function leftAloneCounts(): array
    {
        $counts = [];

        foreach (array_keys(self::leftAlone()) as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * Does it, in one transaction: either all of it or none. On SQLite the counters of the emptied tables start again at 1,
     * and the next order gets $startOrderNumber (default 1).
     *
     * @return list<array{table: string, label: string, rows: int}> what was removed or cleared, per step
     */
    public static function run(?int $startOrderNumber = null): array
    {
        return DB::transaction(function () use ($startOrderNumber): array {
            $done = [];
            $emptied = [];

            foreach (self::steps() as $step) {
                if (! Schema::hasTable($step['table'])) {
                    continue;
                }

                $done[] = ['table' => $step['table'], 'label' => $step['label'], 'rows' => $step['run']()];

                if ($step['reset']) {
                    $emptied[] = $step['table'];
                }
            }

            if (DB::connection()->getDriverName() === 'sqlite' && Schema::hasTable('sqlite_sequence')) {
                DB::table('sqlite_sequence')->whereIn('name', $emptied)->delete();

                // The next order is $start: the counter holds the last number used.
                if ($startOrderNumber !== null && $startOrderNumber > 1) {
                    DB::table('sqlite_sequence')->insert(['name' => 'orders', 'seq' => $startOrderNumber - 1]);
                }
            }

            return $done;
        });
    }
}
