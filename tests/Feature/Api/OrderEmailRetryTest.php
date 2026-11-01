<?php

namespace Tests\Feature\Api;

use App\Mail\OrderConfirmationMail;
use App\Mail\TransactionalMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\SystemEvent;
use App\Support\Email\TransactionalEmails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An order email the mail server did not take (a timeout) is remembered on the order and sent again: 15 minutes, 1 hour and 3 hours after each failure,
 * within 24 hours of the first, never twice, and only when those tries are used up does it reach System health. All mail is faked.
 */
class OrderEmailRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 10:00:00');
        SiteSetting::create(['site_name' => 'Anaiza Nest']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function order(string $status = 'pending'): Order
    {
        $customer = Customer::create(['name' => 'Nusrat Jahan', 'email' => uniqid('n').'@example.test', 'phone' => '01712345678', 'password' => 'x']);

        return Order::create([
            'customer_id' => $customer->id, 'status' => $status, 'subtotal' => 600, 'delivery_fee' => 80, 'total' => 680, 'payment_method' => 'cod',
            'ship_name' => 'Nusrat Jahan', 'ship_email' => $customer->email, 'ship_phone' => '01712345678', 'ship_district' => 'Dhaka', 'ship_division' => 'Dhaka',
        ]);
    }

    private function afterResponse(): void
    {
        $this->app->terminate();
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    /** The mail server does not answer. */
    private function timeout(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection to mail.example.test timed out after 10 seconds'));
    }

    private function mailWorks(): void
    {
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();
    }

    private function retryAfter(int $minutesLater): array
    {
        Carbon::setTestNow(now()->addMinutes($minutesLater));

        return TransactionalEmails::retryFailed();
    }

    public function test_a_confirmation_that_times_out_is_remembered_not_reported_yet_and_sent_15_minutes_later(): void
    {
        $order = $this->order();
        $this->timeout();

        TransactionalEmails::orderConfirmation($order);
        $this->afterResponse();

        $this->assertSame(1, $order->fresh()->emails_failed['confirmation']['tries']);
        $this->assertSame(0, SystemEvent::query()->where('type', 'mail_failed')->count(), 'being tried again is not an error yet');

        $this->mailWorks();
        $this->assertSame(['sent' => 0, 'failed' => 0, 'dropped' => 0, 'waiting' => 1], $this->retryAfter(10), 'not due yet');
        Mail::assertNothingSent();

        $this->assertSame(1, $this->retryAfter(6)['sent']);
        Mail::assertSent(OrderConfirmationMail::class, 1);
        $this->assertNull($order->fresh()->emails_failed, 'sent: forgotten');

        $this->assertSame(0, $this->retryAfter(600)['sent'], 'and never again');
        Mail::assertSent(OrderConfirmationMail::class, 1);
    }

    public function test_the_tries_are_15_minutes_1_hour_and_3_hours_after_each_failure_then_it_is_reported(): void
    {
        $order = $this->order();
        $this->timeout();

        TransactionalEmails::orderConfirmation($order);
        $this->afterResponse();

        $this->assertSame(['sent' => 0, 'failed' => 1, 'dropped' => 0, 'waiting' => 0], $this->retryAfter(15));      // 2nd failure
        $this->assertSame(1, $this->retryAfter(59)['waiting'], '1 hour after that one, not before');
        $this->assertSame(1, $this->retryAfter(1)['failed']);                                                          // 3rd
        $this->assertSame(1, $this->retryAfter(179)['waiting']);
        $this->assertSame(0, SystemEvent::query()->where('type', 'mail_failed')->count());
        $this->assertSame(1, $this->retryAfter(1)['failed']);                                                          // 4th: the first try and three more

        $this->assertSame(1, SystemEvent::query()->where('type', 'mail_failed')->count(), 'only now does System health hear of it');
        $this->assertStringContainsString('could not be sent after 4 tries', SystemEvent::query()->where('type', 'mail_failed')->value('message'));
        $this->assertSame(['sent' => 0, 'failed' => 0, 'dropped' => 0, 'waiting' => 0], $this->retryAfter(2000), 'and there is no fifth');
    }

    public function test_nothing_is_tried_for_an_email_that_first_failed_more_than_24_hours_ago(): void
    {
        $order = $this->order();
        $this->timeout();
        TransactionalEmails::orderConfirmation($order);
        $this->afterResponse();
        $this->mailWorks();

        $summary = $this->retryAfter(25 * 60);   // the scheduler was stopped for a day

        $this->assertSame(1, $summary['dropped']);
        Mail::assertNothingSent();
        $this->assertSame(1, SystemEvent::query()->where('type', 'mail_failed')->count());
        $this->assertNull($order->fresh()->emails_failed);
    }

    public function test_a_shipped_email_that_failed_is_sent_once_when_the_order_is_still_shipped(): void
    {
        $order = $this->order('shipped');
        $this->timeout();

        TransactionalEmails::orderStatusChanged($order);
        $this->afterResponse();

        $this->assertNull($order->fresh()->emails_sent, 'a failed send is not recorded as sent');
        $this->assertSame(1, $order->fresh()->emails_failed['shipped']['tries']);

        $this->mailWorks();
        $this->assertSame(1, $this->retryAfter(15)['sent']);
        Mail::assertSent(TransactionalMail::class, 1);
        $this->assertArrayHasKey('shipped', $order->fresh()->emails_sent, 'recorded as sent, so a later status change cannot send it again');

        TransactionalEmails::orderStatusChanged($order->fresh());
        $this->afterResponse();
        Mail::assertSent(TransactionalMail::class, 1);
    }

    public function test_a_shipped_email_is_dropped_when_the_order_has_moved_on(): void
    {
        $order = $this->order('shipped');
        $this->timeout();
        TransactionalEmails::orderStatusChanged($order);
        $this->afterResponse();
        $this->mailWorks();
        $order->forceFill(['status' => 'delivered'])->saveQuietly();

        $summary = $this->retryAfter(15);

        $this->assertSame(1, $summary['dropped']);
        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->emails_failed);
        $this->assertSame(0, SystemEvent::query()->where('type', 'mail_failed')->count(), 'a stale note is not an error');
    }

    public function test_a_status_change_that_got_there_first_means_the_retry_sends_nothing(): void
    {
        $order = $this->order('shipped');
        $this->timeout();
        TransactionalEmails::orderStatusChanged($order);
        $this->afterResponse();
        $this->mailWorks();

        // the admin changes the status again before the retry runs, and that send works
        TransactionalEmails::orderStatusChanged($order->fresh());
        $this->afterResponse();
        Mail::assertSent(TransactionalMail::class, 1);

        $this->retryAfter(15);

        Mail::assertSent(TransactionalMail::class, 1);
        $this->assertNull($order->fresh()->emails_failed);
    }

    public function test_an_email_without_a_retry_kind_goes_straight_to_system_health(): void
    {
        $customer = Customer::create(['name' => 'Ann', 'email' => 'ann@example.test', 'phone' => '01712345679', 'password' => 'x']);
        $this->timeout();

        TransactionalEmails::welcome($customer);
        $this->afterResponse();

        $this->assertSame(1, SystemEvent::query()->where('type', 'mail_failed')->count());
    }

    public function test_the_command_runs_and_reports(): void
    {
        $order = $this->order();
        $this->timeout();
        TransactionalEmails::orderConfirmation($order);
        $this->afterResponse();
        $this->mailWorks();
        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertSame(0, Artisan::call('emails:retry'));
        $this->assertStringContainsString('1 sent, 0 failed again, 0 dropped, 0 not due yet', Artisan::output());
    }

    public function test_a_smtp_server_that_does_not_answer_is_waited_for_at_most_ten_seconds(): void
    {
        $this->assertSame(10, config('mail.mailers.smtp.timeout'));
    }
}
