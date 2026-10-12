<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * One command that answers "is this server set up safely?" from what the application itself can see. Run it after a
 * deploy: php artisan security:check
 */
class SecurityCheck extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Check the settings and accounts that matter most for the shop\'s security.';

    public function handle(): int
    {
        $problems = 0;

        $report = function (bool $ok, string $label, string $detail = '') use (&$problems): void {
            if (! $ok) {
                $problems++;
            }

            $this->line(sprintf('  [%s] %s%s', $ok ? ' OK ' : 'FIX!', $label, $detail !== '' ? ' - '.$detail : ''));
        };

        $this->info('Application');
        $production = app()->environment('production');
        $report($production, 'APP_ENV is production', 'it is "'.app()->environment().'"');
        $report(! config('app.debug'), 'APP_DEBUG is off', config('app.debug') ? 'debug pages show code and settings to visitors' : '');
        $report(filled(config('app.key')), 'APP_KEY is set');
        $report(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL is https', (string) config('app.url'));
        $report(str_starts_with((string) config('app.frontend_url'), 'https://'), 'FRONTEND_URL is https', (string) config('app.frontend_url'));

        $this->newLine();
        $this->info('Sessions and sign-in');
        $report((bool) config('session.secure') || ! $production, 'SESSION_SECURE_COOKIE is on', 'set SESSION_SECURE_COOKIE=true');
        $report(config('sanctum.expiration') !== null, 'Customer sign-ins expire', 'SANCTUM_EXPIRATION is empty');

        $this->newLine();
        $this->info('Admin accounts');

        if (Schema::hasTable('users')) {
            $users = User::query()->get();
            $report($users->isNotEmpty(), 'There is an admin account', $users->count().' found');

            $default = $users->filter(fn (User $user): bool => Hash::check('password', $user->password));
            $report($default->isEmpty(), 'No admin uses the password "password"', $default->pluck('email')->implode(', '));

            $sample = $users->filter(fn (User $user): bool => in_array(mb_strtolower($user->email), ['test@example.com', 'admin@example.com'], true));
            $report($sample->isEmpty(), 'No sample admin account', $sample->pluck('email')->implode(', '));

            $supers = $users->filter(fn (User $user): bool => $user->isSuperAdmin());
            $this->line('  Unrestricted (no role): '.($supers->pluck('email')->implode(', ') ?: 'none'));
        }

        $this->newLine();
        $this->info('Database');
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $mode = strtolower((string) DB::selectOne('PRAGMA journal_mode')->journal_mode);
            $report($mode === 'wal', 'SQLite journal mode is WAL', 'it is "'.$mode.'" (DB_JOURNAL_MODE)');
            $report((int) (DB::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0) === 1, 'Foreign keys are enforced');
        } else {
            $this->line('  Driver: '.$driver);
        }

        $this->newLine();
        $this->info('Traffic');
        $report(config('trustedproxy.proxies') !== null || config('trustedproxy.client_ip_header') !== null, 'Client address settings are configured', 'check Admin > Network check, then set TRUSTED_PROXIES / CLIENT_IP_HEADER if it says so');
        $report((bool) config('abuse.hsts_enabled'), 'HSTS is on', 'turn on with HSTS_ENABLED=true once the site is HTTPS only');
        $report((bool) config('abuse.require_form_proof'), 'Forms require the bot trap fields', 'turn on with ABUSE_REQUIRE_FORM_PROOF=true once the storefront is deployed');

        $this->newLine();
        $this->info('Logs and files');
        $stack = (string) env('LOG_STACK', 'single');
        $report(str_contains($stack, 'daily') || env('LOG_CHANNEL') === 'daily', 'Logs rotate', 'LOG_STACK is "'.$stack.'" (use daily)');
        $report(is_link(public_path('storage')) || is_dir(public_path('storage')), 'public/storage link exists', 'run php artisan storage:link');

        $this->newLine();
        $this->line($problems === 0 ? 'Everything checked is in order.' : "{$problems} thing(s) to fix.");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
