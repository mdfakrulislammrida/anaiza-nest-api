<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmailTemplateResource\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplateResource\Pages\ListEmailTemplates;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Mail\TransactionalMail;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class EmailTemplatesAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['role_id' => null, 'email' => 'owner@example.com']);
        $this->actingAs($this->admin);
    }

    /**
     * Every Livewire request in a test runs what earlier requests queued for "after the response" again, because tests share one
     * application; a real request is a fresh process. Call this after each step to count each email once.
     */
    private function forgetTerminating(): void
    {
        (new ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    private function template(string $key): EmailTemplate
    {
        EmailTemplate::ensureAll();

        return EmailTemplate::query()->where('key', $key)->firstOrFail();
    }

    public function test_the_list_shows_the_five_emails_even_on_a_fresh_install(): void
    {
        Livewire::test(ListEmailTemplates::class)
            ->assertSee('Order confirmation')
            ->assertSee('Order shipped')
            ->assertSee('Order delivered')
            ->assertSee('Order cancelled')
            ->assertSee('Welcome')
            ->assertSee('Built-in');

        $this->assertSame(5, EmailTemplate::count());
    }

    public function test_the_old_single_page_is_gone_and_the_area_is_in_settings(): void
    {
        $this->get('/admin/order-confirmation-email')->assertNotFound();
        $this->get('/admin/email-templates')->assertOk()->assertSee('Email templates');
    }

    public function test_the_edit_form_starts_from_the_built_in_wording_and_lists_the_placeholders(): void
    {
        $template = $this->template('order_shipped');

        $test = Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()]);

        $this->assertStringContainsString('has shipped', $test->get('data.subject'));
        $this->assertStringContainsString('{{shipping_details}}', $test->get('data.intro_html'));
        $this->get("/admin/email-templates/{$template->id}/edit")->assertOk()
            ->assertSee('{{order_items_table}}', false)->assertSee('{{order_totals}}', false)
            ->assertSee('{{tracking_number}}', false)->assertSee('{{courier_name}}', false)
            ->assertSee('Custom full HTML');
    }

    public function test_saving_wording_stores_it_and_unchanged_default_text_stays_blank(): void
    {
        $template = $this->template('order_shipped');

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['is_active' => true, 'subject' => 'Shipped: #{{order_number}}', 'footer_note' => 'A note.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $template->refresh();
        $this->assertTrue($template->is_active);
        $this->assertSame('Shipped: #{{order_number}}', $template->subject);
        $this->assertSame('A note.', $template->footer_note);
        $this->assertNull($template->intro_html, 'Intro was left as the built-in text, so it stays blank and keeps following it.');
        $this->assertNull($template->closing_html);
    }

    public function test_the_preview_follows_the_form_with_sample_data(): void
    {
        $template = $this->template('order_delivered');

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->assertSee('Review your purchase')
            ->assertSee('Sample tea set for two')
            ->fillForm(['subject' => 'Arrived: #{{order_number}} for {{customer_name}}'])
            ->assertSee('Arrived: #1042 for Sample customer')
            ->fillForm(['use_custom_html' => true, 'custom_html' => '<p>Custom body {{order_total}}</p>'])
            ->assertSee('Custom body ৳2,980');
    }

    public function test_send_test_email_sends_the_current_wording_to_the_admin(): void
    {
        Mail::fake();
        $template = $this->template('order_confirmation');

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['subject' => 'Test: order #{{order_number}}'])
            ->callAction('sendTest')
            ->assertNotified('Test email sent to owner@example.com');

        Mail::assertSent(TransactionalMail::class, function (TransactionalMail $mail): bool {
            return $mail->hasTo('owner@example.com')
                && $mail->hasSubject('Test: order #1042')
                && count($mail->attachments()) === 1
                && str_contains($mail->render(), 'Sample customer');
        });
        $this->assertFalse($template->fresh()->is_active, 'Sending a test saves nothing.');
        $this->assertNull($template->fresh()->subject);
    }

    public function test_send_test_email_reports_a_mail_problem_instead_of_breaking(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused'));
        $template = $this->template('welcome');

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->callAction('sendTest')
            ->assertNotified(Notification::make()->danger()->title('The test email could not be sent')->body('Connection refused')->persistent());
    }

    // ---- order status from the admin

    private function order(): Order
    {
        return Order::create([
            'customer_id' => AdminRecords::customer(['email' => 'buyer'.uniqid().'@example.com'])->id, 'status' => 'pending',
            'subtotal' => 1450, 'delivery_fee' => 80, 'total' => 1530, 'payment_method' => 'cod',
        ]);
    }

    public function test_changing_the_status_on_the_order_page_sends_the_email_once_with_courier_details(): void
    {
        Mail::fake();
        $order = $this->order();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm(['status' => 'shipped', 'courier_name' => 'Pathao', 'tracking_number' => 'PT-5'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->forgetTerminating();

        Mail::assertSent(TransactionalMail::class, 1);
        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $mail) => str_contains($mail->render(), 'Pathao') && str_contains($mail->render(), 'PT-5'));

        // Saving again without a status change sends nothing more.
        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->call('save');
        $this->forgetTerminating();
        Mail::assertSent(TransactionalMail::class, 1);
    }

    public function test_the_bulk_action_changes_many_orders_and_each_customer_gets_one_email(): void
    {
        Mail::fake();
        $orders = collect([$this->order(), $this->order(), $this->order()]);

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('changeStatus', $orders, ['status' => 'delivered']);
        $this->forgetTerminating();

        $this->assertSame(['delivered', 'delivered', 'delivered'], $orders->map(fn (Order $order) => $order->fresh()->status)->all());
        Mail::assertSent(TransactionalMail::class, 3);

        Livewire::test(ListOrders::class)->callTableBulkAction('changeStatus', $orders, ['status' => 'delivered']);
        $this->forgetTerminating();
        Mail::assertSent(TransactionalMail::class, 3);
    }
}
