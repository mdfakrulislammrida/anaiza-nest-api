<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\SystemHealth;
use App\Models\Role;
use App\Models\User;
use App\Support\System\HealthChecks;
use App\Support\System\SystemEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'anaiza-health-test-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
        config(['backup.directory' => $this->directory, 'backup.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    private function check(string $key): array
    {
        return collect(HealthChecks::all())->firstWhere('key', $key);
    }

    // ---- the public monitor address

    public function test_the_health_address_says_ok_and_nothing_else(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_the_health_address_says_down_when_the_database_cannot_be_reached(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \RuntimeException('disk I/O error'));

        $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'down']);
    }

    // ---- the checks

    public function test_the_scheduler_is_fine_only_while_its_heartbeat_is_recent(): void
    {
        $this->assertSame('fail', $this->check('scheduler')['status']);
        $this->assertStringContainsString('cron', $this->check('scheduler')['hint']);

        SystemEvents::mark('scheduler', 'ran');
        $this->assertSame('ok', $this->check('scheduler')['status']);

        $this->travel(11)->minutes();
        $this->assertSame('fail', $this->check('scheduler')['status']);
    }

    public function test_the_backup_check_follows_what_happened(): void
    {
        $this->assertSame('warn', $this->check('backup')['status'], 'nothing made yet');

        $file = $this->directory.DIRECTORY_SEPARATOR.'anaiza-daily-20261012-023000.zip';
        file_put_contents($file, 'zip');
        $this->assertSame('ok', $this->check('backup')['status']);

        touch($file, time() - 40 * 3600);
        $this->assertSame('fail', $this->check('backup')['status'], 'older than 36 hours');

        touch($file, time());
        SystemEvents::mark('backup', 'Not enough free disk space', 'error');
        $check = $this->check('backup');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('Not enough free disk space', $check['detail']);
    }

    public function test_email_is_a_failure_while_mail_only_goes_to_the_log(): void
    {
        config(['mail.default' => 'log']);
        $this->assertSame('fail', $this->check('mail')['status']);

        config(['mail.default' => 'smtp']);
        $this->assertSame('ok', $this->check('mail')['status']);

        SystemEvents::record('mail_failed', 'Order confirmation email: Connection refused');
        $check = $this->check('mail');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('Connection refused', $check['detail']);

        $this->travel(8)->days();
        $this->assertSame('ok', $this->check('mail')['status'], 'old failures stop counting');
    }

    public function test_orders_left_pending_for_a_day_are_flagged(): void
    {
        $this->assertSame('ok', $this->check('orders')['status']);

        $order = AdminRecords::order(['status' => 'pending']);
        $order->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $check = $this->check('orders');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('1 still Pending after a day', $check['detail']);
    }

    public function test_the_database_check_reports_a_healthy_database(): void
    {
        $this->assertContains($this->check('database')['status'], ['ok', 'warn']);
        $this->assertStringContainsString('Healthy', $this->check('database')['detail']);
    }

    public function test_the_overall_result_is_the_worst_one(): void
    {
        $this->assertSame('fail', HealthChecks::overall([['status' => 'ok'], ['status' => 'fail'], ['status' => 'warn']]));
        $this->assertSame('warn', HealthChecks::overall([['status' => 'ok'], ['status' => 'warn']]));
        $this->assertSame('ok', HealthChecks::overall([['status' => 'ok']]));
    }

    // ---- the admin page

    public function test_the_page_lists_the_checks_and_the_backups(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'anaiza-daily-20261012-023000.zip', 'zip');
        $this->actingAs($this->admin());

        Livewire::test(SystemHealth::class)
            ->assertSuccessful()
            ->assertSee('Scheduled jobs')
            ->assertSee('Nightly backup')
            ->assertSee('Disk space')
            ->assertSee('Daily')
            ->assertSee('Download');

        $this->get('/admin/system-health')->assertOk();
    }

    public function test_a_backup_can_be_downloaded_and_nothing_else_can(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'anaiza-daily-20261012-023000.zip', 'zip bytes');
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'notes.txt', 'private');
        $this->actingAs($this->admin());

        Livewire::test(SystemHealth::class)
            ->call('download', 'anaiza-daily-20261012-023000.zip')
            ->assertFileDownloaded('anaiza-daily-20261012-023000.zip', 'zip bytes');

        Livewire::test(SystemHealth::class)
            ->call('download', 'notes.txt')
            ->assertNoFileDownloaded();

        Livewire::test(SystemHealth::class)
            ->call('download', '../../.env')
            ->assertNoFileDownloaded();
    }

    public function test_the_page_needs_its_own_permission(): void
    {
        $limited = User::factory()->create(['role_id' => Role::create(['name' => 'Packer', 'slug' => 'packer', 'permissions' => ['orders.manage']])->id]);
        $this->actingAs($limited)->get('/admin/system-health')->assertForbidden();

        $allowed = User::factory()->create(['role_id' => Role::create(['name' => 'Ops', 'slug' => 'ops', 'permissions' => ['system.manage']])->id]);
        $this->actingAs($allowed)->get('/admin/system-health')->assertOk();
    }

    public function test_a_limited_staff_member_cannot_call_the_download_directly(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'anaiza-daily-20261012-023000.zip', 'zip bytes');
        $limited = User::factory()->create(['role_id' => Role::create(['name' => 'Packer', 'slug' => 'packer', 'permissions' => ['orders.manage']])->id]);

        $this->actingAs($limited);
        Livewire::test(SystemHealth::class)->assertForbidden();
    }
}
