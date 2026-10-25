<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReviewRequestSetting;
use App\Support\Courier\CourierBooking;
use App\Support\InvoicePdf;
use App\Support\Legacy\LegacyOrderImporter;
use App\Support\Legacy\SqlDumpReader;
use App\Support\Reviews\ReviewRequests;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

class LegacyOrderImportTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        parent::tearDown();
    }

    // ---- A tiny made-up export in the old site's shape (no real data anywhere)

    private function sql(string $table, array $rows): string
    {
        $columns = array_keys($rows[0]);
        $values = array_map(fn (array $row): string => '('.implode(',', array_map(
            fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $v)."'"),
            $row,
        )).')', $rows);

        return "INSERT INTO `{$table}` (`".implode('`, `', $columns).'`) VALUES '.implode(",\n", $values).";\n";
    }

    private function customerRow(int $id, string $phone, string $name = 'Test Person'): array
    {
        return ['id' => $id, 'name' => $name, 'slug' => 'c'.$id, 'phone' => $phone, 'email' => null, 'district' => null, 'area' => null, 'address' => null, 'status' => 'active', 'created_at' => '2026-05-01 10:00:00', 'updated_at' => '2026-05-01 10:00:00'];
    }

    private function orderRow(int $id, string $invoice, int $customer, int $amount, int $fee = 70, int $status = 5, int $discount = 0, string $at = '2026-06-10 15:30:00'): array
    {
        return ['id' => $id, 'invoice_id' => $invoice, 'amount' => $amount, 'discount' => $discount, 'shipping_charge' => $fee, 'customer_id' => $customer, 'admin_note' => null, 'order_status' => $status, 'note' => null, 'created_at' => $at, 'updated_at' => $at];
    }

    private function lineRow(int $id, int $order, string $name, int $price, int $qty = 1, ?string $size = null): array
    {
        return ['id' => $id, 'order_id' => $order, 'product_id' => 9000 + $id, 'product_name' => $name, 'purchase_price' => 0, 'sale_price' => $price, 'product_discount' => 0, 'product_size' => $size, 'product_color' => null, 'qty' => $qty, 'created_at' => '2026-06-10 15:30:00', 'updated_at' => '2026-06-10 15:30:00'];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function export(): array
    {
        return [
            'customers' => [
                $this->customerRow(1, '+8801712345678'),
                $this->customerRow(2, '1812345678'),
                $this->customerRow(3, '01712-345678'), // the same number as customer 1
            ],
            'orders' => [
                $this->orderRow(10, '30001', 1, 1370, 70, 5),                // 2 x 650 + 70 = 1370
                $this->orderRow(11, '30002', 2, 570, 70, 6, 0),               // 500 + 70
                $this->orderRow(12, '30003', 3, 1180, 120, 3, 40),            // 500 + 700 + 120 - 40 = 1280? no: see below
                $this->orderRow(13, '30004', 1, 900, 70, 5),                  // no lines
                $this->orderRow(14, '30005', 99, 900, 70, 5),                 // customer not in the export
                $this->orderRow(15, '30001', 1, 900, 70, 5),                  // the number repeats
                $this->orderRow(16, '30006', 1, 900, 70, 4),                  // status 4 has no mapping
            ],
            'order_details' => [
                $this->lineRow(1, 10, 'Linen tea set', 650, 2),
                $this->lineRow(2, 11, 'Brass tray', 500, 1, 'Large'),
                $this->lineRow(3, 12, 'Cotton runner', 500, 1),
                $this->lineRow(4, 12, 'Glass jar', 350, 2),                   // 500 + 700 + 120 - 40 = 1280, but the old total says 1180
                $this->lineRow(5, 14, 'Whatever', 100),
                $this->lineRow(6, 16, 'Whatever', 100),
                $this->lineRow(7, 777, 'Belongs to no order', 100),            // an orphan line
            ],
        ];
    }

    private function plan(?array $tables = null, ?array $map = null): array
    {
        return LegacyOrderImporter::plan($tables ?? $this->export(), $map ?? LegacyOrderImporter::DEFAULT_STATUS_MAP);
    }

    private function folder(array $tables): string
    {
        $folder = storage_path('framework/testing/legacy-'.uniqid());
        File::ensureDirectoryExists($folder);
        $this->cleanup[] = $folder;

        foreach ($tables as $name => $rows) {
            File::put($folder.'/'.$name.'.sql', $this->sql($name, $rows));
        }

        return $folder;
    }

    // ---- reading the dump

    public function test_the_reader_handles_quotes_escapes_nulls_and_several_rows(): void
    {
        $rows = SqlDumpReader::rows("INSERT INTO `t` (`id`, `name`, `note`, `price`) VALUES\n(1, 'It''s a \\'tray\\'', NULL, 12.5),\n(2, 'a,b)(c', 'x\\ny', 3);");

        $this->assertSame([['id' => 1, 'name' => "It's a 'tray'", 'note' => null, 'price' => 12.5], ['id' => 2, 'name' => 'a,b)(c', 'note' => "x\ny", 'price' => 3]], $rows['t']);
    }

    // ---- phone numbers and statuses

    public function test_phones_are_normalised_to_01_and_nine_digits(): void
    {
        foreach (['+8801712345678', '8801712345678', '1712345678', '01712-345678', '017 1234 5678', ' 01712345678 ', '+880 1712 345678'] as $typed) {
            $this->assertSame('01712345678', LegacyOrderImporter::phone($typed), $typed);
        }

        foreach (['', null, '12345', '02-9876543', '01212345678', 'abc'] as $typed) {
            $this->assertNull(LegacyOrderImporter::phone($typed), (string) $typed);
        }
    }

    public function test_a_status_map_is_read_with_confirmed_meaning_processing_and_a_bad_one_is_refused(): void
    {
        $this->assertSame([2 => 'processing', 3 => 'shipped', 5 => 'delivered', 6 => 'cancelled', 7 => 'returned'], LegacyOrderImporter::parseStatusMap('2=confirmed, 3=shipped,5=delivered,6=cancelled,7=returned'));

        foreach (['2=done', 'x=pending', '2', '2=pending,,'] as $bad) {
            try {
                LegacyOrderImporter::parseStatusMap($bad);
                $this->assertSame('2=pending,,', $bad, 'only the empty part is tolerated');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('code=status', $e->getMessage());
            }
        }
    }

    public function test_the_old_codes_become_our_statuses(): void
    {
        $plan = $this->plan(null, LegacyOrderImporter::parseStatusMap('5=returned,6=cancelled,3=confirmed,4=pending'));
        $byNumber = array_column($plan['orders'], 'status', 'number');

        $this->assertSame('returned', $byNumber['30001']);
        $this->assertSame('cancelled', $byNumber['30002']);
        $this->assertSame('processing', $byNumber['30003']);
        $this->assertSame('pending', $byNumber['30006']);
    }

    // ---- the plan

    public function test_the_plan_counts_what_it_skips_and_why(): void
    {
        $plan = $this->plan();

        $this->assertSame(7, $plan['in_file']);
        $this->assertSame(['30001', '30002', '30003'], array_column($plan['orders'], 'number'));
        $this->assertSame([
            'no line items in the export' => 1,
            'customer not in the export' => 1,
            'order number repeated in the export' => 1,
            'status code 4 has no mapping' => 1,
        ], $plan['skipped']);
        $this->assertSame(1, $plan['orphan_lines']);
        $this->assertSame(1370 + 570 + 1180, $plan['taka']);
        $this->assertSame(1, $plan['total_not_from_lines'], 'the one order whose lines do not add up is reported, not repriced');
        $this->assertSame(['delivered' => 1, 'cancelled' => 1, 'shipped' => 1], $plan['by_status']);
    }

    public function test_the_plan_report_holds_no_personal_data(): void
    {
        $tables = $this->export();
        $tables['customers'][0]['name'] = 'Zubaida Unique-Name';
        $tables['customers'][0]['phone'] = '01799999999';

        $json = json_encode(array_diff_key($this->plan($tables), ['orders' => 1]));

        $this->assertStringNotContainsString('Zubaida', $json);
        $this->assertStringNotContainsString('01799999999', $json);
    }

    // ---- the import

    public function test_the_totals_dates_status_and_lines_are_kept_exactly(): void
    {
        LegacyOrderImporter::import($this->plan());

        $order = Order::query()->where('legacy_order_id', '30001')->with('items')->firstOrFail();

        $this->assertSame('legacy_import', $order->source);
        $this->assertTrue($order->isLegacy());
        $this->assertSame('30001', $order->number());
        $this->assertSame('delivered', $order->status);
        $this->assertSame(1370, $order->total);
        $this->assertSame(70, $order->delivery_fee);
        $this->assertSame(1300, $order->subtotal);
        $this->assertSame(0, $order->discount_amount);
        $this->assertSame($order->total, $order->subtotal + $order->delivery_fee - $order->discount_amount);
        $this->assertSame('cod', $order->payment_method);
        // Bangladesh time in the old export, UTC here.
        $this->assertSame('2026-06-10 09:30:00', $order->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('01712345678', $order->ship_phone);

        $this->assertCount(1, $order->items);
        $this->assertSame(['Linen tea set', 2, 650, null], [$order->items[0]->product_name, $order->items[0]->quantity, $order->items[0]->price, $order->items[0]->product_id]);
        $this->assertNull($order->items[0]->image_path);

        // An order whose lines do not add up still ends on the old total, to the taka, with its discount.
        $odd = Order::query()->where('legacy_order_id', '30003')->firstOrFail();
        $this->assertSame(1180, $odd->total);
        $this->assertSame(40, $odd->discount_amount);
        $this->assertSame(1100, $odd->subtotal);
        $this->assertSame(1180, $odd->subtotal + $odd->delivery_fee - $odd->discount_amount);

        $this->assertSame(1370 + 570 + 1180, (int) Order::query()->sum('total'));
    }

    public function test_a_line_whose_product_is_gone_is_a_plain_named_line_with_its_options(): void
    {
        LegacyOrderImporter::import($this->plan());

        $line = Order::query()->where('legacy_order_id', '30002')->firstOrFail()->items()->firstOrFail();

        $this->assertNull($line->product_id);
        $this->assertSame('Brass tray', $line->product_name);
        $this->assertSame(['Size', 'Large'], [$line->variant_name, $line->variant_value]);
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $first = LegacyOrderImporter::import($this->plan());
        $customers = Customer::query()->count();
        $items = OrderItem::query()->count();

        $again = $this->plan();
        $second = LegacyOrderImporter::import($again);

        $this->assertSame(3, $first['orders']);
        $this->assertSame(3, $again['already_imported']);
        $this->assertSame([], $again['orders']);
        $this->assertSame(0, $second['orders']);
        $this->assertSame(3, Order::query()->count());
        $this->assertSame($items, OrderItem::query()->count());
        $this->assertSame($customers, Customer::query()->count());

    }

    public function test_a_second_import_of_the_same_plan_object_is_still_safe(): void
    {
        $plan = $this->plan();

        LegacyOrderImporter::import($plan);
        $second = LegacyOrderImporter::import($plan);

        $this->assertSame(0, $second['orders']);
        $this->assertSame(3, $second['skipped_existing']);
        $this->assertSame(3, Order::query()->count());
    }

    public function test_the_unique_old_number_is_enforced_by_the_database(): void
    {
        LegacyOrderImporter::import($this->plan());

        $this->expectException(QueryException::class);
        Order::query()->where('legacy_order_id', '30001')->firstOrFail()->replicate()->forceFill(['legacy_order_id' => '30001'])->save();
    }

    public function test_customers_are_merged_by_normalised_phone_with_the_shops_own_and_with_each_other(): void
    {
        $existing = Customer::create(['name' => 'Already Here', 'phone' => '01812-345678', 'email' => 'here@example.com', 'password' => 'x']);

        $result = LegacyOrderImporter::import($this->plan());

        // Old customers 1 and 3 are one phone (one is created, the other joins it); old customer 2 is the shop's existing customer.
        $this->assertSame(1, $result['customers_created']);
        $this->assertSame(2, $result['customers_matched']);
        $this->assertSame(2, Customer::query()->count());
        $this->assertSame($existing->id, Order::query()->where('legacy_order_id', '30002')->value('customer_id'));
        $this->assertSame(
            Order::query()->where('legacy_order_id', '30001')->value('customer_id'),
            Order::query()->where('legacy_order_id', '30003')->value('customer_id'),
        );
        $this->assertSame('Already Here', $existing->fresh()->name, 'the existing customer is not rewritten');
        $this->assertNull(Customer::query()->where('phone', '01712345678')->value('email'));
    }

    // ---- nothing is sent, booked, reported or counted

    public function test_importing_and_changing_an_imported_order_sends_and_dispatches_nothing_and_leaves_stock_alone(): void
    {
        Mail::fake();
        Http::fake();
        Queue::fake();
        Bus::fake();
        $this->steadfast(['auto_book' => true]);

        $product = Product::create([
            'category_id' => Category::create(['name' => 'Trays', 'slug' => 'trays'])->id,
            'name' => 'Brass tray', 'slug' => 'brass-tray', 'price' => 500, 'stock_quantity' => 10, 'sku' => 'TRAY', 'is_active' => true,
        ]);
        $this->shop();

        $tables = $this->export();
        $tables['customers'][0]['phone'] = '01712345678';

        LegacyOrderImporter::import($this->plan($tables, LegacyOrderImporter::parseStatusMap('3=processing,5=delivered,6=cancelled')));

        // A status change made later on an imported order (shipped, then delivered, then processing: the one that books a courier).
        foreach (Order::query()->get() as $order) {
            foreach (['shipped', 'delivered', 'processing', 'cancelled'] as $status) {
                $order->update(['status' => $status]);
            }
        }

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        $this->afterResponse();
        Http::assertNothingSent();
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(0, Order::query()->whereNotNull('courier_booked_at')->count());
        $this->assertSame(0, Order::query()->whereNotNull('conversion_sent_at')->count());
        $this->assertSame([], Order::query()->whereNotNull('emails_sent')->pluck('emails_sent')->all());
    }

    public function test_an_imported_order_is_never_booked_with_a_courier(): void
    {
        $this->steadfast();
        LegacyOrderImporter::import($this->plan());
        $order = Order::query()->where('legacy_order_id', '30001')->firstOrFail();

        $preview = CourierBooking::preview($order, 'steadfast');

        $this->assertNotEmpty(array_filter($preview['blocks'], fn (string $b) => str_contains($b, 'imported from the old site')));
        $this->assertFalse(CourierBooking::book($order, 'steadfast')['ok']);
    }

    public function test_review_requests_skip_imported_orders_even_when_they_are_delivered_and_old(): void
    {
        Mail::fake();
        ReviewRequestSetting::query()->create(['enabled' => true, 'email_enabled' => true, 'enabled_at' => now()->subYear(), 'days_after_delivery' => 1]);
        $tables = $this->export();
        $tables['customers'][0]['email'] = 'old@example.com';
        LegacyOrderImporter::import($this->plan($tables));
        Customer::query()->orderBy('id')->first()->update(['email' => 'old@example.com']);
        Order::query()->update(['ship_email' => 'old@example.com']);

        $this->assertSame(0, ReviewRequests::due(ReviewRequestSetting::current())->count());
        $result = ReviewRequests::run();

        $this->assertSame(['sent' => 0, 'reminded' => 0, 'skipped' => 0], $result);
        Mail::assertNothingSent();
    }

    // ---- the command

    public function test_the_dry_run_changes_nothing_and_prints_counts_only(): void
    {
        $tables = $this->export();
        $tables['customers'][0]['name'] = 'Zubaida Unique-Name';
        $folder = $this->folder($tables);

        $this->artisan('orders:import-legacy', ['file' => $folder, '--dry-run' => true])
            ->expectsOutputToContain('to import: 3')
            ->expectsOutputToContain('customer not in the export')
            ->doesntExpectOutputToContain('Zubaida')
            ->doesntExpectOutputToContain('01712345678')
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_without_force_nothing_is_written(): void
    {
        $this->artisan('orders:import-legacy', ['file' => $this->folder($this->export())])->assertSuccessful();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_real_run_needs_the_status_map_or_an_explicit_acceptance_of_the_guess(): void
    {
        $folder = $this->folder($this->export());

        $this->artisan('orders:import-legacy', ['file' => $folder, '--force' => true])->assertFailed();
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_real_run_copies_the_database_first_then_imports_once(): void
    {
        $folder = $this->folder($this->export());
        $fake = storage_path('framework/testing/fake-'.uniqid().'.sqlite');
        File::put($fake, 'sqlite stand-in');
        $this->cleanup[] = $fake;
        config(['database.connections.sqlite.database' => $fake]);
        $before = glob(storage_path('backups/before-legacy-import-*.sqlite')) ?: [];

        $this->artisan('orders:import-legacy', ['file' => $folder, '--force' => true, '--status-map' => '5=delivered,6=cancelled,3=shipped'])
            ->expectsOutputToContain('Database copied to:')
            ->expectsOutputToContain('Imported 3 order(s)')
            ->assertSuccessful();

        $copies = array_diff(glob(storage_path('backups/before-legacy-import-*.sqlite')) ?: [], $before);
        $this->cleanup = array_merge($this->cleanup, $copies);
        $this->assertCount(1, $copies);
        $this->assertSame('sqlite stand-in', file_get_contents(array_values($copies)[0]));
        $this->assertSame(3, Order::query()->count());

        // Again: nothing new, and a second copy is not even needed because there is nothing to import.
        $this->artisan('orders:import-legacy', ['file' => $folder, '--force' => true, '--status-map' => '5=delivered,6=cancelled,3=shipped'])->assertSuccessful();
        $this->assertSame(3, Order::query()->count());
    }

    public function test_a_real_run_on_a_database_that_cannot_be_copied_imports_nothing(): void
    {
        $folder = $this->folder($this->export());

        $this->artisan('orders:import-legacy', ['file' => $folder, '--force' => true, '--accept-default-status-map' => true])->assertFailed();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_bad_status_map_is_refused(): void
    {
        $this->artisan('orders:import-legacy', ['file' => $this->folder($this->export()), '--dry-run' => true, '--status-map' => '5=lost'])->assertFailed();
    }

    // ---- the admin, the invoice and Track

    public function test_the_admin_list_marks_and_filters_imported_orders(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $own = $this->codOrder();
        LegacyOrderImporter::import($this->plan());
        $imported = Order::query()->where('legacy_order_id', '30001')->firstOrFail();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$own, $imported])
            ->assertSee('Legacy')
            ->filterTable('origin', 'legacy')->assertCanSeeTableRecords([$imported])->assertCanNotSeeTableRecords([$own])
            ->filterTable('origin', 'shop')->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$imported])
            ->resetTableFilters()
            ->searchTable('30001')->assertCanSeeTableRecords([$imported])->assertCanNotSeeTableRecords([$own]);
    }

    public function test_the_invoice_shows_the_old_number_and_renders_without_an_address(): void
    {
        $this->shop();
        LegacyOrderImporter::import($this->plan());
        $order = Order::query()->where('legacy_order_id', '30001')->with(['customer', 'items'])->firstOrFail();

        $this->assertNull($order->recipient()['address']);
        $this->assertSame('', $order->recipientAddressLine());
        $this->assertStringContainsString('Order #30001', view('pdf.invoice', ['order' => $order, 'siteSetting' => null])->render());
        $this->assertStringStartsWith('%PDF', InvoicePdf::make($order)->output());
    }

    public function test_track_finds_an_imported_order_by_the_old_number_and_phone(): void
    {
        LegacyOrderImporter::import($this->plan());

        $this->getJson('/api/orders/lookup?order_id=30001&phone=%2B8801712345678')
            ->assertOk()
            ->assertJsonPath('data.order_number', '30001')
            ->assertJsonPath('data.is_legacy', true)
            ->assertJsonPath('data.total', 1370)
            ->assertJsonPath('data.items.0.product_name', 'Linen tea set');

        $this->getJson('/api/orders/lookup?order_id=30001&phone=01999999999')->assertNotFound();
        $this->getJson('/api/orders/lookup?order_id=30009&phone=01712345678')->assertNotFound();
    }

    public function test_when_an_old_number_equals_a_new_order_id_the_phone_decides(): void
    {
        $mine = $this->codOrder(); // phone 01712345678
        $other = Customer::create(['name' => 'Else', 'phone' => '01812345678', 'password' => 'x']);
        $tables = ['customers' => [$this->customerRow(1, '01812345678')], 'orders' => [$this->orderRow(10, (string) $mine->id, 1, 570, 70, 5)], 'order_details' => [$this->lineRow(1, 10, 'Brass tray', 500)]];
        LegacyOrderImporter::import($this->plan($tables));

        $this->getJson('/api/orders/lookup?order_id='.$mine->id.'&phone=01712345678')->assertOk()->assertJsonPath('data.is_legacy', false);
        $this->getJson('/api/orders/lookup?order_id='.$mine->id.'&phone=01812345678')->assertOk()->assertJsonPath('data.is_legacy', true);
        $this->assertNotNull($other);
    }
}
