<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\CorporateEnquiryResource\Pages\EditCorporateEnquiry;
use App\Filament\Resources\CorporateEnquiryResource\Pages\ListCorporateEnquiries;
use App\Filament\Resources\HomepageSectionResource\Pages\EditHomepageSection;
use App\Filament\Resources\HomepageSectionResource\Pages\ListHomepageSections;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\RelationManagers\ImagesRelationManager;
use App\Models\Category;
use App\Models\CorporateEnquiry;
use App\Models\HomepageSection;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class E2AdminFormsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1450,
            'stock_quantity' => 5,
            'sku' => 'TP-1',
            'is_active' => true,
        ]);
    }

    public function test_the_box_care_and_gift_box_fields_save(): void
    {
        $this->signIn();
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'gift_box_included' => true,
                'box_contents' => [['line' => 'Teapot'], ['line' => 'Two cups']],
                'care_instructions' => 'Wash by hand.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertTrue($product->gift_box_included);
        $this->assertSame(['Teapot', 'Two cups'], $product->box_contents);
        $this->assertSame('Wash by hand.', $product->care_instructions);
    }

    public function test_more_than_twelve_lines_or_a_long_care_text_is_refused(): void
    {
        $this->signIn();
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'box_contents' => array_map(fn (int $i) => ['line' => "Item {$i}"], range(1, 13)),
                'care_instructions' => str_repeat('a', 401),
            ])
            ->call('save')
            ->assertHasFormErrors(['box_contents', 'care_instructions']);
    }

    public function test_a_brand_note_shows_but_never_blocks_saving(): void
    {
        $this->signIn();
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['summary' => 'Amazing set! Hurry!'])
            ->assertSee('Brand check')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame('Amazing set! Hurry!', $product->refresh()->summary);
    }

    public function test_clean_wording_shows_no_brand_note(): void
    {
        $this->signIn();
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['summary' => 'A porcelain tea set for two, packed in a gift box.'])
            ->assertDontSee('Brand check');
    }

    public function test_the_save_notification_carries_the_brand_summary(): void
    {
        $this->signIn();
        $page = Page::create(['title' => 'About', 'slug' => 'about', 'content' => '<p>Amazing!</p>']);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->call('save')
            ->assertNotified(Notification::make()->success()->title('Saved')->body('Brand check: 2 notes'));
    }

    public function test_the_image_hint_warns_below_four_images_and_stays_quiet_at_four(): void
    {
        $this->signIn();
        $product = $this->product();
        $product->images()->create(['url' => 'products/a.webp', 'sort_order' => 0]);

        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertSee('the reveal, the pour, the table and a detail')
            ->assertSee('1 of 4 suggested images');

        foreach (['b', 'c', 'd'] as $i => $name) {
            $product->images()->create(['url' => "products/{$name}.webp", 'sort_order' => $i + 1]);
        }

        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertSee('the reveal, the pour, the table and a detail')
            ->assertDontSee('suggested images');
    }

    public function test_the_order_form_saves_the_gift_fields_and_the_print_action_shows_for_gifts_only(): void
    {
        $this->signIn();
        $order = Order::create([
            'customer_id' => AdminRecords::customer()->id,
            'status' => 'pending',
            'subtotal' => 1450,
            'delivery_fee' => 80,
            'total' => 1530,
            'payment_method' => 'cod',
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('printGiftNote')
            ->fillForm(['is_gift' => true, 'gift_message' => 'Happy Eid'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($order->refresh()->is_gift);
        $this->assertSame('Happy Eid', $order->gift_message);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->assertActionVisible('printGiftNote');
    }

    public function test_the_gift_message_is_limited_to_200_characters_in_the_admin_too(): void
    {
        $this->signIn();
        $order = Order::create([
            'customer_id' => AdminRecords::customer()->id,
            'status' => 'pending',
            'subtotal' => 1450,
            'delivery_fee' => 80,
            'total' => 1530,
            'payment_method' => 'cod',
            'is_gift' => true,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['gift_message' => str_repeat('a', 201)])
            ->call('save')
            ->assertHasFormErrors(['gift_message']);
    }

    public function test_corporate_enquiries_can_be_filtered_by_status_and_updated(): void
    {
        $this->signIn();
        $new = CorporateEnquiry::create(['name' => 'A', 'company' => 'New Co', 'phone' => '1', 'email' => 'a@example.com', 'quantity' => 5]);
        $won = CorporateEnquiry::create(['name' => 'B', 'company' => 'Won Co', 'phone' => '2', 'email' => 'b@example.com', 'quantity' => 9, 'status' => 'won']);

        Livewire::test(ListCorporateEnquiries::class)
            ->assertCanSeeTableRecords([$new, $won])
            ->filterTable('status', 'won')
            ->assertCanSeeTableRecords([$won])
            ->assertCanNotSeeTableRecords([$new]);

        Livewire::test(EditCorporateEnquiry::class, ['record' => $new->getRouteKey()])
            ->fillForm(['status' => 'quoted', 'internal_notes' => 'Sent a quote on the 7th.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('quoted', $new->refresh()->status);
        $this->assertSame('Sent a quote on the 7th.', $new->internal_notes);
    }

    public function test_the_two_section_actions_do_their_job_and_say_so_when_there_is_nothing_to_do(): void
    {
        $this->signIn();
        HomepageSection::query()->delete();

        Livewire::test(ListHomepageSections::class)
            ->callAction('addKitOccasions')->assertNotified('7 tiles added, switched off')
            ->callAction('addKitOccasions')->assertNotified('All the kit occasions are already there')
            ->callAction('insertKitLines')->assertNotified('Five kit lines inserted')
            ->callAction('insertKitLines')->assertNotified('The Why Anaiza Nest section already has lines');
    }

    public function test_a_tile_and_a_reason_can_be_edited_in_their_sections(): void
    {
        $this->signIn();
        HomepageSection::query()->delete();
        HomepageSection::addKitOccasions();
        HomepageSection::insertKitLines();
        $occasions = HomepageSection::where('type', 'occasions')->sole();
        $why = HomepageSection::where('type', 'why_us')->sole();

        Livewire::test(EditHomepageSection::class, ['record' => $occasions->getRouteKey()])
            ->assertFormFieldIsVisible('tiles')
            ->assertFormFieldIsHidden('reasons')
            ->assertFormFieldIsVisible('custom_title')
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditHomepageSection::class, ['record' => $why->getRouteKey()])
            ->assertFormFieldIsVisible('reasons')
            ->assertFormFieldIsHidden('tiles')
            ->fillForm(['custom_title' => 'Why Anaiza Nest'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Why Anaiza Nest', $why->refresh()->custom_title);
        $this->assertCount(5, $why->reasons);
    }

    public function test_the_corporate_settings_save_and_the_pickers_accept_the_new_page(): void
    {
        $this->signIn();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'corporate_intro' => 'Tell us what you need.',
                'corporate_notify_email' => 'team@example.com',
                'footer_columns' => [['title' => 'Customer care', 'items' => [['type' => 'custom', 'label' => 'Ask for a corporate quotation', 'url' => '/corporate-gifting']]]],
                'nav_links' => [['type' => 'custom', 'label' => 'Corporate gifting', 'url' => '/corporate-gifting']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = SiteSetting::first();
        $this->assertSame('Tell us what you need.', $settings->corporate_intro);
        $this->assertSame('team@example.com', $settings->corporate_notify_email);
        $this->assertSame('/corporate-gifting', $settings->footer_columns[0]['items'][0]['url']);
    }
}
