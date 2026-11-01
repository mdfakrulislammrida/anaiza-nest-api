<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Utm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The same campaign is stored as the same text however the link was encoded, and only the five campaign fields are ever touched.
 */
class UtmCleanTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attributes = []): Order
    {
        $customer = Customer::query()->first() ?? Customer::create(['name' => 'Ann+Lee', 'email' => 'ann@example.test', 'phone' => '01712345678', 'password' => 'x']);

        return Order::create($attributes + ['customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 600, 'delivery_fee' => 80, 'total' => 680, 'payment_method' => 'cod', 'gift_note' => 'Happy+birthday']);
    }

    /** Writes the values straight into the table, as orders stored before the rule are. */
    private function raw(Order $order, array $values): void
    {
        DB::table('orders')->where('id', $order->id)->update($values);
    }

    public function test_the_rule_in_every_case(): void
    {
        foreach ([
            ['AN+%7C+SALES+%7C+Broad', 'AN | SALES | Broad'],     // plus signs and percent-encoding together
            ['AN%20%7C%20SALES', 'AN | SALES'],                    // percent-encoding only
            ['AN | SALES', 'AN | SALES'],                          // already clean
            ['AN+|+SALES+|+Broad', 'AN | SALES | Broad'],          // the audit's example: a plus instead of a space
            ['AN%2B%7C%2BSALES', 'AN | SALES'],                    // encoded twice: decoded once, its plus signs are the spaces
            ['100% off', '100% off'],                              // a real % is left alone
            ['50%', '50%'],
            ['100%+off', '100%+off'],                              // not a %XX sequence, one plus: untouched
            ['sale%2525', 'sale%25'],                              // decoded once, never twice
            ['Buy1+Get1', 'Buy1+Get1'],                            // one plus is a plus
            ['C++', 'C++'],
            ['Buy 1 + Get 1', 'Buy 1 + Get 1'],                    // has spaces: its plus is a plus
            ['  AN   |  SALES ', 'AN | SALES'],                    // trimmed, one space
            ['%E0%A4', '%E0%A4'],                                  // a broken sequence is not turned into something else
            ['', null],
            ['   ', null],
            ['%20%20', null],
            [null, null],
        ] as [$given, $expected]) {
            $this->assertSame($expected, Utm::clean($given), var_export($given, true));
        }
    }

    public function test_saving_an_order_cleans_the_five_fields_and_nothing_else(): void
    {
        $order = $this->order([
            'utm_source' => 'face+book+ads', 'utm_medium' => 'cpc', 'utm_campaign' => 'AN+|+SALES+|+Broad', 'utm_content' => 'AN%20video%20one', 'utm_term' => '  tea  ',
            'fbclid' => 'IwAR0abc+def%2Bx', 'gclid' => 'a+b+c', 'ship_name' => 'Ann+Lee', 'landing_slug' => 'a+b+c', 'payment_note' => '50%25 paid+later',
        ]);

        $order = $order->fresh();

        $this->assertSame(['face book ads', 'cpc', 'AN | SALES | Broad', 'AN video one', 'tea'], [$order->utm_source, $order->utm_medium, $order->utm_campaign, $order->utm_content, $order->utm_term]);
        // untouched, character for character
        $this->assertSame('IwAR0abc+def%2Bx', $order->fbclid);
        $this->assertSame('a+b+c', $order->gclid);
        $this->assertSame('Ann+Lee', $order->ship_name);
        $this->assertSame('a+b+c', $order->landing_slug);
        $this->assertSame('50%25 paid+later', $order->payment_note);
        $this->assertSame('Happy+birthday', $order->gift_note);
    }

    public function test_a_value_that_is_already_clean_is_not_changed_and_a_later_save_leaves_it_alone(): void
    {
        $order = $this->order(['utm_campaign' => 'AN | SALES | 100% off']);
        $this->assertSame('AN | SALES | 100% off', $order->fresh()->utm_campaign);

        $order->update(['status' => 'processing']);

        $this->assertSame('AN | SALES | 100% off', $order->fresh()->utm_campaign);
    }

    public function test_an_order_placed_through_the_api_stores_the_clean_campaign(): void
    {
        $product = Product::create(['category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id, 'name' => 'Mug', 'slug' => 'mug', 'price' => 600, 'stock_quantity' => 9, 'sku' => 'M-1', 'is_active' => true]);

        $response = $this->postJson('/api/orders', [
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01712345679', 'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
            'payment_method' => 'cod', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'utm_source' => 'facebook', 'utm_campaign' => 'AN+|+SALES+|+Broad', 'utm_term' => 'tea%20set',
        ])->assertCreated();

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame('AN | SALES | Broad', $order->utm_campaign);
        $this->assertSame('tea set', $order->utm_term);
        $this->assertSame('facebook', $order->utm_source);
    }

    // ---- orders:utm-clean

    public function test_the_preview_counts_what_would_change_shows_samples_and_changes_nothing(): void
    {
        $dirty = $this->order();
        $this->raw($dirty, ['utm_campaign' => 'AN+|+SALES+|+Broad', 'utm_source' => 'face+book+ads']);
        $other = $this->order();
        $this->raw($other, ['utm_campaign' => 'AN%20%7C%20SALES']);
        $clean = $this->order();
        $this->raw($clean, ['utm_campaign' => 'AN | SALES', 'utm_source' => 'facebook']);
        $real = $this->order();
        $this->raw($real, ['utm_campaign' => '100% off']);

        $exit = Artisan::call('orders:utm-clean');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Orders whose campaign text would change: 2', $output);
        $this->assertMatchesRegularExpression('/utm_campaign\s*\|\s*2/', $output);
        $this->assertMatchesRegularExpression('/utm_source\s*\|\s*1/', $output);
        $this->assertStringContainsString('AN+|+SALES+|+Broad', $output);
        $this->assertStringContainsString('AN | SALES | Broad', $output);
        $this->assertStringContainsString('Dry run: nothing was changed', $output);
        $this->assertSame('AN+|+SALES+|+Broad', $dirty->fresh()->utm_campaign);
        $this->assertSame('AN%20%7C%20SALES', $other->fresh()->utm_campaign);
    }

    public function test_apply_updates_only_the_campaign_fields_of_those_orders(): void
    {
        $dirty = $this->order(['fbclid' => 'IwAR0x']);
        $this->raw($dirty, ['utm_campaign' => 'AN+|+SALES+|+Broad', 'gift_note' => 'a+b', 'updated_at' => '2026-01-01 00:00:00']);
        $clean = $this->order();
        $this->raw($clean, ['utm_campaign' => 'AN | SALES']);
        $real = $this->order();
        $this->raw($real, ['utm_campaign' => '100% off']);

        $exit = Artisan::call('orders:utm-clean', ['--apply' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Updated 1 order(s)', Artisan::output());
        $fresh = $dirty->fresh();
        $this->assertSame('AN | SALES | Broad', $fresh->utm_campaign);
        $this->assertSame('a+b', $fresh->gift_note, 'nothing else is touched');
        $this->assertSame('IwAR0x', $fresh->fbclid);
        $this->assertSame('2026-01-01 00:00:00', $fresh->updated_at->format('Y-m-d H:i:s'), 'the order\'s own updated time is left alone');
        $this->assertSame('AN | SALES', $clean->fresh()->utm_campaign);
        $this->assertSame('100% off', $real->fresh()->utm_campaign);

        Artisan::call('orders:utm-clean');
        $this->assertStringContainsString('Nothing to clean', Artisan::output());
    }
}
