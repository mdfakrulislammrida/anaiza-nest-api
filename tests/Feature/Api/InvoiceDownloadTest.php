<?php

namespace Tests\Feature\Api;

use App\Mail\OrderConfirmationMail;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\InvoicePdf;
use App\Support\System\HealthChecks;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The invoice PDF can be downloaded: by a guest with the signed link that comes with the order, by a signed-in customer for their own orders, and by an admin.
 * Whatever goes wrong is JSON (never an HTML page, never a bare 500), and a failure is logged without any personal detail.
 */
class InvoiceDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create(['site_name' => 'Anaiza Nest', 'contact_phone' => '+880 1886-004421']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function product(): Product
    {
        return Product::first() ?? Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Porcelain tea set', 'slug' => 'porcelain-tea-set', 'price' => 1450, 'stock_quantity' => 100, 'sku' => 'T-1', 'is_active' => true,
        ]);
    }

    /** The order as the checkout answer describes it. */
    private function place(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return $this->postJson('/api/orders', array_merge([
            'customer_name' => 'Nusrat Jahan', 'customer_email' => "buyer{$n}@example.test", 'customer_phone' => '0171000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'customer_address' => 'House 1, Road 2', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
            'payment_method' => 'cod', 'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
        ], $overrides))->assertCreated()->json('data');
    }

    private function assertPdf($response, string $filename): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('attachment; filename="'.$filename.'"', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    private function assertJsonError($response, int $status, string $code): void
    {
        $response->assertStatus($status);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'), 'never an HTML page');
        $this->assertSame($code, $response->json('code'));
        $this->assertNotEmpty($response->json('message'));
    }

    // ---- a guest, right after checkout

    public function test_the_order_answer_carries_a_link_that_downloads_the_invoice(): void
    {
        $order = $this->place();

        $this->assertStringContainsString('/api/orders/'.$order['id'].'/invoice?', $order['invoice_url']);
        $this->assertStringNotContainsString('0171000', $order['invoice_url'], 'no phone number or name in the link');

        // A plain GET with no Accept header, as a browser link does.
        $this->assertPdf($this->get($order['invoice_url']), 'AnaizaNest-Invoice-'.$order['id'].'.pdf');
    }

    public function test_track_order_hands_out_a_link_once_the_phone_number_is_right(): void
    {
        $order = $this->place(['customer_phone' => '01711223344']);

        $found = $this->getJson('/api/orders/lookup?'.http_build_query(['order_id' => $order['id'], 'phone' => '+8801711223344']))->assertOk()->json('data');
        $this->assertPdf($this->get($found['invoice_url']), 'AnaizaNest-Invoice-'.$order['id'].'.pdf');

        $this->getJson('/api/orders/lookup?'.http_build_query(['order_id' => $order['id'], 'phone' => '01799999999']))->assertNotFound()->assertJsonMissingPath('data.invoice_url');
    }

    public function test_a_link_that_is_wrong_changed_or_old_is_a_403_in_json(): void
    {
        $first = $this->place();
        $second = $this->place();

        // the signature is cut off, altered, or belongs to another order
        $this->assertJsonError($this->get(preg_replace('/signature=[^&]+/', 'signature=abc123', $first['invoice_url'])), 403, 'invoice_link_invalid');
        $this->assertJsonError($this->get(explode('&signature=', $first['invoice_url'])[0]), 403, 'invoice_link_invalid');
        $this->assertJsonError($this->get(str_replace('/orders/'.$first['id'].'/', '/orders/'.$second['id'].'/', $first['invoice_url'])), 403, 'invoice_link_invalid');

        // older than 7 days
        Carbon::setTestNow(now()->addDays(8));
        $this->assertJsonError($this->get($first['invoice_url']), 403, 'invoice_link_invalid');
    }

    public function test_a_signed_link_to_an_order_that_does_not_exist_is_a_404_in_json(): void
    {
        $url = URL::temporarySignedRoute('api.orders.invoice', now()->addHour(), ['order' => 99999]);

        $this->assertJsonError($this->get($url), 404, 'order_not_found');
    }

    public function test_an_order_with_no_email_a_coupon_variants_and_long_or_bangla_names_still_makes_a_pdf(): void
    {
        $product = $this->product();
        $customer = Customer::create(['name' => 'ননী Rahman', 'email' => 'x@example.test', 'phone' => '01712345678', 'password' => 'x']);
        $order = Order::create([
            'customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 3000, 'delivery_fee' => 80, 'total' => 2580, 'discount_amount' => 500, 'coupon_code' => 'WELCOME500', 'payment_method' => 'cod',
            'ship_name' => 'ননী রহমান', 'ship_email' => null, 'ship_phone' => '01712345678', 'ship_address' => str_repeat('বাড়ি ১২, রোড ৫, ', 10), 'ship_district' => 'Dhaka', 'ship_division' => 'Dhaka',
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => str_repeat('Hand-painted porcelain gongfu tea set with bamboo tray ', 4), 'quantity' => 2, 'price' => 1000, 'sku' => 'T-1', 'variant_name' => 'রঙ', 'variant_value' => str_repeat('গাঢ় নীল ', 12)]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'চায়ের কাপ', 'quantity' => 1, 'price' => 1000, 'sku' => 'T-2']);
        $url = URL::temporarySignedRoute('api.orders.invoice', now()->addHour(), ['order' => $order->id]);

        $this->assertPdf($this->get($url), 'AnaizaNest-Invoice-'.$order->id.'.pdf');
    }

    // ---- a signed-in customer

    public function test_a_signed_in_customer_downloads_their_own_invoice_and_nobody_elses(): void
    {
        $mine = $this->place(['customer_email' => 'mine@example.test', 'customer_phone' => '01733334444']);
        $theirs = $this->place(['customer_email' => 'theirs@example.test', 'customer_phone' => '01755556666']);
        $token = Customer::query()->findOrFail($mine['customer']['id'])->createToken('test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $this->assertPdf($this->withHeaders($headers)->get('/api/account/orders/'.$mine['id'].'/invoice'), 'AnaizaNest-Invoice-'.$mine['id'].'.pdf');
        $this->assertJsonError($this->withHeaders($headers)->get('/api/account/orders/'.$theirs['id'].'/invoice'), 404, 'order_not_found');
        $this->assertJsonError($this->withHeaders($headers)->get('/api/account/orders/99999/invoice'), 404, 'order_not_found');
    }

    public function test_the_account_address_needs_a_sign_in_and_says_so_in_json(): void
    {
        $order = $this->place();

        $this->get('/api/account/orders/'.$order['id'].'/invoice')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->get('/api/account/orders/'.$order['id'].'/invoice', ['Authorization' => 'Bearer not-a-real-token'])->assertUnauthorized();
    }

    // ---- the admin

    public function test_an_admin_downloads_any_invoice_and_a_visitor_is_sent_to_sign_in(): void
    {
        $order = $this->place();

        $this->get('/admin/orders/'.$order['id'].'/invoice')->assertRedirect();

        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->assertPdf($this->get('/admin/orders/'.$order['id'].'/invoice'), 'AnaizaNest-Invoice-'.$order['id'].'.pdf');
    }

    // ---- the browser on another origin

    public function test_the_file_name_can_be_read_from_the_storefront_origin(): void
    {
        $order = $this->place();

        $response = $this->withHeaders(['Origin' => 'https://anaizanest.com'])->get($order['invoice_url']);

        $this->assertPdf($response, 'AnaizaNest-Invoice-'.$order['id'].'.pdf');
        $response->assertHeader('Access-Control-Allow-Origin', 'https://anaizanest.com');
        $this->assertStringContainsString('Content-Disposition', (string) $response->headers->get('Access-Control-Expose-Headers'));

        // an error answer is readable from the storefront too
        $error = $this->withHeaders(['Origin' => 'https://anaizanest.com'])->get(explode('&signature=', $order['invoice_url'])[0]);
        $this->assertJsonError($error, 403, 'invoice_link_invalid');
        $error->assertHeader('Access-Control-Allow-Origin', 'https://anaizanest.com');

        $this->withHeaders(['Origin' => 'https://anaizanest.com', 'Access-Control-Request-Method' => 'GET'])->options('/api/orders/'.$order['id'].'/invoice')->assertNoContent();
    }

    // ---- when it fails

    public function test_a_failure_is_logged_without_personal_detail_and_answered_in_json(): void
    {
        $order = $this->place(['customer_email' => 'private.person@example.test', 'customer_name' => 'Private Person', 'customer_phone' => '01799988877']);
        $lines = [];
        Log::listen(function ($event) use (&$lines): void {
            $lines[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });
        Pdf::shouldReceive('loadView')->andThrow(new \RuntimeException('Unable to write to storage/fonts for private.person@example.test'));

        $response = $this->get($order['invoice_url']);

        $this->assertJsonError($response, 500, 'invoice_failed');
        // This request's own line (the confirmation email made after checkout also needs the PDF, and fails under the same fake, with its own message).
        $log = implode("\n", array_filter($lines, fn (string $line) => str_contains($line, 'The invoice could not be made.')));
        $this->assertStringContainsString('The invoice could not be made.', $log);
        $this->assertStringContainsString('"order_id":'.$order['id'], $log);
        $this->assertStringContainsString('"path":"signed"', $log);
        $this->assertStringContainsString('RuntimeException', $log);
        foreach (['private.person@example.test', 'Private Person', '01799988877'] as $personal) {
            $this->assertStringNotContainsString($personal, $log);
        }
    }

    // ---- the server

    public function test_this_server_has_what_the_invoice_needs_and_the_health_page_says_so(): void
    {
        $this->assertSame([], InvoicePdf::problems());
        $this->assertSame(HealthChecks::OK, HealthChecks::invoices()['status']);
        $this->assertContains('invoices', array_column(HealthChecks::all(), 'key'));
    }

    public function test_too_little_memory_is_reported_in_words(): void
    {
        $problems = InvoicePdf::problems('64M');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('memory limit is 64M', $problems[0]);
        $this->assertStringContainsString('128M', $problems[0]);
    }

    public function test_the_email_attachment_has_the_same_name_as_the_download(): void
    {
        $order = Order::findOrFail($this->place()['id']);

        $attachments = (new OrderConfirmationMail($order->load(['customer', 'items']), SiteSetting::first()))->attachments();

        $this->assertSame('AnaizaNest-Invoice-'.$order->id.'.pdf', $attachments[0]->as);
    }

    // ---- pictures

    public function test_a_logo_on_this_server_is_embedded_without_any_network_and_one_elsewhere_with_a_short_fetch_that_may_fail(): void
    {
        Storage::fake('public');
        Http::fake(['cdn.example.test/*' => Http::response('not a picture', 200, ['Content-Type' => 'text/html'])]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('branding/logo.png', $png);
        $order = Order::findOrFail($this->place()['id'])->load(['customer', 'items']);

        // the address of a file kept on this server: read from the disk
        SiteSetting::first()->update(['logo_mono' => 'https://api.example.test/storage/branding/logo.png']);
        $this->assertStringStartsWith('%PDF', InvoicePdf::make($order)->output());
        Http::assertNothingSent();

        // a picture on another server that answers with something that is not a picture, and one that is not there at all
        SiteSetting::first()->update(['logo_mono' => 'https://cdn.example.test/logo.png']);
        $this->assertStringStartsWith('%PDF', InvoicePdf::make($order)->output());
        Http::assertSentCount(1);

        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->assertStringStartsWith('%PDF', InvoicePdf::make($order)->output());
    }
}
