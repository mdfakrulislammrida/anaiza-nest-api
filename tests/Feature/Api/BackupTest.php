<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\SystemHealth;
use App\Mail\ShopAlertMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Backup\BackupService;
use App\Support\System\SystemEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Real commits and a real database file copy: VACUUM INTO cannot run inside the wrapping transaction the other tests use,
 * so this class migrates a fresh database itself (the in-memory one goes away with the process).
 */
class BackupTest extends TestCase
{
    private string $directory;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'anaiza-backup-test-'.bin2hex(random_bytes(4));
        $this->directory = $base.DIRECTORY_SEPARATOR.'backups';
        $this->uploads = $base.DIRECTORY_SEPARATOR.'public';
        mkdir($this->directory, 0775, true);
        mkdir($this->uploads.DIRECTORY_SEPARATOR.'products', 0775, true);

        config([
            'backup.directory' => $this->directory,
            'backup.files_root' => $this->uploads,
            'backup.min_free_mb' => 1,
            'backup.sftp.enabled' => false,
        ]);

        Mail::fake();

        $category = Category::create(['name' => 'Mugs', 'slug' => 'mugs']);
        Product::create(['category_id' => $category->id, 'name' => 'Glazed mug', 'slug' => 'glazed-mug', 'price' => 600, 'stock_quantity' => 9, 'sku' => 'MUG', 'is_active' => true]);
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'contact_email' => 'owner@example.com']);
        file_put_contents($this->uploads.DIRECTORY_SEPARATOR.'products'.DIRECTORY_SEPARATOR.'mug.jpg', 'not really a picture');
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory, $this->uploads.DIRECTORY_SEPARATOR.'products', $this->uploads] as $dir) {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($dir);
        }
        @rmdir(dirname($this->directory));

        Carbon::setTestNow();
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }

    private function fakeBackup(string $kind, string $stamp): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR."anaiza-{$kind}-{$stamp}.zip", 'x');
    }

    private function names(): array
    {
        return array_column((new BackupService)->all(), 'name');
    }

    public function test_a_backup_is_a_zip_whose_database_can_be_restored(): void
    {
        $made = (new BackupService)->run('manual');

        $this->assertFileExists($made['path']);
        $this->assertMatchesRegularExpression('/^anaiza-manual-\d{8}-\d{6}\.zip$/', $made['name']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($made['path']));

        $restored = $this->directory.DIRECTORY_SEPARATOR.'restored.sqlite';
        file_put_contents($restored, $zip->getFromName('database.sqlite'));

        $pdo = new \PDO('sqlite:'.$restored);
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('Glazed mug', $pdo->query('select name from products')->fetchColumn());

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame(1, $manifest['products']);
        $this->assertSame(1, $manifest['picture_files']);

        $this->assertSame('not really a picture', $zip->getFromName('files/products/mug.jpg'));
        $zip->close();
        $pdo = null; // let go of the file before it is removed (Windows will not delete an open file)
        unlink($restored);
    }

    public function test_a_backup_never_contains_the_env_file_or_a_stray_partial(): void
    {
        (new BackupService)->run('daily');

        $zip = new ZipArchive;
        $zip->open($this->directory.DIRECTORY_SEPARATOR.$this->names()[0]);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('.env', $entry);
        }
        $this->assertSame([], glob($this->directory.DIRECTORY_SEPARATOR.'*.partial'));
        $this->assertSame([], glob($this->directory.DIRECTORY_SEPARATOR.'.snapshot-*'));
    }

    public function test_pictures_can_be_left_out(): void
    {
        config(['backup.include_files' => false]);

        $made = (new BackupService)->run('manual');

        $zip = new ZipArchive;
        $zip->open($made['path']);
        $this->assertFalse($zip->locateName('files/products/mug.jpg'));
        $zip->close();
    }

    public function test_old_backups_are_removed_by_kind(): void
    {
        for ($day = 1; $day <= 9; $day++) {
            $this->fakeBackup('daily', sprintf('202609%02d-023000', $day));
        }
        for ($week = 1; $week <= 6; $week++) {
            $this->fakeBackup('weekly', sprintf('202608%02d-023000', $week));
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->fakeBackup('manual', sprintf('202607%02d-101010', $i));
        }

        $removed = (new BackupService)->prune();

        $kept = $this->names();
        $this->assertCount(7 + 4 + 3, $kept);
        $this->assertCount(2 + 2 + 2, $removed);
        $this->assertContains('anaiza-daily-20260909-023000.zip', $kept, 'the newest is kept');
        $this->assertNotContains('anaiza-daily-20260901-023000.zip', $kept, 'the oldest goes');
        $this->assertContains('anaiza-weekly-20260806-023000.zip', $kept);
        $this->assertNotContains('anaiza-weekly-20260801-023000.zip', $kept);
    }

    public function test_only_our_own_backups_can_be_named(): void
    {
        $this->fakeBackup('daily', '20260101-020000');
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'secret.txt', 'secret');
        file_put_contents(dirname($this->directory).DIRECTORY_SEPARATOR.'outside.zip', 'outside');

        $service = new BackupService;

        $this->assertNotNull($service->pathFor('anaiza-daily-20260101-020000.zip'));
        $this->assertNull($service->pathFor('secret.txt'));
        $this->assertNull($service->pathFor('../outside.zip'));
        $this->assertNull($service->pathFor('..\\outside.zip'));
        $this->assertNull($service->pathFor('anaiza-daily-20260101-020000.zip/../../outside.zip'));
        $this->assertNull($service->pathFor('anaiza-daily-20990101-020000.zip'), 'a name that is not there');

        unlink($this->directory.DIRECTORY_SEPARATOR.'secret.txt');
        unlink(dirname($this->directory).DIRECTORY_SEPARATOR.'outside.zip');
    }

    public function test_a_backup_is_refused_when_the_disk_is_too_full_and_nothing_is_written(): void
    {
        config(['backup.min_free_mb' => PHP_INT_MAX / 1048576 / 4]);

        try {
            (new BackupService)->run('daily');
            $this->fail('A backup should not be attempted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Not enough free disk space', $e->getMessage());
        }

        $this->assertSame([], $this->names());
        $this->assertSame([], glob($this->directory.DIRECTORY_SEPARATOR.'.snapshot-*'));
    }

    public function test_the_off_server_copy_is_off_by_default_and_says_so_plainly_when_it_cannot_run(): void
    {
        $this->assertFalse((bool) config('backup.sftp.enabled'), 'off unless .env turns it on');
        $this->assertNull((new BackupService)->run('manual')['offsite']);

        config(['backup.sftp.enabled' => true, 'backup.sftp.host' => 'backup.example.com', 'backup.sftp.username' => 'u']);

        try {
            (new BackupService)->run('manual');
            $this->fail('Expected the missing package to be reported.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('league/flysystem-sftp-v3', $e->getMessage());
            $this->assertStringContainsString('backup on this server was made', $e->getMessage());
        }

        $this->assertNotEmpty($this->names(), 'the local backup exists whether or not the copy went anywhere');
    }

    public function test_the_nightly_command_records_success(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $last = SystemEvents::latest('backup');
        $this->assertSame('ok', $last->level);
        $this->assertStringContainsString('anaiza-daily-', $last->message);
        $this->assertCount(1, $this->names());
    }

    public function test_on_a_sunday_the_nightly_backup_also_keeps_a_weekly_one(): void
    {
        // 2026-10-18 is a Sunday in Dhaka.
        Carbon::setTestNow(Carbon::parse('2026-10-18 10:00:00', 'Asia/Dhaka'));

        $this->artisan('backup:run')->assertSuccessful();

        $kinds = array_column((new BackupService)->all(), 'kind');
        sort($kinds);
        $this->assertSame(['daily', 'weekly'], $kinds);
    }

    public function test_a_failed_backup_is_recorded_and_the_owner_is_told(): void
    {
        config(['backup.min_free_mb' => PHP_INT_MAX / 1048576 / 4, 'backup.enabled' => true]);

        $this->artisan('backup:run')->assertFailed();

        $last = SystemEvents::latest('backup');
        $this->assertSame('error', $last->level);
        $this->assertNotNull(SystemEvents::latest('backup_failed'));

        Mail::assertSent(ShopAlertMail::class, fn (ShopAlertMail $mail) => $mail->hasTo('owner@example.com')
            && $mail->alertSubject === 'The nightly backup did not finish'
            && str_contains(implode("\n", $mail->lines), 'Not enough free disk space'));
    }

    public function test_back_up_now_in_the_admin_makes_a_manual_backup(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));

        Livewire::test(SystemHealth::class)->callAction('backUpNow');

        $kinds = array_column((new BackupService)->all(), 'kind');
        $this->assertSame(['manual'], $kinds);
        $this->assertSame('ok', SystemEvents::latest('backup')->level);
    }

    public function test_switched_off_does_nothing(): void
    {
        config(['backup.enabled' => false]);

        $this->artisan('backup:run')->assertSuccessful();

        $this->assertSame([], $this->names());
    }

    public function test_a_backup_does_not_change_the_live_data(): void
    {
        $before = Order::count() + Product::count();

        (new BackupService)->run('manual');

        $this->assertSame($before, Order::count() + Product::count());
    }
}
