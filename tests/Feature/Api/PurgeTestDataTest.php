<?php

namespace Tests\Feature\Api;

use App\Models\AbandonedCheckout;
use App\Models\Banner;
use App\Models\BlockedContact;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Faq;
use App\Models\HomepageSection;
use App\Models\IntegrationSetting;
use App\Models\MarketingSetting;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Page;
use App\Models\PaymentSetting;
use App\Models\ProductReview;
use App\Models\Role;
use App\Models\ShippingZone;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Data\TestDataPurge;
use App\Support\System\SystemEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class PurgeTestDataTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    /** Tables the purge empties completely. */
    private const EMPTIED = [
        'product_reviews', 'courier_events', 'order_items', 'orders', 'abandoned_checkouts', 'customers', 'newsletter_subscribers',
        'checkout_daily_counts', 'landing_page_stats', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens',
    ];

    /** Tables (and settings) that must come out exactly as they went in. */
    private const KEPT = [
        'products', 'product_variants', 'product_images', 'categories', 'brands', 'pages', 'faqs', 'banners', 'homepage_sections',
        'site_settings', 'payment_settings', 'marketing_settings', 'integration_settings', 'courier_settings', 'users', 'roles',
        'email_templates', 'shipping_zones', 'blocked_contacts', 'contact_submissions', 'email_opt_outs', 'migrations',
    ];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    /** A test shop: everything the purge keeps, and one of everything it removes. */
    private function seedShop(): void
    {
        Mail::fake();

        $category = Category::create(['name' => 'Trays', 'slug' => 'trays']);
        $product = AdminRecords::product(['category_id' => $category->id]);
        $product->images()->create(['url' => 'products/a.png', 'sort_order' => 0]);
        $product->variants()->create(['name' => 'Size', 'value' => 'L', 'price' => 100, 'stock_quantity' => 3, 'sku' => 'V-L']);

        foreach ([Brand::class, Banner::class, Faq::class, HomepageSection::class, Page::class, ShippingZone::class, BlockedContact::class, ContactSubmission::class, Role::class, EmailTemplate::class] as $model) {
            AdminRecords::for($model);
        }

        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        PaymentSetting::create(['bkash_number' => '01711111111']);
        MarketingSetting::create(['meta_pixel_id' => '123456789012345']);
        IntegrationSetting::create(['mailer' => 'smtp', 'smtp_host' => 'smtp.example.test']);
        DB::table('email_opt_outs')->insert(['email' => 'optout@example.test', 'source' => 'link', 'created_at' => now()]);
        $admin = User::factory()->create();

        // What the purge removes.
        $customer = AdminRecords::customer();
        $order = AdminRecords::order(['customer_id' => $customer->id, 'payment_method' => 'bkash', 'payment_trx_id' => 'TRX123', 'payment_status' => 'awaiting_verification']);
        AdminRecords::order(['customer_id' => $customer->id]);
        DB::table('courier_events')->insert(['order_id' => $order->id, 'courier' => 'steadfast', 'source' => 'admin', 'event_type' => 'note', 'created_at' => now()]);
        ProductReview::create(['product_id' => $product->id, 'customer_id' => $customer->id, 'order_id' => $order->id, 'name' => 'Rafi', 'rating' => 5, 'body' => 'Fine.']);
        AdminRecords::for(AbandonedCheckout::class);
        NewsletterSubscriber::create(['email' => 'tester@example.test']);
        DB::table('checkout_daily_counts')->insert(['day' => '2026-10-20', 'stage' => 'cart', 'visitors' => 4]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'x', 'queue' => 'x', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $customer->createToken('t');
        $admin->createToken('t');
        SystemEvents::record('mail_failed', 'could not send');
        SystemEvents::mark('scheduler', 'ran');

        // Coupons: one of the owner's (used in a test order), one made for an abandoned checkout.
        $coupon = Coupon::create(['code' => 'WELCOME10', 'discount_type' => 'percent', 'amount' => 10, 'is_active' => true]);
        DB::table('coupons')->where('id', $coupon->id)->update(['used_count' => 3, 'used_at' => now(), 'used_order_id' => $order->id]);
        Coupon::create(['code' => 'COMEBACK1', 'discount_type' => 'fixed', 'amount' => 50, 'is_active' => true, 'source' => 'recovery', 'abandoned_checkout_id' => 1]);
    }

    /** @return array<string, string> a fingerprint of each table's rows */
    private function fingerprint(array $tables): array
    {
        $prints = [];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $prints[$table] = md5(json_encode(DB::table($table)->orderBy('id')->get()->all()) ?: '');
            }
        }

        return $prints;
    }

    // ---- the purge itself

    public function test_the_dry_run_counts_and_changes_nothing(): void
    {
        $this->seedShop();
        $before = $this->fingerprint(array_merge(self::EMPTIED, self::KEPT, ['coupons', 'personal_access_tokens', 'system_events']));

        $counts = array_column(TestDataPurge::counts(), 'rows', 'table');

        $this->assertSame(2, $counts['orders']);
        $this->assertSame(2, $counts['order_items']);
        $this->assertSame(1, $counts['customers']);
        $this->assertSame(1, $counts['product_reviews']);
        $this->assertSame(1, $counts['newsletter_subscribers']);
        $this->assertSame($before, $this->fingerprint(array_merge(self::EMPTIED, self::KEPT, ['coupons', 'personal_access_tokens', 'system_events'])));
    }

    public function test_the_purge_empties_the_test_tables_and_leaves_the_rest_exactly_as_it_was(): void
    {
        $this->seedShop();
        $kept = $this->fingerprint(self::KEPT);
        $adminTokens = DB::table('personal_access_tokens')->where('tokenable_type', User::class)->count();

        TestDataPurge::run();

        foreach (self::EMPTIED as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }

        $this->assertSame($kept, $this->fingerprint(self::KEPT), 'kept tables are untouched');
        $this->assertSame(1, User::query()->count(), 'the admin user stays');

        // Sign-ins: customers' go, an admin's stays.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_type', Customer::class)->count());
        $this->assertSame($adminTokens, DB::table('personal_access_tokens')->where('tokenable_type', User::class)->count());

        // Coupons: the owner's stays, with its used marks cleared; the one made for a checkout goes.
        $this->assertSame(['WELCOME10'], DB::table('coupons')->pluck('code')->all());
        $coupon = DB::table('coupons')->first();
        $this->assertSame([0, null, null], [(int) $coupon->used_count, $coupon->used_at, $coupon->used_order_id]);

        // Notes: the mail failure goes, the scheduler heartbeat stays.
        $this->assertSame(0, DB::table('system_events')->where('type', 'mail_failed')->count());
        $this->assertSame(1, DB::table('system_events')->where('type', 'scheduler')->count());
    }

    public function test_the_next_order_starts_at_1_or_where_you_say(): void
    {
        $this->seedShop();
        $this->assertGreaterThan(1, Order::query()->max('id'));

        TestDataPurge::run();
        $first = AdminRecords::order();
        $this->assertSame(1, $first->id);

        TestDataPurge::run(5000);
        $this->assertSame(5000, AdminRecords::order()->id);
        $this->assertSame(5001, AdminRecords::order()->id);
    }

    public function test_it_is_all_or_nothing(): void
    {
        $this->seedShop();
        $before = $this->fingerprint(array_merge(self::EMPTIED, self::KEPT));

        // A rule in the database that refuses to let the last-but-one step finish.
        DB::statement("CREATE TRIGGER refuse_newsletter_delete BEFORE DELETE ON newsletter_subscribers BEGIN SELECT RAISE(ABORT, 'refused'); END");

        try {
            TestDataPurge::run();
            $this->fail('the purge should have failed');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }

        $this->assertSame($before, $this->fingerprint(array_merge(self::EMPTIED, self::KEPT)), 'nothing was deleted');
        $this->assertSame(2, Order::query()->count());
    }

    // ---- the command

    public function test_the_command_dry_run_prints_counts_only(): void
    {
        $this->seedShop();
        Customer::query()->update(['name' => 'Zubaida Unique-Name', 'phone' => '01799999999']);

        $this->artisan('data:purge-test', ['--dry-run' => true])
            ->expectsOutputToContain('2  orders')
            ->expectsOutputToContain('The next order number will be 1.')
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->doesntExpectOutputToContain('Zubaida')
            ->doesntExpectOutputToContain('01799999999')
            ->doesntExpectOutputToContain('tester@example.test')
            ->assertSuccessful();

        $this->assertSame(2, Order::query()->count());
    }

    public function test_without_force_nothing_is_deleted(): void
    {
        $this->seedShop();

        $this->artisan('data:purge-test')->expectsOutputToContain('add --force')->assertSuccessful();

        $this->assertSame(2, Order::query()->count());
    }

    public function test_a_bad_start_number_or_both_flags_are_refused(): void
    {
        $this->artisan('data:purge-test', ['--dry-run' => true, '--start-order-number' => '0'])->assertFailed();
        $this->artisan('data:purge-test', ['--dry-run' => true, '--start-order-number' => 'abc'])->assertFailed();
        $this->artisan('data:purge-test', ['--dry-run' => true, '--force' => true])->assertFailed();
    }

    public function test_a_real_run_that_cannot_copy_the_database_deletes_nothing(): void
    {
        $this->seedShop();

        $this->artisan('data:purge-test', ['--force' => true])->expectsOutputToContain('Nothing was deleted.')->assertFailed();

        $this->assertSame(2, Order::query()->count());
    }

    public function test_a_real_run_copies_the_database_first_then_deletes_and_starts_numbering_again(): void
    {
        $this->seedShop();
        $fake = storage_path('framework/testing/fake-'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($fake));
        File::put($fake, 'sqlite stand-in');
        $this->cleanup[] = $fake;
        config(['database.connections.sqlite.database' => $fake]);
        $before = glob(storage_path('backups/before-purge-test-*.sqlite')) ?: [];

        $this->artisan('data:purge-test', ['--force' => true, '--start-order-number' => '2500'])
            ->expectsOutputToContain('Database copied to:')
            ->expectsOutputToContain('The next order number is 2500.')
            ->assertSuccessful();

        $copies = array_values(array_diff(glob(storage_path('backups/before-purge-test-*.sqlite')) ?: [], $before));
        $this->cleanup = array_merge($this->cleanup, $copies);
        $this->assertCount(1, $copies);
        $this->assertSame('sqlite stand-in', file_get_contents($copies[0]));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(2500, AdminRecords::order()->id);
    }
}
