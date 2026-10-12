<?php

namespace Tests\Feature\Api;

use App\Models\BlockedContact;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Rate limits, the bot trap on public forms, buyer blocks, the per-phone daily cap, response headers and CORS.
 */
class AbuseProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id,
            'name' => 'Glazed mug', 'slug' => 'glazed-mug', 'price' => 600, 'stock_quantity' => 500, 'sku' => 'MUG', 'is_active' => true,
        ]);
    }

    private function order(array $overrides = [])
    {
        (new ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        return $this->postJson('/api/orders', array_merge([
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01711234567', 'customer_email' => 'nusrat@example.com',
            'customer_address' => 'House 12, Road 5', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], $overrides));
    }

    private function millisecondsAgo(int $ms): int
    {
        return (int) (microtime(true) * 1000) - $ms;
    }

    // ---- rate limits

    public function test_placing_orders_in_a_burst_is_slowed_with_a_calm_message(): void
    {
        config(['abuse.throttle_by_ip' => true]);
        SiteSetting::query()->update(['max_orders_per_phone_per_day' => 0]);

        for ($i = 0; $i < 6; $i++) {
            $this->order(['customer_phone' => '0171123456'.$i])->assertCreated();
        }

        $this->order(['customer_phone' => '01711234569'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'That is a lot of tries in a short time. Please wait a minute and try again.')
            ->assertHeader('Retry-After');
    }

    public function test_the_order_tracking_lookup_is_limited_per_address(): void
    {
        config(['abuse.throttle_by_ip' => true]);

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/orders/lookup?order_id='.$i.'&phone=0171123456'.$i)->assertStatus(404);
        }

        $this->getJson('/api/orders/lookup?order_id=99&phone=01711234599')->assertStatus(429);
    }

    public function test_the_order_tracking_lookup_is_limited_per_phone_whatever_the_address(): void
    {
        // Address-based counting is off here, so only the phone key can stop this.
        config(['abuse.throttle_by_ip' => false]);

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/orders/lookup?order_id='.$i.'&phone=01711234567')->assertStatus(404);
        }

        // The same number typed another way is still the same number.
        $this->getJson('/api/orders/lookup?order_id=99&phone=%2B8801711234567')->assertStatus(429);
    }

    public function test_the_contact_form_and_newsletter_are_limited_per_address(): void
    {
        config(['abuse.throttle_by_ip' => true]);

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/contact-submissions', ['name' => 'N', 'phone' => '01710000000', 'message' => 'Hello '.$i])->assertCreated();
        }
        $this->postJson('/api/contact-submissions', ['name' => 'N', 'phone' => '01710000000', 'message' => 'Hello again'])->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/newsletter-subscribers', ['email' => "reader{$i}@example.com"])->assertCreated();
        }
        $this->postJson('/api/newsletter-subscribers', ['email' => 'one-more@example.com'])->assertStatus(429);
    }

    public function test_address_based_limits_stand_aside_when_turned_off(): void
    {
        config(['abuse.throttle_by_ip' => false]);

        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/newsletter-subscribers', ['email' => "reader{$i}@example.com"])->assertCreated();
        }
    }

    // ---- the bot trap

    public function test_a_filled_honeypot_is_turned_away_from_checkout_and_nothing_is_stored(): void
    {
        $this->order(['hp_field' => 'http://spam.example'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Something went wrong with that form. Please reload the page and try again, or message us on WhatsApp and we will help.');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Customer::count());
    }

    public function test_a_checkout_submitted_too_fast_is_turned_away(): void
    {
        $this->order(['form_rendered_at' => $this->millisecondsAgo(800)])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_a_checkout_that_took_a_normal_time_is_accepted(): void
    {
        $this->order(['form_rendered_at' => $this->millisecondsAgo(30_000), 'hp_field' => ''])->assertCreated();
    }

    public function test_a_clock_that_is_wrong_is_never_held_against_the_customer(): void
    {
        // A phone set a day ahead reports a "rendered at" in the future.
        $this->order(['form_rendered_at' => $this->millisecondsAgo(-86_400_000)])->assertCreated();
    }

    public function test_the_newsletter_and_contact_form_pretend_to_succeed_for_a_bot(): void
    {
        $this->postJson('/api/newsletter-subscribers', ['email' => 'bot@example.com', 'hp_field' => 'x'])->assertCreated();
        $this->assertDatabaseMissing('newsletter_subscribers', ['email' => 'bot@example.com']);

        $this->postJson('/api/contact-submissions', ['name' => 'Bot', 'phone' => '01710000000', 'message' => 'Buy now', 'hp_field' => 'x'])->assertCreated();
        $this->assertDatabaseMissing('contact_submissions', ['name' => 'Bot']);
    }

    public function test_a_form_with_no_proof_passes_until_it_is_required(): void
    {
        $this->order()->assertCreated();

        config(['abuse.require_form_proof' => true]);

        $this->order(['customer_phone' => '01711234500'])->assertStatus(422);
        $this->order(['customer_phone' => '01711234501', 'form_rendered_at' => $this->millisecondsAgo(20_000)])->assertCreated();
    }

    // ---- blocked buyers and the daily cap

    public function test_a_blocked_phone_cannot_order_however_it_is_typed(): void
    {
        BlockedContact::create(['kind' => BlockedContact::PHONE, 'value' => '+880 1711-234567']);

        $this->assertSame('01711234567', BlockedContact::first()->value, 'stored in the form it is compared in');

        $this->order(['customer_phone' => '017 1123 4567'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_phone'])
            ->assertJsonPath('errors.customer_phone.0', 'We are not able to take this order online just now. Please message us on WhatsApp and we will help you place it.');

        $this->assertSame(0, Order::count());
    }

    public function test_a_blocked_email_and_a_blocked_address_fragment_are_refused(): void
    {
        BlockedContact::create(['kind' => BlockedContact::EMAIL, 'value' => 'Prank@Example.com']);
        BlockedContact::create(['kind' => BlockedContact::ADDRESS, 'value' => 'Road 99 Nowhere']);

        $this->order(['customer_email' => 'prank@example.com'])->assertStatus(422);
        $this->order(['customer_address' => 'Flat 4, road 99 NOWHERE, Dhaka'])->assertStatus(422);
        $this->order()->assertCreated();
    }

    public function test_a_buyer_who_is_not_blocked_is_not_told_about_the_blocklist(): void
    {
        BlockedContact::create(['kind' => BlockedContact::PHONE, 'value' => '01799999999']);

        $this->order(['customer_phone' => '01711234567'])->assertCreated();
    }

    public function test_one_phone_number_has_a_daily_order_limit_set_in_the_admin(): void
    {
        SiteSetting::query()->update(['max_orders_per_phone_per_day' => 2]);

        $this->order()->assertCreated();
        $this->order()->assertCreated();

        $this->order()
            ->assertStatus(422)
            ->assertJsonPath('errors.customer_phone.0', 'We already have a few orders from this number today. If you would like to order more, please message us on WhatsApp and we will help.');

        // Another number is not affected, and the same number typed another way is the same number.
        $this->order(['customer_phone' => '01811111111'])->assertCreated();
        $this->order(['customer_phone' => '+8801711234567'])->assertStatus(422);
    }

    public function test_the_daily_limit_ignores_cancelled_and_older_orders_and_zero_means_no_limit(): void
    {
        SiteSetting::query()->update(['max_orders_per_phone_per_day' => 1]);

        $first = $this->order()->assertCreated()->json('data.id');
        $this->order()->assertStatus(422);

        Order::whereKey($first)->update(['status' => 'cancelled']);
        $this->order()->assertCreated();

        Order::query()->update(['created_at' => now()->subDays(2)]);
        $this->order()->assertCreated();

        SiteSetting::query()->update(['max_orders_per_phone_per_day' => 0]);
        $this->order()->assertCreated();
        $this->order()->assertCreated();
    }

    // ---- response headers, errors, CORS

    public function test_every_api_answer_carries_the_security_headers(): void
    {
        $response = $this->getJson('/api/categories');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
        $this->assertFalse($response->headers->has('X-Powered-By'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'), 'HSTS is off until it is turned on');
    }

    public function test_hsts_is_sent_only_when_turned_on_and_only_over_https(): void
    {
        config(['abuse.hsts_enabled' => true, 'abuse.hsts_max_age' => 600]);

        $this->getJson('/api/categories')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/categories')->assertHeader('Strict-Transport-Security', 'max-age=600');
    }

    public function test_errors_are_in_the_shops_voice_and_never_name_a_model(): void
    {
        $this->getJson('/api/products/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'We could not find that.']);

        $this->getJson('/api/no-such-endpoint')->assertNotFound()->assertJsonPath('message', 'We could not find that.');
    }

    public function test_only_the_shops_own_storefront_may_read_the_api_from_a_browser(): void
    {
        $allowed = config('cors.allowed_origins');
        $this->assertNotContains('*', $allowed);
        $this->assertContains('https://new.anaizanest.com', $allowed);

        $ok = $this->call('OPTIONS', '/api/categories', [], [], [], [
            'HTTP_ORIGIN' => 'https://new.anaizanest.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertSame('https://new.anaizanest.com', $ok->headers->get('Access-Control-Allow-Origin'));

        $other = $this->call('OPTIONS', '/api/categories', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNull($other->headers->get('Access-Control-Allow-Origin'));
    }
}
