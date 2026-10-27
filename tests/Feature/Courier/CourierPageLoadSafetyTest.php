<?php

namespace Tests\Feature\Courier;

use App\Filament\Pages\ManageCourierSettings;
use App\Models\CourierSetting;
use App\Support\Courier\CourierException;
use App\Support\Courier\CourierSync;
use App\Support\Courier\PathaoClient;
use App\Support\Courier\SteadfastClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * Steadfast and Pathao are only ever called from a button (Test, Send to courier), from the courier sync in the scheduler, and from the automatic booking
 * after a status change. No admin page calls them while it is drawn. And when a call does go wrong (no answer, or a refusal), nothing reaches the page.
 */
class CourierPageLoadSafetyTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->shop();
        $this->actingAs($this->admin());
    }

    public function test_drawing_any_admin_page_makes_no_call_to_a_courier_or_anything_else(): void
    {
        $this->steadfast();
        $this->pathao();
        $order = $this->codOrder(['status' => 'shipped']);
        $this->booked($order);
        Http::fake();

        foreach (['/admin', '/admin/products/create', '/admin/products', '/admin/orders', '/admin/orders/'.$order->id.'/edit', ManageCourierSettings::getUrl(), '/admin/system-health', '/admin/courier-statuses'] as $url) {
            $this->get($url)->assertSuccessful();
        }

        Http::assertNothingSent();
    }

    public function test_a_test_button_that_gets_no_answer_shows_a_message_and_throws_nothing(): void
    {
        $this->steadfast();
        $this->pathao();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        Livewire::test(ManageCourierSettings::class)
            ->callAction('testSteadfast')->assertNotified('Steadfast did not connect')
            ->callAction('testPathao')->assertNotified('Pathao did not connect');
    }

    public function test_a_refused_key_is_noted_and_nothing_more_is_sent_with_it_until_the_keys_are_saved_again(): void
    {
        $this->steadfast();
        Http::fake([
            '*/ping' => Http::response(['status' => 200], 200),
            '*/get_balance' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        Livewire::test(ManageCourierSettings::class)->callAction('testSteadfast')->assertNotified('Steadfast did not connect');

        $settings = CourierSetting::current();
        $this->assertNotNull($settings->steadfast_auth_failed_at, 'the 401 is recorded');

        // Anything else (the sync, a booking) is refused without a request.
        Http::fake();
        foreach ([fn () => (new SteadfastClient($settings))->balance(), fn () => (new SteadfastClient($settings))->statusByInvoice('42'), fn () => SteadfastClient::forButton($settings)->createOrder(['invoice' => '42', 'recipient_name' => 'N', 'recipient_phone' => '01712345678', 'recipient_address' => 'A', 'cod_amount' => 1])] as $call) {
            try {
                $call();
                $this->fail('a locked client must refuse');
            } catch (CourierException $e) {
                $this->assertSame(CourierException::AUTH, $e->kind);
            }
        }
        Http::assertNothingSent();
    }

    public function test_the_courier_sync_survives_a_courier_that_does_not_answer(): void
    {
        $this->steadfast();
        $this->booked($this->codOrder(['status' => 'shipped']));
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $summary = CourierSync::run();

        $this->assertSame(1, $summary['failed']);
    }

    public function test_page_and_scheduler_calls_are_quick_and_only_a_button_waits_longer(): void
    {
        $timeouts = function (object $client): array {
            $reflection = new \ReflectionObject($client);

            return [$reflection->getProperty('connectTimeout')->getValue($client), $reflection->getProperty('timeout')->getValue($client)];
        };
        $settings = CourierSetting::current();

        $this->assertSame([3, 8], $timeouts(new SteadfastClient($settings)));
        $this->assertSame([3, 8], $timeouts(new PathaoClient($settings)));
        $this->assertSame([5, 20], $timeouts(SteadfastClient::forButton($settings)));
        $this->assertSame([5, 20], $timeouts(PathaoClient::forButton($settings)));

        // The only places that wait the long time are the buttons and the booking.
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $text = file_get_contents($file->getPathname());
                if (preg_match('/(Steadfast|Pathao)Client::forButton\(/', $text)) {
                    $callers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
                }
            }
        }
        sort($callers);
        $this->assertSame(['Filament/Pages/ManageCourierSettings.php', 'Support/Courier/CourierBooking.php'], $callers);
    }
}
