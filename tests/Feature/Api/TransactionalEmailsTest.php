<?php

namespace Tests\Feature\Api;

use App\Mail\OrderConfirmationMail;
use App\Mail\TransactionalMail;
use App\Models\Category;
use App\Models\EmailTemplate;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Support\Email\EmailBuilder;
use App\Support\Email\SampleData;
use App\Support\Email\TemplateDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User;
use Tests\TestCase;

class TransactionalEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create(['site_name' => 'Anaiza Nest', 'contact_phone' => '+880 1886-004421']);
    }

    /**
     * Runs what was queued "after the response" and forgets it, so a test with several steps sends each email once
     * (a real request is a fresh process; tests share one application).
     */
    private function afterResponse(): void
    {
        $this->app->terminate();
        $this->forgetTerminating();
    }

    private function forgetTerminating(): void
    {
        $property = new \ReflectionProperty($this->app, 'terminatingCallbacks');
        $property->setValue($this->app, []);
    }

    private function product(): Product
    {
        return Product::first() ?? Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Porcelain tea set', 'slug' => 'porcelain-tea-set', 'price' => 1450,
            'stock_quantity' => 100, 'sku' => 'T-1', 'is_active' => true,
        ]);
    }

    private function place(array $overrides = []): Order
    {
        static $n = 0;
        $n++;
        $this->forgetTerminating();

        $response = $this->postJson('/api/orders', array_merge([
            'customer_name' => 'Nusrat Jahan', 'customer_email' => "buyer{$n}@example.com", 'customer_phone' => '0171000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'customer_address' => 'House 1, Road 2', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
            'payment_method' => 'cod', 'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
        ], $overrides))->assertCreated();

        $this->forgetTerminating();

        return Order::with('customer')->findOrFail($response->json('data.id'));
    }

    /** @return Collection<int, TransactionalMail> */
    private function sent(?string $key = null): Collection
    {
        return Mail::sent(TransactionalMail::class, fn (TransactionalMail $mail) => $key === null ? $mail->templateKey !== 'order_confirmation' : $mail->templateKey === $key);
    }

    // ---- 1. nothing depends on a queue worker

    public function test_a_checkout_queues_nothing_and_sends_its_email_and_conversions_after_the_response(): void
    {
        Mail::fake();
        Http::fake();
        MarketingSetting::create([
            'meta_pixel_id' => '1', 'meta_capi_access_token' => 't', 'tiktok_pixel_id' => '2', 'tiktok_events_api_access_token' => 't',
        ]);
        $this->forgetTerminating();

        $this->postJson('/api/orders', [
            'customer_name' => 'A B', 'customer_email' => 'a@example.com', 'customer_phone' => '01711111111', 'customer_address' => 'H1',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(0, DB::table('jobs')->count());
        Mail::assertSent(OrderConfirmationMail::class, 1);
        Http::assertSentCount(2);
    }

    public function test_checkout_still_succeeds_when_the_mail_server_cannot_be_reached(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);
        Log::spy();

        $order = $this->place();

        $this->assertSame('pending', $order->status);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'Order confirmation email failed'))->atLeast()->once();
    }

    public function test_a_customer_with_no_email_gets_no_confirmation(): void
    {
        Mail::fake();

        $this->place(['customer_email' => null]);

        Mail::assertNothingSent();
    }

    // ---- confirmation: brand wording and the invoice

    public function test_the_confirmation_is_in_the_brand_with_the_invoice_attached(): void
    {
        Mail::fake();
        $this->place();

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Thank you. Your order is being packed by hand and will be with you soon.')
                && str_contains($html, 'Packed by hand, sent with care.')
                && str_contains($html, 'Gifted, beautifully.')
                && str_contains($html, '#F6F1E7')   // ivory
                && str_contains($html, '#1B2A41')   // navy
                && str_contains($html, '1px solid #AD8A50') // the thin gold rule
                && str_contains($html, 'background:#661E29') // the one burgundy button
                && substr_count($html, 'background:#661E29') === 1
                && str_contains($html, 'Georgia')
                && str_contains($html, 'Arial')
                && count($mail->attachments()) === 1
                && $mail->hasSubject('Order #'.$mail->order->id.' confirmed - Anaiza Nest');
        });
    }

    public function test_no_wording_can_remove_the_invoice_or_the_branded_frame(): void
    {
        EmailTemplate::create(['key' => 'order_confirmation', 'is_active' => true, 'use_custom_html' => true, 'custom_html' => '<p>Only this.</p>']);
        Mail::fake();
        $this->place();

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Only this.')
                && ! str_contains($html, 'Thank you. Your order is being packed')
                && str_contains($html, 'Gifted, beautifully.')
                && count($mail->attachments()) === 1;
        });
    }

    // ---- 2. status emails

    private function move(Order $order, string $status): void
    {
        $order->refresh()->update(['status' => $status]);
        $this->afterResponse();
    }

    public function test_each_status_email_goes_out_once_with_the_right_details(): void
    {
        Mail::fake();
        $order = $this->place();
        $order->update(['courier_name' => 'Pathao', 'tracking_number' => 'PT-778899']);
        Mail::assertSent(OrderConfirmationMail::class, 1);

        $this->move($order, 'processing');
        $this->assertCount(0, $this->sent('order_shipped'));

        $this->move($order, 'shipped');
        $shipped = $this->sent('order_shipped');
        $this->assertCount(1, $shipped);
        $html = $shipped->first()->render();
        $this->assertStringContainsString('Pathao', $html);
        $this->assertStringContainsString('PT-778899', $html);
        $this->assertStringContainsString('Nusrat Jahan', $html);
        $this->assertStringContainsString("order #{$order->id} is on its way", $html);
        $this->assertStringContainsString('Packed by hand, sent with care.', $html);
        $this->assertTrue($shipped->first()->hasTo($order->customer->email));
        $this->assertTrue($shipped->first()->hasSubject("Your order #{$order->id} has shipped - Anaiza Nest"));
        $this->assertCount(0, $shipped->first()->attachments());

        $this->move($order, 'delivered');
        $this->assertCount(1, $this->sent('order_delivered'));
        $this->assertTrue($this->sent('order_delivered')->first()->hasSubject("Your order #{$order->id} has been delivered - Anaiza Nest"));

        $this->move($order, 'cancelled');
        $this->assertCount(1, $this->sent('order_cancelled'));
        $this->assertTrue($this->sent('order_cancelled')->first()->hasSubject("Your order #{$order->id} has been cancelled - Anaiza Nest"));
    }

    public function test_changing_back_and_forth_never_sends_a_status_email_twice(): void
    {
        Mail::fake();
        $order = $this->place();

        foreach (['shipped', 'processing', 'shipped', 'delivered', 'shipped', 'delivered', 'cancelled', 'shipped', 'cancelled'] as $status) {
            $this->move($order, $status);
        }

        $this->assertCount(1, $this->sent('order_shipped'));
        $this->assertCount(1, $this->sent('order_delivered'));
        $this->assertCount(1, $this->sent('order_cancelled'));
        $this->assertSame(['shipped', 'delivered', 'cancelled'], array_keys($order->fresh()->emails_sent));
    }

    public function test_statuses_without_an_email_send_nothing(): void
    {
        Mail::fake();
        $order = $this->place();

        $this->move($order, 'processing');
        $this->move($order, 'pending');

        $this->assertCount(0, $this->sent());
    }

    public function test_a_customer_with_no_email_gets_no_status_email_and_nothing_is_recorded(): void
    {
        Mail::fake();
        $order = $this->place(['customer_email' => null]);

        $this->move($order, 'shipped');

        $this->assertCount(0, $this->sent());
        $this->assertNull($order->fresh()->emails_sent);
    }

    public function test_an_order_that_was_already_shipped_before_this_feature_is_not_emailed_again(): void
    {
        Mail::fake();
        $order = $this->place();
        $order->forceFill(['status' => 'shipped', 'emails_sent' => ['shipped' => now()->toIso8601String()]])->saveQuietly();

        $this->move($order, 'processing');
        $this->move($order, 'shipped');

        $this->assertCount(0, $this->sent());
    }

    public function test_a_failed_send_is_logged_and_can_be_tried_again_by_the_next_change(): void
    {
        $order = $this->place();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP down'));
        Log::spy();

        $this->move($order, 'shipped');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'Order shipped email failed'))->once();
        $this->assertNull($order->fresh()->emails_sent, 'A failed send is not recorded as sent.');
    }

    public function test_the_delivered_email_links_every_item_to_its_product_page_for_a_review(): void
    {
        Mail::fake();
        $order = $this->place();
        $order->items()->create(['product_id' => $this->product()->id, 'product_name' => 'Porcelain tea set', 'quantity' => 1, 'price' => 1450]);

        $this->move($order, 'delivered');

        $html = $this->sent('order_delivered')->first()->render();
        $this->assertStringContainsString('How was it?', $html);
        $this->assertStringContainsString('/product/porcelain-tea-set#reviews', $html);
        $this->assertStringContainsString('Review your purchase', $html);
        $this->assertSame(2, substr_count($html, 'Review your purchase'));
    }

    public function test_the_shipped_email_has_no_courier_box_when_there_is_none(): void
    {
        Mail::fake();
        $order = $this->place();

        $this->move($order, 'shipped');

        $html = $this->sent('order_shipped')->first()->render();
        $this->assertStringNotContainsString('Tracking number:', $html);
        $this->assertStringNotContainsString('Courier:', $html);
    }

    public function test_track_order_returns_the_courier_and_tracking_number(): void
    {
        $order = $this->place();
        $order->update(['courier_name' => 'Pathao', 'tracking_number' => 'PT-1']);

        $this->getJson("/api/orders/lookup?order_id={$order->id}&phone={$order->customer->phone}")
            ->assertOk()
            ->assertJsonPath('data.courier_name', 'Pathao')
            ->assertJsonPath('data.tracking_number', 'PT-1');
    }

    // ---- welcome

    public function test_registering_sends_one_welcome_email(): void
    {
        Mail::fake();
        $this->forgetTerminating();

        $this->postJson('/api/auth/register', [
            'name' => 'Rafi Ahmed', 'email' => 'rafi@example.com', 'password' => 'password123', 'phone' => '01712345678',
            'address' => 'House 3', 'city' => 'Dhaka', 'postal_code' => '1212',
        ])->assertCreated();

        $welcome = $this->sent('welcome');
        $this->assertCount(1, $welcome);
        $this->assertTrue($welcome->first()->hasTo('rafi@example.com'));
        $this->assertTrue($welcome->first()->hasSubject('Welcome to Anaiza Nest'));
        $this->assertStringContainsString('Welcome, Rafi Ahmed.', $welcome->first()->render());
        $this->assertStringContainsString('Gifted, beautifully.', $welcome->first()->render());
    }

    public function test_a_first_social_sign_in_sends_a_welcome_and_a_return_visit_does_not(): void
    {
        Mail::fake();
        $social = \Mockery::mock(User::class);
        $social->shouldReceive('getEmail')->andReturn('social@example.com');
        $social->shouldReceive('getName')->andReturn('Social Person');
        $social->shouldReceive('getNickname')->andReturn(null);
        Socialite::shouldReceive('driver->stateless->user')->andReturn($social);

        $this->forgetTerminating();
        $this->get('/api/auth/google/callback')->assertRedirect();
        $this->assertCount(1, $this->sent('welcome'));

        $this->forgetTerminating();
        $this->get('/api/auth/google/callback')->assertRedirect();
        $this->assertCount(1, $this->sent('welcome'), 'Signing in again sends nothing.');
    }

    // ---- 3. editable templates

    private function template(string $key, array $attributes): EmailTemplate
    {
        return EmailTemplate::query()->updateOrCreate(['key' => $key], $attributes);
    }

    public function test_an_inactive_or_missing_template_uses_the_built_in_wording(): void
    {
        $this->template('order_shipped', ['is_active' => false, 'subject' => 'Ignored subject', 'intro_html' => '<p>Ignored</p>']);
        $sample = SampleData::order();

        $built = EmailBuilder::build('order_shipped', $sample, $sample->customer, SiteSetting::first());

        $this->assertStringContainsString('has shipped', $built['subject']);
        $this->assertStringNotContainsString('Ignored', $built['html']);
    }

    public function test_active_wording_is_used_and_blank_fields_fall_back_one_by_one(): void
    {
        $this->template('order_shipped', [
            'is_active' => true, 'subject' => 'On its way: #{{order_number}}', 'intro_html' => '<p>Hi {{customer_name}}, via {{courier_name}} ({{tracking_number}}).</p>',
            'closing_html' => null, 'footer_note' => 'Call us on {{site_name}} support.',
        ]);
        $sample = SampleData::order();

        $built = EmailBuilder::build('order_shipped', $sample, $sample->customer, SiteSetting::first());

        $this->assertSame('On its way: #1042', $built['subject']);
        $this->assertStringContainsString('Hi Sample customer, via Sample Courier (SC123456789).', $built['html']);
        $this->assertStringContainsString('Packed by hand, sent with care.', $built['html'], 'Blank closing falls back to the built-in one.');
        $this->assertStringContainsString('Call us on Anaiza Nest support.', $built['html']);
        $this->assertStringContainsString('Sample tea set for two', $built['html'], 'The items table still follows the intro.');
    }

    public function test_block_tokens_render_the_real_tables_where_the_wording_puts_them(): void
    {
        $this->template('order_confirmation', [
            'is_active' => true, 'intro_html' => '<p>Start</p>{{order_totals}}<p>Then</p>{{order_items_table}}',
        ]);
        $sample = SampleData::order();

        $html = EmailBuilder::build('order_confirmation', $sample, $sample->customer, SiteSetting::first())['html'];

        $this->assertLessThan(strpos($html, 'Sample tea set for two'), strpos($html, 'Subtotal'), 'Totals come before the items, as the wording says.');
        $this->assertSame(1, substr_count($html, 'Subtotal'), 'A block the wording placed is not added again.');
        $this->assertStringContainsString('৳2,980', $html);
    }

    public function test_custom_html_replaces_the_body_and_a_missing_or_unknown_placeholder_is_harmless(): void
    {
        $this->template('order_delivered', [
            'is_active' => true, 'use_custom_html' => true,
            'custom_html' => '<h1>Delivered #{{order_number}}</h1><p>{{customer_name}} {{no_such_thing}}</p>{{order_items_table}}',
        ]);
        $sample = SampleData::order();

        $built = EmailBuilder::build('order_delivered', $sample, $sample->customer, SiteSetting::first());

        $this->assertStringContainsString('<h1>Delivered #1042</h1>', $built['html']);
        $this->assertStringContainsString('{{no_such_thing}}', $built['html']);
        $this->assertStringContainsString('Sample ceramic mug', $built['html']);
        $this->assertStringNotContainsString('Review your purchase', $built['html'], 'No review block, because the custom HTML did not ask for one.');
        $this->assertStringContainsString('Gifted, beautifully.', $built['html']);
        $this->assertStringNotContainsString('background:#661E29', $built['html'], 'Custom HTML replaces the button too.');
    }

    public function test_a_customers_name_cannot_inject_html_into_an_email(): void
    {
        $sample = SampleData::order();
        $sample->customer->name = '<script>alert(1)</script>Eve';

        $built = EmailBuilder::build('order_shipped', $sample, $sample->customer, SiteSetting::first());

        $this->assertStringNotContainsString('<script>alert(1)', $built['html']);
        $this->assertStringContainsString('&lt;script&gt;', $built['html']);
    }

    public function test_the_brand_voice_defaults_say_nothing_the_kit_does_not(): void
    {
        foreach (TemplateDefinitions::KEYS as $key) {
            $defaults = TemplateDefinitions::defaults($key);
            $text = strtolower(strip_tags(implode(' ', $defaults)));

            foreach (['!', 'amazing', 'luxury', 'best quality', 'hurry', 'exclusive', 'must-have', 'wow'] as $banned) {
                $this->assertStringNotContainsString($banned, $text, "{$key} must not contain '{$banned}'");
            }
        }
    }

    public function test_the_invoice_is_plain_and_one_colour_with_the_one_colour_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('site/mono.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        SiteSetting::query()->update(['logo_mono' => 'site/mono.png', 'logo_navy' => 'site/navy.png']);
        $order = $this->place();

        $html = view('pdf.invoice', ['order' => $order->load(['customer', 'items']), 'siteSetting' => SiteSetting::first()])->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Invoice', $html);
        foreach (['#AD8A50', '#661E29', '#1B2A41', '#F6F1E7', 'text-transform', 'background'] as $decoration) {
            $this->assertStringNotContainsString($decoration, $html);
        }
    }
}
