<?php

namespace Tests\Feature\Api;

use App\Mail\OrderConfirmationMail;
use App\Models\BlockedContact;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Faq;
use App\Models\LandingPage;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Landing\LandingStats;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $mug;

    private Product $tray;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->category = Category::create(['name' => 'Mugs', 'slug' => 'mugs']);
        $this->mug = $this->product('Glazed mug', 'MUG-1', 600, 20);
        $this->tray = $this->product('Brass tray', 'TRAY-1', 1500, 5);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function product(string $name, string $sku, int $price, int $stock, bool $active = true, ?Category $category = null): Product
    {
        return Product::create([
            'category_id' => ($category ?? $this->category)->id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)),
            'price' => $price, 'stock_quantity' => $stock, 'sku' => $sku, 'is_active' => $active,
        ]);
    }

    private function page(array $overrides = [], ?array $blocks = null): LandingPage
    {
        return LandingPage::create(array_merge([
            'title' => 'Eid offer', 'slug' => 'eid-offer', 'status' => 'published',
            'blocks' => $blocks ?? [
                ['type' => 'hero', 'data' => ['is_visible' => true, 'headline' => 'A gift for Eid', 'button_target' => 'order']],
                ['type' => 'products', 'data' => ['is_visible' => true, 'source' => 'hand', 'product_ids' => [$this->mug->id, $this->tray->id]]],
                ['type' => 'order_form', 'data' => ['is_visible' => true]],
            ],
        ], $overrides));
    }

    private function types(array $payload): array
    {
        return array_column($payload['data']['blocks'], 'type');
    }

    // ---- slugs, draft and published, the window

    public function test_a_slug_is_unique(): void
    {
        $this->page();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->page(['title' => 'Another']);
    }

    public function test_a_slug_is_made_url_safe(): void
    {
        $page = $this->page(['slug' => 'Eid Offer 2026!']);

        $this->assertSame('eid-offer-2026', $page->slug);
    }

    public function test_a_draft_does_not_exist_for_visitors(): void
    {
        $this->page(['status' => 'draft']);

        $this->getJson('/api/landing-pages/eid-offer')->assertNotFound();
        $this->getJson('/api/landing-pages')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_published_page_is_served_with_its_blocks_in_order(): void
    {
        $this->page();

        $response = $this->getJson('/api/landing-pages/eid-offer')->assertOk()
            ->assertJsonPath('data.state', 'live')
            ->assertJsonPath('data.layout', 'focus')
            ->assertJsonPath('data.seo.noindex', true);

        $this->assertSame(['hero', 'products', 'order_form'], $this->types($response->json()));
        $this->getJson('/api/landing-pages')->assertJsonPath('data.0.slug', 'eid-offer');
    }

    public function test_a_page_that_has_not_opened_yet_does_not_exist_and_one_that_has_closed_says_so(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->page(['publish_from' => '2026-10-15 00:00:00', 'publish_until' => '2026-10-20 00:00:00']);

        $this->getJson('/api/landing-pages/eid-offer')->assertNotFound();

        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->getJson('/api/landing-pages/eid-offer')->assertOk()->assertJsonPath('data.state', 'live');

        Carbon::setTestNow('2026-10-20 00:00:00');
        $ended = $this->getJson('/api/landing-pages/eid-offer')->assertOk()->assertJsonPath('data.state', 'ended');
        $this->assertSame([], $ended->json('data.blocks'), 'nothing of the offer is sent once it has ended');
        $this->assertTrue($ended->json('data.seo.noindex'));
        $this->getJson('/api/landing-pages')->assertJsonCount(0, 'data');
    }

    public function test_a_valid_preview_link_shows_a_draft_and_an_old_or_forged_one_does_not(): void
    {
        $page = $this->page(['status' => 'draft', 'noindex' => false]);

        $this->getJson('/api/landing-pages/eid-offer?preview='.$page->previewToken())->assertOk()
            ->assertJsonPath('data.state', 'preview')
            ->assertJsonPath('data.seo.noindex', true);

        $this->getJson('/api/landing-pages/eid-offer?preview=1999999999.'.str_repeat('a', 64))->assertNotFound();
        $this->getJson('/api/landing-pages/eid-offer?preview=nonsense')->assertNotFound();

        Carbon::setTestNow(now()->addHours(3));
        $this->getJson('/api/landing-pages/eid-offer?preview='.$page->previewToken(now()->subHours(3)))->assertNotFound();
    }

    public function test_the_preview_link_of_one_page_does_not_open_another(): void
    {
        $one = $this->page();
        $this->page(['title' => 'Other', 'slug' => 'other', 'status' => 'draft']);

        $this->getJson('/api/landing-pages/other?preview='.$one->previewToken())->assertNotFound();
    }

    // ---- blocks

    public function test_a_hidden_block_is_not_sent(): void
    {
        $this->page([], [
            ['type' => 'hero', 'data' => ['is_visible' => false, 'headline' => 'Secret']],
            ['type' => 'order_form', 'data' => ['is_visible' => true]],
        ]);

        $json = $this->getJson('/api/landing-pages/eid-offer')->assertOk();

        $this->assertSame(['order_form'], $this->types($json->json()));
        $this->assertStringNotContainsString('Secret', $json->getContent());
    }

    public function test_products_picked_by_hand_keep_their_order_and_skip_inactive_ones(): void
    {
        $retired = $this->product('Old teapot', 'POT', 900, 3, active: false);
        $this->page([], [['type' => 'products', 'data' => ['source' => 'hand', 'product_ids' => [$this->tray->id, $retired->id, $this->mug->id], 'layout' => 'list']]]);

        $block = $this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0');

        $this->assertSame('list', $block['layout']);
        $this->assertSame(['Brass tray', 'Glazed mug'], array_column($block['products'], 'name'));
        $this->assertSame('TRAY-1', $block['products'][0]['sku']);
    }

    public function test_products_of_a_category_with_variants_and_stock(): void
    {
        $other = Category::create(['name' => 'Trays', 'slug' => 'trays']);
        $this->product('Walnut board', 'BOARD', 2000, 4, category: $other);
        ProductVariant::create(['product_id' => $this->mug->id, 'name' => 'Colour', 'value' => 'Blue', 'stock_quantity' => 7, 'sku' => 'MUG-BLU']);
        $this->page([], [['type' => 'products', 'data' => ['source' => 'category', 'category_id' => $this->category->id, 'limit' => 10]]]);

        $products = $this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0.products');
        $names = array_column($products, 'name');

        $this->assertSame(['Brass tray', 'Glazed mug'], $names);
        $mug = collect($products)->firstWhere('name', 'Glazed mug');
        $this->assertSame('MUG-BLU', $mug['variants'][0]['sku']);
        $this->assertSame(7, $mug['variants'][0]['stock_quantity']);
    }

    public function test_a_products_block_with_nothing_to_show_is_left_out(): void
    {
        $this->page([], [['type' => 'products', 'data' => ['source' => 'hand', 'product_ids' => []]], ['type' => 'order_form', 'data' => []]]);

        $this->assertSame(['order_form'], $this->types($this->getJson('/api/landing-pages/eid-offer')->json()));
    }

    public function test_reviews_are_real_and_the_block_hides_when_there_are_none(): void
    {
        $this->page([], [
            ['type' => 'products', 'data' => ['source' => 'hand', 'product_ids' => [$this->mug->id]]],
            ['type' => 'reviews', 'data' => ['heading' => 'What people say']],
        ]);

        $this->assertSame(['products'], $this->types($this->getJson('/api/landing-pages/eid-offer')->json()));

        ProductReview::create(['product_id' => $this->mug->id, 'name' => 'Nusrat', 'rating' => 5, 'body' => 'Lovely.', 'status' => 'approved', 'approved_at' => now()]);
        ProductReview::create(['product_id' => $this->mug->id, 'name' => 'Waiting', 'rating' => 1, 'body' => 'Not yet read.', 'status' => 'pending']);
        ProductReview::create(['product_id' => $this->tray->id, 'name' => 'Elsewhere', 'rating' => 4, 'body' => 'A different piece.', 'status' => 'approved', 'approved_at' => now()]);

        $reviews = collect($this->getJson('/api/landing-pages/eid-offer')->json('data.blocks'))->firstWhere('type', 'reviews');

        $this->assertSame(['Nusrat'], array_column($reviews['items'], 'name'), 'approved reviews of the products on the page only');
        $this->assertSame(1, $reviews['summary']['rating_count']);
        $this->assertSame('Glazed mug', $reviews['items'][0]['product_name']);
    }

    public function test_faq_uses_the_shops_questions_all_or_picked(): void
    {
        $one = Faq::create(['question' => 'How long is delivery?', 'answer' => '1-3 days.', 'sort_order' => 1]);
        Faq::create(['question' => 'Can I return it?', 'answer' => 'Within 7 days.', 'sort_order' => 2]);

        $this->page([], [['type' => 'faq', 'data' => []]]);
        $this->assertCount(2, $this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0.items'));

        LandingPage::query()->update(['blocks' => [['type' => 'faq', 'data' => ['faq_ids' => [$one->id]]]]]);
        $this->assertSame(['How long is delivery?'], array_column($this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0.items'), 'question'));
    }

    public function test_a_countdown_exists_only_with_a_real_end_time_in_the_future(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->page(['publish_until' => null], [['type' => 'countdown', 'data' => []], ['type' => 'order_form', 'data' => []]]);
        $this->assertSame(['order_form'], $this->types($this->getJson('/api/landing-pages/eid-offer')->json()), 'no end time, no timer');

        LandingPage::query()->update(['publish_until' => '2026-10-20 18:00:00']);
        $countdown = collect($this->getJson('/api/landing-pages/eid-offer')->json('data.blocks'))->firstWhere('type', 'countdown');
        $this->assertSame('2026-10-20T18:00:00+00:00', $countdown['ends_at']);
    }

    // ---- HTML safety through the model

    public function test_custom_html_is_cleaned_on_save_and_the_report_is_kept(): void
    {
        $page = $this->page([], [['type' => 'custom_html', 'data' => ['html' => '<p onclick="x()">Hi</p><script>alert(1)</script><form action="https://e.example"><input></form>']]]);

        $data = $page->fresh()->blocks[0]['data'];

        $this->assertSame('<p>Hi</p>', $data['html_clean']);
        $this->assertStringContainsString('onclick', $data['html'], 'what was typed is kept for editing');
        $this->assertCount(4, $page->notes, 'script, handler, form and form field');

        $served = $this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0');
        $this->assertSame('<p>Hi</p>', $served['html']);
        $this->assertFalse($served['scripts']);
    }

    public function test_scripts_need_an_explicit_grant_even_for_a_super_admin(): void
    {
        $html = '<p>Hi</p><script>track()</script>';
        $block = [['type' => 'custom_html', 'data' => ['html' => $html, 'allow_scripts' => true]], ['type' => 'rich_text', 'data' => ['content' => '<p>x</p>']]];

        // No signed-in person at all, a super-admin with no grant, and a role without the permission: removed.
        $this->assertStringNotContainsString('<script', $this->page([], $block)->fresh()->blocks[0]['data']['html_clean']);
        LandingPage::query()->delete();

        $superAdmin = User::factory()->create(['role_id' => null]);
        $this->actingAs($superAdmin);
        $this->assertTrue($superAdmin->hasPermission('landing_pages.allow_scripts'), 'the general permission check passes for a super-admin');
        $page = $this->page([], $block);
        $this->assertStringNotContainsString('<script', $page->fresh()->blocks[0]['data']['html_clean'], 'but scripts are an explicit grant');
        $this->assertStringContainsString('do not have the permission', implode(' ', $page->notes));
        LandingPage::query()->delete();

        // Granted to the person.
        $superAdmin->forceFill(['landing_scripts_allowed' => true])->save();
        $page = $this->page([], [['type' => 'custom_html', 'data' => ['html' => $html, 'allow_scripts' => true]]]);
        $this->assertStringContainsString('<script>track()</script>', $page->fresh()->blocks[0]['data']['html_clean']);
        $this->assertStringContainsString('WARNING', implode(' ', $page->notes));
        $this->assertTrue($this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0.scripts'));
        LandingPage::query()->delete();

        // Granted to a role.
        $role = Role::create(['name' => 'Marketer', 'slug' => 'marketer', 'permissions' => ['landing_pages.manage', 'landing_pages.allow_scripts']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        $page = $this->page([], [['type' => 'custom_html', 'data' => ['html' => $html, 'allow_scripts' => true]]]);
        $this->assertStringContainsString('<script>track()</script>', $page->fresh()->blocks[0]['data']['html_clean']);
    }

    public function test_scripts_are_kept_off_a_focus_mode_page_that_has_the_order_form(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null, 'landing_scripts_allowed' => true]));
        $blocks = [
            ['type' => 'custom_html', 'data' => ['html' => '<p>Hi</p><script>track()</script>', 'allow_scripts' => true]],
            ['type' => 'order_form', 'data' => []],
        ];

        $focus = $this->page(['layout' => 'focus'], $blocks);
        $this->assertStringNotContainsString('<script', $focus->fresh()->blocks[0]['data']['html_clean']);
        $this->assertStringContainsString('Focus mode', implode(' ', $focus->notes));

        $normal = $this->page(['slug' => 'normal-layout', 'layout' => 'normal'], $blocks);
        $this->assertStringContainsString('<script>track()</script>', $normal->fresh()->blocks[0]['data']['html_clean']);
    }

    public function test_toggling_the_status_by_someone_without_the_grant_does_not_strip_a_trusted_pages_scripts(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null, 'landing_scripts_allowed' => true]));
        $page = $this->page([], [['type' => 'custom_html', 'data' => ['html' => '<script>track()</script>', 'allow_scripts' => true]]]);

        $this->actingAs(User::factory()->create(['role_id' => null]));
        $page->update(['status' => 'draft']);

        $this->assertStringContainsString('<script>track()</script>', $page->fresh()->blocks[0]['data']['html_clean']);
    }

    public function test_uploaded_images_are_matched_by_file_name_and_added_to_the_media_library(): void
    {
        \Storage::fake('public');
        \Storage::disk('public')->put('media-library/landing/photo.jpg', 'x');

        $page = $this->page([], [['type' => 'custom_html', 'data' => [
            'html' => '<img src="images/photo.jpg"><img src="images/gone.png">',
            'assets' => ['media-library/landing/photo.jpg'],
        ]]]);

        $clean = $page->fresh()->blocks[0]['data']['html_clean'];

        $this->assertStringContainsString('/storage/media-library/landing/photo.jpg', $clean);
        $this->assertStringContainsString('images/gone.png', $clean);
        $this->assertStringContainsString('image not found among the uploaded images: images/gone.png', implode(' ', $page->notes));
        $this->assertDatabaseHas('media_items', ['path' => 'media-library/landing/photo.jpg', 'original_name' => 'photo.jpg']);
    }

    // ---- orders

    private function order(array $overrides = [])
    {
        (new ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        return $this->postJson('/api/orders', array_merge([
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01711234567', 'customer_email' => 'nusrat@example.com',
            'customer_address' => 'House 12, Road 5', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->mug->id, 'quantity' => 2]],
        ], $overrides));
    }

    public function test_an_order_from_a_landing_page_records_the_page_through_the_normal_endpoint(): void
    {
        $page = $this->page();

        $id = $this->order(['landing_page' => 'eid-offer'])->assertCreated()->json('data.id');

        $order = Order::findOrFail($id);
        $this->assertSame($page->id, $order->landing_page_id);
        $this->assertSame('eid-offer', $order->landing_slug);
        $this->assertSame(1200, (int) $order->subtotal);
        Mail::assertSent(OrderConfirmationMail::class);
        $this->assertSame(18, $this->mug->fresh()->stock_quantity, 'stock is taken exactly as for any order');
    }

    public function test_an_unknown_landing_page_never_fails_an_order(): void
    {
        $id = $this->order(['landing_page' => 'no-such-page'])->assertCreated()->json('data.id');

        $this->assertNull(Order::findOrFail($id)->landing_page_id);
    }

    public function test_the_page_is_remembered_on_the_order_after_the_page_is_deleted(): void
    {
        $page = $this->page();
        $id = $this->order(['landing_page' => 'eid-offer'])->assertCreated()->json('data.id');

        $page->delete();

        $order = Order::findOrFail($id);
        $this->assertNull($order->landing_page_id);
        $this->assertSame('eid-offer', $order->landing_slug);
    }

    public function test_every_existing_order_protection_still_applies_to_a_landing_order(): void
    {
        $this->page();

        // Bot trap.
        $this->order(['landing_page' => 'eid-offer', 'hp_field' => 'x'])->assertStatus(422);

        // Blocked buyer.
        BlockedContact::create(['kind' => 'phone', 'value' => '01799999999']);
        $this->order(['landing_page' => 'eid-offer', 'customer_phone' => '01799999999'])->assertStatus(422)->assertJsonValidationErrors(['customer_phone']);

        // Stock.
        $this->order(['landing_page' => 'eid-offer', 'items' => [['product_id' => $this->tray->id, 'quantity' => 6]]])->assertStatus(422);
        $this->assertSame(5, $this->tray->fresh()->stock_quantity);

        // A coupon that does not exist.
        $this->order(['landing_page' => 'eid-offer', 'coupon_code' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors(['coupon_code']);

        // The per-phone daily limit.
        SiteSetting::query()->update(['max_orders_per_phone_per_day' => 1]);
        $this->order(['landing_page' => 'eid-offer', 'customer_phone' => '01811111111'])->assertCreated();
        $this->order(['landing_page' => 'eid-offer', 'customer_phone' => '01811111111'])->assertStatus(422);

        $this->assertSame(1, Order::count());
    }

    public function test_a_coupon_works_on_a_landing_order(): void
    {
        Coupon::create(['code' => 'EID10', 'discount_type' => 'percent', 'amount' => 10]);
        $this->page();

        $id = $this->order(['landing_page' => 'eid-offer', 'coupon_code' => 'EID10'])->assertCreated()->json('data.id');

        $this->assertSame(120, (int) Order::findOrFail($id)->discount_amount);
    }

    // ---- ad click ids

    public function test_click_ids_are_kept_and_sent_with_the_server_side_events(): void
    {
        MarketingSetting::query()->firstOrCreate([])->update([
            'meta_pixel_id' => '123456', 'meta_capi_access_token' => 'meta-token',
            'tiktok_pixel_id' => 'TT123', 'tiktok_events_api_access_token' => 'tt-token',
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->page();

        $id = $this->order(['landing_page' => 'eid-offer', 'fbclid' => 'IwAR-abc', 'gclid' => 'Cj0-gclid', 'ttclid' => 'tt-click-1', 'marketing_consent' => true])
            ->assertCreated()->json('data.id');

        $order = Order::findOrFail($id);
        $this->assertSame('IwAR-abc', $order->fbclid);
        $this->assertSame('Cj0-gclid', $order->gclid);
        $this->assertSame('tt-click-1', $order->ttclid);

        Http::assertSent(function ($request) use ($order): bool {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $user = $request['data'][0]['user_data'];

                return str_starts_with($user['fbc'], 'fb.1.') && str_ends_with($user['fbc'], '.IwAR-abc')
                    && $request['data'][0]['event_id'] === "order-{$order->id}"
                    && $request['data'][0]['custom_data']['content_ids'] === ['MUG-1'];
            }

            return false;
        });
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'business-api.tiktok.com')
            && $request['data'][0]['user']['ttclid'] === 'tt-click-1'
            && $request['data'][0]['properties']['contents'][0]['content_id'] === 'MUG-1');
    }

    // ---- stats

    public function test_views_and_add_to_carts_are_counted_for_a_live_page(): void
    {
        $this->page();
        $human = ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1'];

        $this->postJson('/api/landing-pages/eid-offer/events', ['type' => 'view'], $human)->assertOk();
        $this->postJson('/api/landing-pages/eid-offer/events', ['type' => 'view'], $human)->assertOk();
        $this->postJson('/api/landing-pages/eid-offer/events', ['type' => 'add_to_cart'], $human)->assertOk();

        $stats = LandingStats::forPage(LandingPage::first());
        $this->assertSame(2, $stats['views']);
        $this->assertSame(1, $stats['add_to_carts']);
    }

    public function test_bots_drafts_ended_pages_and_nonsense_are_not_counted(): void
    {
        $this->page();
        $human = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120 Safari/537.36'];

        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'facebookexternalhit/1.1', 'curl/8.0', 'HeadlessChrome/120'] as $agent) {
            $this->postJson('/api/landing-pages/eid-offer/events', ['type' => 'view'], ['User-Agent' => $agent])->assertOk();
        }
        $this->postJson('/api/landing-pages/eid-offer/events', ['type' => 'purchase'], $human)->assertOk();
        $this->postJson('/api/landing-pages/missing/events', ['type' => 'view'], $human)->assertOk();

        LandingPage::query()->update(['status' => 'draft']);
        $this->postJson('/api/landing-pages/eid-offer/events', ['type' => 'view'], $human)->assertOk();

        $this->assertSame(0, LandingStats::forPage(LandingPage::first())['views']);
        $this->assertDatabaseCount('landing_page_stats', 0);
    }

    public function test_orders_conversion_and_revenue_come_from_the_orders_themselves_for_a_date_range(): void
    {
        $page = $this->page();
        $human = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120 Safari/537.36'];
        SiteSetting::query()->update(['max_orders_per_phone_per_day' => 0]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/landing-pages/eid-offer/events', ['type' => 'view'], $human);
        }

        $kept = $this->order(['landing_page' => 'eid-offer'])->assertCreated()->json('data.id');
        $cancelled = $this->order(['landing_page' => 'eid-offer', 'customer_phone' => '01822222222'])->assertCreated()->json('data.id');
        Order::whereKey($cancelled)->update(['status' => 'cancelled']);
        $this->order(['customer_phone' => '01833333333'])->assertCreated(); // not from the page

        $stats = LandingStats::forPage($page);

        $this->assertSame(4, $stats['views']);
        $this->assertSame(1, $stats['orders'], 'a cancelled order is not a sale, and an order from elsewhere is not this page\'s');
        $this->assertSame(25.0, $stats['conversion']);
        $this->assertSame((int) Order::findOrFail($kept)->total, $stats['revenue']);

        // Outside the range nothing counts.
        $past = LandingStats::forPage($page, now()->subDays(60), now()->subDays(40));
        $this->assertSame(['views' => 0, 'add_to_carts' => 0, 'orders' => 0, 'conversion' => null, 'revenue' => 0], $past);
    }

    // ---- search engines and the sitemap

    public function test_a_noindex_page_is_left_out_of_the_sitemap_and_an_indexable_one_is_in(): void
    {
        $this->page(['noindex' => true]);
        $this->page(['title' => 'Public', 'slug' => 'public-offer', 'noindex' => false]);
        $this->page(['title' => 'Public draft', 'slug' => 'public-draft', 'noindex' => false, 'status' => 'draft']);

        $slugs = array_column($this->getJson('/api/sitemap')->assertOk()->json('landing_pages'), 'slug');

        $this->assertSame(['public-offer'], $slugs);
    }

    public function test_noindex_is_on_by_default_and_follows_the_switch(): void
    {
        LandingPage::create(['title' => 'Plain', 'slug' => 'plain', 'status' => 'published']);
        $this->assertTrue(LandingPage::first()->fresh()->noindex);

        LandingPage::query()->update(['noindex' => false]);
        $this->getJson('/api/landing-pages/plain')->assertJsonPath('data.seo.noindex', false);
    }
}
