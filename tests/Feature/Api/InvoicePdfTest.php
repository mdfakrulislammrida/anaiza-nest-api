<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\InvoicePdf;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** Two of a ৳600 piece with a picture (under the free-delivery line): a line total of ৳1,200, delivery ৳80 inside Dhaka, a total of ৳1,280. */
    private function order(): Order
    {
        $picture = imagecreatetruecolor(300, 400);
        imagefill($picture, 0, 0, imagecolorallocate($picture, 40, 90, 140));
        ob_start();
        imagepng($picture);
        Storage::disk('public')->put('products/vase.png', (string) ob_get_clean());

        $product = Product::create([
            'category_id' => Category::create(['name' => 'Vases', 'slug' => 'vases'])->id,
            'name' => 'Blue vase', 'slug' => 'vase', 'price' => 600, 'stock_quantity' => 20, 'sku' => 'VASE', 'is_active' => true,
        ]);
        ProductImage::create(['product_id' => $product->id, 'url' => 'products/vase.png', 'sort_order' => 0]);

        $id = $this->postJson('/api/orders', [
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01712345678', 'customer_address' => 'House 12, Road 5',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        return Order::with(['customer', 'items'])->findOrFail($id);
    }

    /** The text of a PDF as a reader sees it (in UTF-8, or the taka sign is lost on the way out), or null when pdftotext is not on this machine. */
    private function textOf(string $pdf): ?string
    {
        $found = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null'));

        if ($found === '') {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
        file_put_contents($file, $pdf);
        $text = (string) shell_exec('pdftotext -enc UTF-8 -layout '.escapeshellarg($file).' - 2>&1');
        @unlink($file);

        return $text;
    }

    public function test_every_price_on_the_invoice_has_its_taka_sign(): void
    {
        $pdf = InvoicePdf::make($this->order())->output();

        // (A PDF's bytes are never given to an assertion's message: a failure would print all of them.)
        $this->assertTrue(str_starts_with($pdf, '%PDF'));
        $this->assertSame(1, preg_match('/BaseFont\s*\/[A-Z]{6}\+HindSiliguri/', $pdf), 'The invoice is set in Hind Siliguri.');

        $text = $this->textOf($pdf);

        if ($text === null) {
            $this->markTestIncomplete('pdftotext is not installed here, so the text of the PDF was not read (the font checks above passed).');
        }

        // Unit price, line total, subtotal, delivery fee and total.
        foreach (['৳600', '৳1,200', '৳80', '৳1,280'] as $price) {
            $this->assertStringContainsString($price, $text, "The invoice shows {$price}.");
        }

        $this->assertSame(1, substr_count($text, '৳600'), 'The unit price.');
        $this->assertSame(2, substr_count($text, '৳1,200'), 'The line total and the subtotal.');
        $this->assertSame(1, substr_count($text, '৳80'), 'The delivery fee.');
        $this->assertSame(1, substr_count($text, '৳1,280'), 'The total.');

        // Nothing is printed as "?", and the sign is not replaced by a word.
        $this->assertStringNotContainsString('?', $text);
        $this->assertStringNotContainsString('Tk', $text);
        $this->assertStringNotContainsString('BDT', $text);
        $this->assertStringContainsString('Blue vase', $text);
        $this->assertStringContainsString('Nusrat Jahan', $text);
    }

    public function test_the_product_thumbnail_is_still_in_the_pdf(): void
    {
        $pdf = InvoicePdf::make($this->order())->output();

        $this->assertTrue(str_contains($pdf, '/DCTDecode'), 'The 128 px JPEG of the line is embedded.');
    }

    public function test_a_server_with_no_font_folder_installs_the_font_by_itself_and_only_once(): void
    {
        // A font folder of its own and a PDF maker that only installs (it draws nothing), so no file is held open.
        $dir = storage_path('framework/testing/invoice-fonts-'.uniqid());
        $dompdf = new Dompdf(new Options(['font_dir' => $dir, 'font_cache' => $dir, 'chroot' => base_path()]));

        try {
            $this->assertDirectoryDoesNotExist($dir);
            $this->assertTrue(InvoicePdf::ensureFonts($dompdf));

            $names = collect(File::files($dir))->map->getFilename();
            $this->assertTrue($names->contains(fn (string $name): bool => str_starts_with($name, 'hind_siliguri_normal_') && str_ends_with($name, '.ttf')));
            $this->assertTrue($names->contains(fn (string $name): bool => str_starts_with($name, 'hind_siliguri_bold_') && str_ends_with($name, '.ttf')));
            $this->assertTrue(InvoicePdf::installed($dompdf, 'normal'));
            $this->assertTrue(InvoicePdf::installed($dompdf, 'bold'));

            // The cache holds plain file names, not the folder of this machine, so a moved or copied site still finds the font.
            $cache = json_decode((string) file_get_contents($dir.'/installed-fonts.json'), true);
            foreach ($cache['hind siliguri'] as $file) {
                $this->assertSame(basename($file), $file);
            }

            // The next invoice finds it installed and copies nothing.
            $before = collect(File::files($dir))->mapWithKeys(fn ($file): array => [$file->getFilename() => $file->getMTime().'/'.$file->getSize()]);
            sleep(1);
            $this->assertTrue(InvoicePdf::ensureFonts(new Dompdf(new Options(['font_dir' => $dir, 'font_cache' => $dir, 'chroot' => base_path()]))));
            $after = collect(File::files($dir))->mapWithKeys(fn ($file): array => [$file->getFilename() => $file->getMTime().'/'.$file->getSize()]);

            $this->assertEquals($before, $after);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_install_command_installs_and_reports(): void
    {
        $this->artisan('invoice:install-fonts')
            ->expectsOutputToContain('normal  installed')
            ->expectsOutputToContain('bold    installed')
            ->assertSuccessful();

        // Safe to run again.
        $this->artisan('invoice:install-fonts')->assertSuccessful();
    }

    public function test_the_fonts_and_their_licence_are_in_the_repository(): void
    {
        foreach (['HindSiliguri-Regular.ttf', 'HindSiliguri-Bold.ttf', 'HindSiliguri-OFL.txt'] as $file) {
            $this->assertFileExists(resource_path('fonts/'.$file));
        }

        $this->assertStringContainsString('SIL OPEN FONT LICENSE', strtoupper((string) file_get_contents(resource_path('fonts/HindSiliguri-OFL.txt'))));
    }
}
