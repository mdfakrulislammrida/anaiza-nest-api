<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\RelationManagers\CourierEventsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\ItemsRelationManager;
use App\Models\CourierEvent;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\ActionGroup as PageActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Filament\Tables\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The admin on a phone (Batch OPS2-B): cards below 768 px, the same table from 768 px up, row and page actions in one menu on a phone, the order page in a
 * phone's order, the sidebar shut by default. What a real browser measures (nothing scrolls sideways) is checked by scripts/mobile-admin-check.mjs, which needs
 * Chrome and a local copy of the admin; these tests check the markup and the settings that produce it.
 */
class MobileAdminTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    /** The columns the Orders table had before the phone cards were added: all must still be there, in this order. */
    private const ORDER_COLUMNS = [
        'id', 'created_at', 'ship_name', 'status', 'source', 'legacy_order_id', 'payment_status', 'payment_trx_id', 'courier', 'is_gift', 'payment_method', 'landing_slug',
        'utm_source', 'utm_medium', 'utm_campaign', 'coupon_code', 'review_request', 'shippingZone.name', 'total', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->shop();
        $this->actingAs($this->admin());
    }

    public function test_every_orders_column_is_still_there_and_shows_from_768_px_up(): void
    {
        $columns = Livewire::test(ListOrders::class)->instance()->getTable()->getColumns();

        $this->assertSame([...self::ORDER_COLUMNS, 'phone_card'], array_keys($columns));

        foreach (self::ORDER_COLUMNS as $name) {
            $this->assertSame('md', $columns[$name]->getVisibleFrom(), "{$name} shows from md up");
            $this->assertNull($columns[$name]->getHiddenFrom(), $name);
        }
    }

    public function test_the_phone_card_is_the_only_orders_column_a_phone_shows(): void
    {
        $card = Livewire::test(ListOrders::class)->instance()->getTable()->getColumns()['phone_card'];

        $this->assertSame('md', $card->getHiddenFrom());
        $this->assertNull($card->getVisibleFrom());
        $this->assertFalse($card->isToggleable(), 'the card is not offered in the column menu');
    }

    public function test_the_order_card_has_what_matters_at_a_glance(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        $card = collect(OrderResource::phoneCard($order))->flatMap(fn (array $row): array => [...($row['left'] ?? []), ...($row['right'] ?? [])]);
        $text = $card->map(fn (array $cell): string => (string) ($cell['text'] ?? $cell['badge'] ?? ''))->implode(' | ');

        $this->assertStringContainsString('#'.$order->id, $text);
        $this->assertStringContainsString('Shipped', $text);
        $this->assertStringContainsString('Nusrat Jahan', $text);
        $this->assertStringContainsString('01712345678', $text);
        $this->assertStringContainsString('৳'.number_format($order->total), $text);
        $this->assertStringContainsString('Steadfast', $text, 'the courier and its status');
    }

    public function test_the_list_draws_the_card_and_both_sets_of_row_actions_in_the_markup(): void
    {
        $this->booked($this->codOrder(['status' => 'processing']));

        $html = Livewire::test(ListOrders::class)->html();

        $this->assertStringContainsString('am-card', $html);
        $this->assertStringContainsString('am-desktop-only', $html, 'the inline actions, for a desktop');
        $this->assertStringContainsString('am-phone-only', $html, 'the one Actions menu, for a phone');
        $this->assertStringContainsString('Actions', $html);
    }

    public function test_row_actions_are_in_a_line_on_a_desktop_and_one_menu_on_a_phone(): void
    {
        $actions = Livewire::test(ListOrders::class)->instance()->getTable()->getActions();

        $groups = collect($actions)->filter(fn ($action): bool => $action instanceof ActionGroup);
        $inline = collect($actions)->reject(fn ($action): bool => $action instanceof ActionGroup);

        $this->assertCount(1, $groups, 'exactly one menu');
        $this->assertStringContainsString('am-phone-only', $groups->first()->getExtraAttributes()['class'] ?? '');
        $this->assertNotEmpty($inline);

        foreach ($inline as $action) {
            $this->assertStringContainsString('am-desktop-only', $action->getExtraAttributes()['class'] ?? '', $action->getName());
        }

        $this->assertEqualsCanonicalizing(
            $inline->map(fn ($action): string => $action->getName())->all(),
            collect($groups->first()->getActions())->map(fn ($action): string => $action->getName())->all(),
            'the menu holds the same actions as the line',
        );
    }

    public function test_the_table_keeps_search_ten_a_page_and_no_wide_filter_bar(): void
    {
        $table = Livewire::test(ListOrders::class)->instance()->getTable();

        $this->assertSame(10, $table->getDefaultPaginationPageOption());
        $this->assertTrue($table->hasSearch() || collect($table->getColumns())->contains(fn ($column): bool => $column->isSearchable()), 'search stays');
        $this->assertSame('dropdown', $table->getFiltersLayout()->value ?? 'dropdown', 'filters open as one panel, not a bar across the page');
    }

    public function test_the_order_page_header_has_full_width_buttons_and_one_more_menu_on_a_phone(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));

        $page = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);
        $html = $page->html();

        $this->assertStringContainsString('am-primary', $html);
        $this->assertStringContainsString('More', $html);

        foreach (['syncCourierNow', 'downloadInvoice'] as $name) {
            $page->assertActionVisible($name);
        }

        $actions = collect($page->instance()->getCachedHeaderActions());
        $this->assertTrue($actions->contains(fn ($action): bool => $action instanceof PageActionGroup), 'the More menu');
    }

    public function test_the_phone_order_css_follows_the_orders_form(): void
    {
        $schema = OrderResource::form(Form::make(Livewire::test(EditOrder::class, ['record' => $this->codOrder()->getRouteKey()])->instance()))->getComponents(withHidden: true);
        $names = collect($schema)->map(fn ($component): string => method_exists($component, 'getName') && $component->getName() ? $component->getName() : class_basename($component))->all();

        // mobile-styles.blade.php puts these parts in a phone's order by their place in the form (:nth-child). If a part is added or moved, this fails
        // and the numbers there need to follow.
        $this->assertSame(
            ['customer_id', 'status', 'placed_on', 'order_times', 'courier_name', 'tracking_number', 'payment_method', 'subtotal', 'shipping_zone_id', 'delivery_fee', 'total', 'discount_amount', 'Section', 'gift_note', 'Section', 'Section', 'is_gift', 'gift_message', 'Section'],
            $names,
        );

        $css = view('filament.admin.mobile-styles')->render();
        foreach ([[2, 10, 'status'], [3, 10, 'placed on'], [4, 10, 'times'], [8, 11, 'subtotal'], [10, 12, 'delivery fee'], [12, 13, 'coupon discount'], [11, 14, 'total'], [7, 15, 'payment method'], [1, 20, 'customer'], [13, 21, 'delivery details']] as [$child, $order, $part]) {
            $this->assertStringContainsString(":nth-child({$child}) { order: {$order}; }", $css, $part);
        }
    }

    public function test_the_items_and_courier_history_tables_are_stacked_cards_on_a_phone(): void
    {
        $order = $this->booked($this->codOrder());
        $order->items()->create(['product_name' => 'Rose cup and saucer', 'variant_name' => 'Colour', 'variant_value' => 'Pink', 'quantity' => 2, 'price' => 700]);
        CourierEvent::create(['order_id' => $order->id, 'courier' => 'steadfast', 'source' => 'poll', 'event_type' => 'status', 'summary' => 'Steadfast reported in_review.']);

        foreach ([ItemsRelationManager::class, CourierEventsRelationManager::class] as $manager) {
            $table = Livewire::test($manager, ['ownerRecord' => $order, 'pageClass' => EditOrder::class])->instance()->getTable();
            $columns = $table->getColumns();

            $this->assertSame('md', end($columns)->getHiddenFrom(), "{$manager}: the card is the last column");
            $this->assertSame('phone_card', end($columns)->getName());
        }

        $items = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditOrder::class])->html();
        $this->assertStringContainsString('2 × ৳700', $items);
        $this->assertStringContainsString('৳1,400', $items, 'the line total');
        $this->assertStringContainsString('Colour: Pink', $items);
    }

    public function test_every_admin_page_carries_the_phone_styles_and_the_sidebar_starts_shut_on_a_phone(): void
    {
        $html = $this->get(OrderResource::getUrl('index'))->assertSuccessful()->getContent();

        $this->assertStringContainsString("localStorage.setItem('isOpen', 'false')", $html);
        $this->assertStringContainsString('(max-width: 1023.98px)', $html, 'only below 1024 px: a desktop keeps Filament\'s own memory');
        $this->assertStringContainsString('.am-desktop-only', $html);
        $this->assertStringContainsString('.md\\:table-cell', $html, 'Filament\'s prebuilt CSS lacks this class');
        // The compact desktop styles are untouched.
        $this->assertStringContainsString('font-size: 87.5%', $html);
    }

    public function test_the_phone_styles_only_change_what_is_under_768_px(): void
    {
        $css = view('filament.admin.mobile-styles')->render();
        $style = substr($css, strpos($css, '<style>'));

        // Strip every @media block; what is left (outside any query) may only be the card hider and the media-query-free helpers.
        $outside = preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $style);
        $this->assertStringNotContainsString('font-size: 100%', $outside);
        $this->assertStringNotContainsString('.fi-ta-table', $outside, 'no table rule applies outside a media query');
        $this->assertStringContainsString('.am-card { display: none; }', $outside);
    }

    public function test_an_order_still_opens_when_its_card_is_tapped(): void
    {
        $order = $this->codOrder();

        $html = Livewire::test(ListOrders::class)->html();

        $this->assertStringContainsString(OrderResource::getUrl('edit', ['record' => $order]), $html, 'each cell, the card included, links to the order');
        $this->assertInstanceOf(Order::class, $order);
        $this->assertInstanceOf(User::class, auth()->user());
    }
}
