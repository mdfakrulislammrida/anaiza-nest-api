<?php

namespace Tests\Feature\Courier;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Http\Resources\OrderResource as ApiOrderResource;
use App\Mail\TransactionalMail;
use App\Models\Category;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\Product;
use App\Support\Courier\CourierBooking;
use App\Support\Courier\CourierEffectPreview;
use App\Support\Courier\CourierStatusApplier;
use App\Support\Courier\CourierSync;
use App\Support\System\HealthChecks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The Pre-order status (OPS3-B P2): an order for something not yet in stock, set by a person only. It never books a parcel, a courier's report changes
 * nothing about it, it is not "pending too long", the switch sends no email, and moving it on works as for any order.
 */
class PreorderStatusTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->shop();
        $this->steadfast();
        $this->actingAs($this->admin());
        Mail::fake();
        Http::fake();
    }

    private function sent(string $key): int
    {
        return Mail::sent(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->templateKey === $key)->count();
    }

    private function preorder(array $overrides = []): Order
    {
        return $this->codOrder($overrides + ['status' => 'preorder']);
    }

    public function test_it_is_a_status_with_its_own_label_first_in_the_flow_and_its_own_colour(): void
    {
        $this->assertSame('Pre-order', OrderResource::STATUSES['preorder']);
        $this->assertSame('preorder', array_key_first(OrderResource::STATUSES), 'before Pending in the flow');
        $this->assertSame(['preorder', 'pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'], array_keys(OrderResource::STATUSES));

        $card = collect(OrderResource::phoneCard($this->preorder()))->flatMap(fn (array $row): array => [...($row['left'] ?? []), ...($row['right'] ?? [])]);
        $badge = $card->first(fn (array $cell): bool => ($cell['badge'] ?? null) === 'Pre-order');

        $this->assertNotNull($badge, 'the phone card shows the badge');
        $this->assertSame('preorder', $badge['color'], 'a colour no other status uses');
        $this->assertNotContains('preorder', ['gray', 'warning', 'info', 'success', 'danger']);
    }

    public function test_an_admin_sets_it_from_the_order_page_and_nothing_is_emailed_either_way(): void
    {
        $order = $this->codOrder(['status' => 'pending']);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->fillForm(['status' => 'preorder'])->call('save')->assertHasNoFormErrors();
        $this->afterResponse();
        $this->assertSame('preorder', $order->fresh()->status);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->fillForm(['status' => 'pending'])->call('save')->assertHasNoFormErrors();
        $this->afterResponse();
        $this->assertSame('pending', $order->fresh()->status);

        Mail::assertNothingSent();
    }

    public function test_it_has_its_own_tab_and_is_in_the_status_filter(): void
    {
        $pre = $this->preorder();
        $other = $this->codOrder(['status' => 'pending']);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'preorders')
            ->assertCanSeeTableRecords([$pre])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListOrders::class)
            ->filterTable('status', 'preorder')
            ->assertCanSeeTableRecords([$pre])
            ->assertCanNotSeeTableRecords([$other]);

        $tabs = Livewire::test(ListOrders::class)->instance()->getTabs();
        $this->assertArrayHasKey('preorders', $tabs);
        $this->assertSame('Pre-orders', $tabs['preorders']->getLabel());
    }

    public function test_send_to_courier_is_blocked_with_a_clear_message_and_books_nothing(): void
    {
        $order = $this->preorder();

        $plan = CourierBooking::preview($order, 'steadfast');
        $this->assertContains('Move this order to Processing first.', $plan['blocks']);

        $result = CourierBooking::book($order, 'steadfast');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Move this order to Processing first', $result['message']);
        $this->assertNull($order->fresh()->courier_booked_at);
        Http::assertNothingSent();
    }

    public function test_automatic_booking_never_books_a_pre_order_even_when_it_is_switched_on(): void
    {
        CourierSetting::current()->update(['auto_book' => true, 'default_courier' => 'steadfast']);
        $order = $this->preorder();

        CourierBooking::autoBook($order);

        $this->assertNull($order->fresh()->courier_booked_at);
        Http::assertNothingSent();
    }

    public function test_the_status_check_the_sync_now_button_and_the_preview_leave_it_alone(): void
    {
        $order = $this->booked($this->preorder());

        $summary = CourierSync::run();
        $this->assertSame(0, $summary['checked'], 'a Pre-order is not followed');
        Http::assertNothingSent();

        $one = CourierSync::one($order);
        $this->assertFalse($one['ok']);
        $this->assertStringContainsString('Pre-order', $one['message']);

        $this->assertSame([], CourierEffectPreview::rows(), 'the preview does not list it');
        Http::assertNothingSent();
    }

    public function test_a_couriers_report_about_a_pre_order_changes_nothing_and_is_written_down(): void
    {
        $order = $this->booked($this->preorder());
        $before = $order->only(['status', 'courier_status', 'courier_status_rank', 'courier_final_at', 'cod_collected_at']);

        foreach (['delivered', 'in_transit', 'cancelled'] as $status) {
            $this->assertSame('ignored_preorder', CourierStatusApplier::apply($order->fresh(), 'steadfast', $status, 'webhook'));
        }

        $this->assertSame($before, $order->fresh()->only(['status', 'courier_status', 'courier_status_rank', 'courier_final_at', 'cod_collected_at']));
        $this->assertSame(3, CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'status_ignored')->count());
        $this->assertStringContainsString('Pre-order', CourierEvent::query()->where('order_id', $order->id)->value('summary'));
        $this->afterResponse();
        $this->assertSame(0, $this->sent('order_delivered'));
    }

    public function test_it_moves_on_like_any_order_and_the_existing_emails_go_once(): void
    {
        $order = $this->preorder();

        $order->update(['status' => 'processing']);
        $this->afterResponse();
        $this->assertSame(0, $this->sent('order_shipped'), 'processing sends nothing');

        $order->update(['status' => 'shipped']);
        $this->afterResponse();
        $this->assertSame(1, $this->sent('order_shipped'));

        $order->update(['status' => 'delivered']);
        $this->afterResponse();
        $this->assertSame(1, $this->sent('order_delivered'));
    }

    public function test_a_pre_order_can_be_cancelled_and_that_stays_final_against_a_courier(): void
    {
        $order = $this->booked($this->preorder());

        $order->update(['status' => 'cancelled']);
        $this->afterResponse();
        $this->assertSame('cancelled', $order->fresh()->status);

        CourierStatusApplier::apply($order->fresh(), 'steadfast', 'delivered', 'poll');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNull($order->fresh()->cod_collected_at);
    }

    public function test_it_is_not_counted_as_pending_for_more_than_a_day(): void
    {
        $this->preorder()->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();
        $old = $this->codOrder(['status' => 'pending']);
        $old->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $row = HealthChecks::orders();
        $this->assertStringContainsString('1 still Pending after a day', $row['detail'] ?? json_encode($row));

        $old->forceFill(['status' => 'preorder'])->saveQuietly();
        $this->assertStringContainsString('Nothing left waiting for more than a day', json_encode(HealthChecks::orders()));
    }

    public function test_stock_is_untouched_by_the_status(): void
    {
        $product = Product::create(['category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id, 'name' => 'Mug', 'slug' => 'mug', 'price' => 500, 'stock_quantity' => 7, 'sku' => 'MG-1', 'is_active' => true]);
        $order = $this->codOrder(['status' => 'pending']);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'Mug', 'quantity' => 2, 'price' => 500]);

        $order->update(['status' => 'preorder']);
        $order->update(['status' => 'processing']);

        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    public function test_the_api_answers_with_the_status_and_its_label(): void
    {
        $order = $this->preorder();

        $data = (new ApiOrderResource($order))->toArray(Request::create('/'));

        $this->assertSame('preorder', $data['status']);
        $this->assertSame('Pre-order', $data['status_label']);
        $this->assertSame('Processing', (new ApiOrderResource($this->codOrder(['status' => 'processing'])))->toArray(Request::create('/'))['status_label']);
        $this->assertSame('Brand new', (new ApiOrderResource($this->codOrder(['status' => 'brand_new'])))->toArray(Request::create('/'))['status_label'], 'an unknown status is still words, never a code with an underscore');
    }

    public function test_the_migration_free_change_leaves_existing_orders_as_they_are(): void
    {
        // The status is a plain text column: no data was changed to make room for it.
        $statuses = [];
        foreach (['pending', 'processing', 'shipped', 'delivered', 'cancelled'] as $status) {
            $statuses[] = $this->codOrder(['status' => $status])->id;
        }

        $this->assertSame(['pending', 'processing', 'shipped', 'delivered', 'cancelled'], Order::query()->whereIn('id', $statuses)->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, Order::query()->where('status', 'preorder')->count(), 'nothing sets it by itself');
    }
}
