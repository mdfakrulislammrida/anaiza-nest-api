<?php

namespace Tests\Feature\Api;

use App\Mail\ShopAlertMail;
use App\Mail\TransactionalMail;
use App\Models\AbandonedCheckout;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Support\Email\EmailBuilder;
use App\Support\Email\SampleData;
use App\Support\Email\TemplateDefinitions;
use App\Support\EmailThumbs;
use App\Support\Recovery\RecoveryEmails;
use App\Support\System\ShopAlerts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** A real picture (a tall rectangle, so the square crop has something to do), as the bytes of a PNG. */
    private function picture(int $r = 40, int $g = 90, int $b = 140, int $width = 600, int $height = 800): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function product(string $name = 'Mug', string $slug = 'mug', bool $withPicture = true): Product
    {
        $product = Product::create([
            'category_id' => Category::firstOrCreate(['slug' => 'mugs'], ['name' => 'Mugs'])->id,
            'name' => $name, 'slug' => $slug, 'price' => 1000, 'stock_quantity' => 20, 'sku' => strtoupper($slug), 'is_active' => true,
        ]);

        if ($withPicture) {
            Storage::disk('public')->put("products/{$slug}.png", $this->picture());
            ProductImage::create(['product_id' => $product->id, 'url' => "products/{$slug}.png", 'sort_order' => 0]);
        }

        return $product;
    }

    private function place(Product $product, ?int $variantId = null): Order
    {
        $id = $this->postJson('/api/orders', [
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01712345678', 'customer_address' => 'House 12',
            'customer_email' => 'nusrat@example.com',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1] + ($variantId ? ['variant_id' => $variantId] : [])],
        ])->assertCreated()->json('data.id');

        return Order::with(['customer', 'items.product'])->findOrFail($id);
    }

    private function html(string $key, Order $order): string
    {
        return EmailBuilder::build($key, $order, $order->customer, SiteSetting::query()->first() ?? new SiteSetting)['html'];
    }

    public function test_an_order_line_keeps_a_128px_jpeg_made_from_the_product_picture(): void
    {
        $order = $this->place($this->product());
        $item = $order->items->first();

        $this->assertStringStartsWith('email-thumbs/', $item->image_path);
        Storage::disk('public')->assertExists($item->image_path);

        [$width, $height, $type] = getimagesizefromstring(Storage::disk('public')->get($item->image_path));
        $this->assertSame([128, 128, IMAGETYPE_JPEG], [$width, $height, $type]);
    }

    public function test_the_thumbnail_is_made_once_and_shared_by_every_order_of_the_piece(): void
    {
        $product = $this->product();

        $first = $this->place($product)->items->first()->image_path;
        $second = $this->place($product)->items->first()->image_path;

        $this->assertSame($first, $second);
        $this->assertCount(1, Storage::disk('public')->files('email-thumbs'));
    }

    public function test_a_transparent_picture_is_flattened_onto_linen_not_black(): void
    {
        $image = imagecreatetruecolor(200, 200);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('products/clear.png', (string) ob_get_clean());

        $path = EmailThumbs::make('products/clear.png');
        $jpeg = imagecreatefromstring(Storage::disk('public')->get($path));
        $rgb = imagecolorsforindex($jpeg, imagecolorat($jpeg, 64, 64));

        $this->assertEqualsWithDelta(0xED, $rgb['red'], 6);
        $this->assertEqualsWithDelta(0xE4, $rgb['green'], 6);
        $this->assertEqualsWithDelta(0xD3, $rgb['blue'], 6);
    }

    public function test_every_email_that_lists_the_order_shows_the_picture_at_64_px(): void
    {
        $order = $this->place($this->product());
        $url = EmailThumbs::url($order->items->first()->image_path);

        $this->assertStringStartsWith('http', $url);

        foreach ([TemplateDefinitions::CONFIRMATION, TemplateDefinitions::SHIPPED, TemplateDefinitions::DELIVERED, TemplateDefinitions::CANCELLED] as $key) {
            $html = $this->html($key, $order);

            $this->assertStringContainsString('src="'.$url.'"', $html, $key);
            $this->assertStringContainsString('width="64" height="64"', $html, $key);
        }
    }

    public function test_the_new_order_note_shows_the_picture_and_keeps_its_plain_text(): void
    {
        $order = $this->place($this->product());
        $url = EmailThumbs::url($order->items->first()->image_path);

        $mail = ShopAlerts::newOrderMail($order);
        $html = $mail->render();

        $this->assertInstanceOf(ShopAlertMail::class, $mail);
        $this->assertStringContainsString('src="'.$url.'"', $html);
        $this->assertStringContainsString('width="64"', $html);
        $this->assertStringContainsString('name="color-scheme" content="light dark"', $html);
        $mail->assertSeeInText('1 x Mug');
        $mail->assertSeeInText('Open the order');
    }

    public function test_the_emails_are_ready_for_a_dark_screen(): void
    {
        $order = $this->place($this->product());

        foreach ([TemplateDefinitions::CONFIRMATION, TemplateDefinitions::SHIPPED, TemplateDefinitions::WELCOME] as $key) {
            $html = $this->html($key, $order);

            $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
            $this->assertStringContainsString('<meta name="supported-color-schemes" content="light dark">', $html);
            $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
            // The header band is a solid navy of its own, and the dark rules keep it, the card and the text apart.
            $this->assertStringContainsString('class="em-band" align="center" bgcolor="#1B2A41"', $html);
            $this->assertMatchesRegularExpression('/\.em-band \{[^}]*background-color: #1B2A41/', $html);
            $this->assertMatchesRegularExpression('/\.em-text[^{]*\{ color: #F6F1E7 !important/', $html);

            // No pure white or pure black anywhere, and no table or cell left without a colour of its own.
            $this->assertDoesNotMatchRegularExpression('/#(fff|ffffff|000|000000)\b/i', $html, $key);
            $this->assertDoesNotMatchRegularExpression('/:\s*(white|black)\b/i', $html, $key);

            preg_match_all('/<(table|td|th)\b[^>]*>/i', $html, $tags);
            foreach ($tags[0] as $tag) {
                $this->assertStringContainsString('bgcolor=', $tag, 'No colour of its own: '.$tag);
            }
        }
    }

    public function test_the_header_uses_the_dark_background_logo_or_the_name_as_text_never_the_navy_logo(): void
    {
        SiteSetting::query()->delete();
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'logo_navy' => 'site/navy.png', 'logo_url' => 'site/old.png']);
        $order = $this->place($this->product());

        $without = $this->html(TemplateDefinitions::CONFIRMATION, $order);
        $this->assertStringNotContainsString('navy.png', $without);
        $this->assertStringNotContainsString('old.png', $without);
        $this->assertStringContainsString('font-size:26px;line-height:32px;color:#F6F1E7;">Anaiza Nest</span>', $without);
        $this->assertStringContainsString('Fraunces,Georgia', $without);

        SiteSetting::query()->update(['logo_ivory' => 'site/ivory.png']);
        $with = $this->html(TemplateDefinitions::CONFIRMATION, $order);

        $this->assertStringContainsString('site/ivory.png', $with);
        $this->assertStringContainsString('background-color:#1B2A41;">', $with);
    }

    public function test_the_snapshot_survives_a_change_to_the_product(): void
    {
        $product = $this->product();
        $order = $this->place($product);
        $url = EmailThumbs::url($order->items->first()->image_path);

        // The product's picture is replaced, its record changed, and every old file of it deleted (only the thumbnails stay).
        foreach (Storage::disk('public')->allFiles() as $file) {
            if (! str_starts_with($file, 'email-thumbs/')) {
                Storage::disk('public')->delete($file);
            }
        }
        $product->images()->delete();
        Storage::disk('public')->put('products/new.png', $this->picture(200, 40, 40));
        ProductImage::create(['product_id' => $product->id, 'url' => 'products/new.png', 'sort_order' => 0]);
        $product->update(['name' => 'Renamed mug']);

        $html = $this->html(TemplateDefinitions::CONFIRMATION, $order->fresh(['customer', 'items.product']));

        $this->assertStringContainsString('src="'.$url.'"', $html);
        $this->assertStringContainsString('Mug', $html);
    }

    public function test_a_variants_own_picture_is_used_when_it_has_one(): void
    {
        $product = $this->product();
        Storage::disk('public')->put('products/red.png', $this->picture(180, 30, 30));
        $red = ProductImage::create(['product_id' => $product->id, 'url' => 'products/red.png', 'sort_order' => 5]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Colour', 'value' => 'Red', 'image_id' => $red->id]);

        $plain = $this->place($product)->items->first();
        $withVariant = $this->place($product, $variant->id)->items->first();

        $this->assertNotSame($plain->image_path, $withVariant->image_path);
        // (Saving a product picture converts it, so the thumbnail is made from the stored copy, not from the file that was uploaded.)
        $this->assertSame(EmailThumbs::make(EmailThumbs::sourceFor($product->fresh(), $variant->fresh())), $withVariant->image_path);
        $this->assertSame(EmailThumbs::make(EmailThumbs::sourceFor($product->fresh())), $plain->image_path);
    }

    public function test_a_line_with_no_picture_gets_a_linen_tile_with_the_first_letter(): void
    {
        $order = $this->place($this->product('Walnut tray', 'tray', false));

        $this->assertNull($order->items->first()->image_path);

        $html = $this->html(TemplateDefinitions::CONFIRMATION, $order);

        $this->assertStringContainsString('line-height:64px;color:#1B2A41;">W</td>', $html);
        $this->assertStringContainsString('bgcolor="#EDE4D3"', $html);
        $this->assertStringNotContainsString('email-thumbs/', $html);
    }

    public function test_orders_placed_before_pictures_were_kept_are_backfilled_and_the_run_can_be_repeated(): void
    {
        $product = $this->product();
        $order = $this->place($product);
        $gone = OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Old piece', 'quantity' => 1, 'price' => 500]);
        $old = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Mug', 'quantity' => 1, 'price' => 1000]);
        DB::table('order_items')->update(['image_path' => null]);

        $this->artisan('orders:backfill-images')->expectsOutputToContain('2 filled')->assertSuccessful();

        $this->assertStringStartsWith('email-thumbs/', $old->fresh()->image_path);
        $this->assertNull($gone->fresh()->image_path, 'A line whose product is gone shows the tile.');

        $before = $old->fresh()->image_path;
        $this->artisan('orders:backfill-images')->assertSuccessful();
        $this->assertSame($before, $old->fresh()->image_path);
    }

    public function test_the_invoice_pdf_shows_the_picture_at_64_px_and_still_renders(): void
    {
        $order = $this->place($this->product());
        $site = SiteSetting::query()->first();

        $html = view('pdf.invoice', ['order' => $order->load(['customer', 'items']), 'siteSetting' => $site])->render();

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringContainsString('width="64" height="64"', $html);

        $pdf = Pdf::loadView('pdf.invoice', ['order' => $order, 'siteSetting' => $site])->output();
        $this->assertStringStartsWith('%PDF', $pdf);

        $bare = $this->place($this->product('Walnut tray', 'tray', false));
        $tile = view('pdf.invoice', ['order' => $bare->load(['customer', 'items']), 'siteSetting' => $site])->render();
        $this->assertStringContainsString('>W</div>', $tile);
    }

    public function test_a_cart_email_shows_the_picture_and_never_a_zero_price(): void
    {
        $product = $this->product();
        // A sale price of 0 left in the database by the old bug: it is no sale.
        DB::table('products')->where('id', $product->id)->update(['sale_price' => 0]);

        $checkout = AbandonedCheckout::create([
            'session_token' => 'abc', 'recovery_token' => 'rec', 'email' => 'a@example.com', 'status' => 'open',
            'items' => [['product_id' => $product->id, 'variant_id' => null, 'name' => 'Mug', 'variant' => null, 'quantity' => 2, 'price' => 0]],
        ]);

        $block = RecoveryEmails::cartContext($checkout)['blocks']['{{cart_items_block}}'];

        $this->assertStringContainsString('email-thumbs/', $block);
        $this->assertStringContainsString('width="64"', $block);
        $this->assertStringContainsString('৳2,000', $block, 'Two at the regular price of 1,000.');
        $this->assertStringNotContainsString('৳0', $block);
    }

    public function test_an_order_for_a_product_with_a_sale_price_of_zero_shows_the_regular_price_in_the_email(): void
    {
        $product = $this->product();
        DB::table('products')->where('id', $product->id)->update(['sale_price' => 0]);

        $html = $this->html(TemplateDefinitions::CONFIRMATION, $this->place($product));

        $this->assertStringContainsString('৳1,000', $html);
        $this->assertStringNotContainsString('৳0', $html);
    }

    public function test_the_test_command_sends_every_email_to_the_admin_address(): void
    {
        Mail::fake();

        $this->artisan('emails:send-tests', ['--to' => 'tajin@example.com'])->assertSuccessful();

        Mail::assertSent(TransactionalMail::class, count(TemplateDefinitions::KEYS));
        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $mail) => $mail->hasTo('tajin@example.com') && $mail->templateKey === TemplateDefinitions::CONFIRMATION && count($mail->attachments()) === 1);
        Mail::assertSent(ShopAlertMail::class, fn (ShopAlertMail $mail) => $mail->hasTo('tajin@example.com'));
    }

    public function test_the_test_command_asks_for_an_address_when_there_is_none(): void
    {
        Mail::fake();

        $this->artisan('emails:send-tests')->expectsOutputToContain('There is no address to send to')->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_the_sample_order_renders_with_tiles_and_no_error(): void
    {
        $html = EmailBuilder::build(TemplateDefinitions::CONFIRMATION, SampleData::order(), SampleData::customer(), new SiteSetting)['html'];

        $this->assertStringContainsString('line-height:64px;color:#1B2A41;">S</td>', $html);
    }
}
