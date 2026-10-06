<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManagePaymentSettings;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Support\PaymentActions;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Support\WalletPayments;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class WalletPaymentAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['role_id' => null, 'name' => 'Shop Owner']);
        $this->actingAs($this->admin);
    }

    private function order(string $method = 'bkash', array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => AdminRecords::customer()->id,
            'status' => 'pending',
            'subtotal' => 1450,
            'delivery_fee' => 80,
            'total' => 1530,
            'payment_method' => $method,
            'payment_sender_number' => $method === 'cod' ? null : '01733333333',
            'payment_trx_id' => $method === 'cod' ? null : 'TRX'.strtoupper(uniqid()),
        ], $overrides));
    }

    public function test_verifying_records_who_and_when(): void
    {
        $order = $this->order();
        $this->assertSame(WalletPayments::AWAITING, $order->payment_status);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('markPaymentVerified', ['note' => 'Matched in the app'])
            ->assertNotified('Payment marked as verified');

        $order->refresh();
        $this->assertSame('verified', $order->payment_status);
        $this->assertSame($this->admin->id, $order->payment_verified_by);
        $this->assertNotNull($order->payment_verified_at);
        $this->assertSame('Matched in the app', $order->payment_note);
    }

    public function test_failing_needs_a_note_and_records_who_and_when(): void
    {
        $order = $this->order('nagad');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('markPaymentFailed', ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);

        $this->assertSame(WalletPayments::AWAITING, $order->fresh()->payment_status);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('markPaymentFailed', ['note' => 'Amount was 1,000, not 1,530'])
            ->assertNotified('Payment marked as failed');

        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame($this->admin->id, $order->payment_verified_by);
        $this->assertSame('Amount was 1,000, not 1,530', $order->payment_note);
    }

    public function test_the_actions_are_not_offered_on_cash_on_delivery_orders(): void
    {
        $order = $this->order('cod');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('markPaymentVerified')
            ->assertActionHidden('markPaymentFailed');
    }

    public function test_a_decided_payment_can_be_changed_only_the_other_way(): void
    {
        $order = $this->order();
        PaymentActionsHelper::decide($order, 'verified');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('markPaymentVerified')
            ->assertActionVisible('markPaymentFailed');
    }

    public function test_saving_the_order_form_cannot_change_the_payment_status(): void
    {
        $order = $this->order();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['payment_status' => 'verified', 'payment_trx_id' => 'HACKED123', 'payment_note' => 'sneaky'])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame(WalletPayments::AWAITING, $order->payment_status);
        $this->assertNotSame('HACKED123', $order->payment_trx_id);
        $this->assertNull($order->payment_note);
    }

    public function test_the_list_shows_the_status_filters_and_quick_filter(): void
    {
        $waiting = $this->order();
        $verified = $this->order('nagad');
        PaymentActionsHelper::decide($verified, 'verified');
        $cod = $this->order('cod');

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$waiting, $verified, $cod])
            ->filterTable('payment_status', WalletPayments::AWAITING)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$verified, $cod]);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'to_verify')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$verified, $cod]);
    }

    public function test_a_row_action_decides_from_the_list(): void
    {
        $order = $this->order();

        Livewire::test(ListOrders::class)
            ->callTableAction('markPaymentVerified', $order, ['note' => null])
            ->assertNotified('Payment marked as verified');

        $this->assertSame('verified', $order->fresh()->payment_status);
    }

    public function test_the_sidebar_badge_counts_payments_to_verify(): void
    {
        $this->assertNull(OrderResource::getNavigationBadge());

        $this->order();
        $this->order('nagad');
        $this->order('cod');

        $this->assertSame('2', OrderResource::getNavigationBadge());
        $this->assertSame('Payment to verify', OrderResource::getNavigationBadgeTooltip());
    }

    public function test_the_order_page_shows_the_payment_panel_for_wallet_orders_only(): void
    {
        $wallet = $this->order();
        $cod = $this->order('cod');

        $this->get("/admin/orders/{$wallet->id}/edit")->assertOk()->assertSee('Transaction ID')->assertSee('Awaiting verification');
        $this->get("/admin/orders/{$cod->id}/edit")->assertOk()->assertDontSee('Transaction ID');
    }

    public function test_an_older_wallet_order_with_no_transaction_id_still_shows_everywhere(): void
    {
        // What the migration leaves behind: a wallet order from before this feature, with nothing to check.
        $old = $this->order('bkash', ['payment_trx_id' => null, 'payment_sender_number' => null]);

        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$old]);
        $this->get("/admin/orders/{$old->id}/edit")->assertOk()->assertSee('Awaiting verification');
        $this->getJson('/api/orders/lookup?order_id='.$old->id.'&phone='.$old->customer->phone)
            ->assertOk()->assertJsonPath('data.payment_status', 'awaiting_verification');
    }

    public function test_payment_settings_save_numbers_steps_and_email_and_keep_defaults_blank(): void
    {
        $page = Livewire::test(ManagePaymentSettings::class);

        $this->assertStringContainsString('{{amount}}', $page->get('data.bkash_instructions'));

        $page->fillForm([
            'bkash_number' => '01711111111',
            'nagad_instructions' => '<ol><li>Pay {{amount}} to {{number}} on Nagad.</li></ol>',
            'payment_notify_email' => 'owner@example.com',
        ])->call('save')->assertHasNoFormErrors();

        $settings = PaymentSetting::first();
        $this->assertSame('01711111111', $settings->bkash_number);
        $this->assertSame('owner@example.com', $settings->payment_notify_email);
        $this->assertNull($settings->bkash_instructions, 'Untouched default steps are stored blank.');
        $this->assertSame('<ol><li>Pay {{amount}} to {{number}} on Nagad.</li></ol>', $settings->nagad_instructions);
    }
}

/**
 * Applies a decision the same way the admin action does, for setting up test data.
 */
final class PaymentActionsHelper
{
    public static function decide(Order $order, string $status): void
    {
        PaymentActions::decide($order, $status, null);
    }
}
