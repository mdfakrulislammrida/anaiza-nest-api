<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Real starter content (categories, products, CMS pages) so the storefront's
 * static export build has something to enumerate via generateStaticParams.
 * Safe to re-run: everything is keyed by name/slug via firstOrCreate /
 * updateOrCreate, so running this twice updates rather than duplicates.
 *
 * No product images are seeded here — real photos get uploaded through the
 * Filament admin afterward.
 */
class AnaizaNestRealDataSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->seedCategories();
        $this->seedProducts($categories);
        $this->seedPages();
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $definitions = [
            'Tea Sets' => 'Ceramic and porcelain tea sets, pots, and brewing accessories.',
            'Gift Boxes & Sets' => 'Curated, presentation-boxed gift sets for any occasion.',
            'Tableware & Decor' => 'Coffee cups, coasters, and decorative tableware.',
        ];

        $categories = [];

        foreach ($definitions as $name => $description) {
            // Matched by name only, so a category already created by hand in
            // the admin (e.g. "Tea Sets" with its own custom slug) is reused
            // as-is rather than duplicated or overwritten.
            $categories[$name] = Category::firstOrCreate(
                ['name' => $name],
                ['slug' => Str::slug($name), 'description' => $description],
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedProducts(array $categories): void
    {
        $products = [
            [
                'name' => 'Portable Ceramic Gaiwan & Cups With Carry Case',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-001',
                'price' => 3450,
                'sale_price' => 2770,
                'featured' => true,
                'description' => 'Travel-ready gaiwan brewing set with two matching cups, packed in a compact carry case so you can brew properly wherever you are.',
            ],
            [
                'name' => 'Lotus Ceramic Tea Caddy & Gift Box Set',
                'category' => 'Gift Boxes & Sets',
                'sku' => 'ANZ-002',
                'price' => 3600,
                'sale_price' => 2950,
                'description' => 'A lotus-motif ceramic tea caddy presented in a matching gift box, ideal for keeping loose-leaf tea fresh and giving as a considered present.',
            ],
            [
                'name' => 'Luxury Crystal Wine Decanter & Glass Gift Set',
                'category' => 'Gift Boxes & Sets',
                'sku' => 'ANZ-003',
                'price' => 3400,
                'sale_price' => 2930,
                'featured' => true,
                'description' => 'A faceted crystal decanter paired with matching glasses in a presentation box, built for entertaining and easy gifting.',
            ],
            [
                'name' => 'Luxury Morris Ceramic Coffee Cup & Saucer Set',
                'category' => 'Tableware & Decor',
                'sku' => 'ANZ-004',
                'price' => 2650,
                'sale_price' => 2250,
                'featured' => true,
                'description' => 'Fine ceramic coffee cups and saucers finished in a classic Morris-inspired pattern, sized for a proper espresso or short coffee.',
            ],
            [
                'name' => 'Stunning Premium Tableware / Creative 3D Wall Decor',
                'category' => 'Tableware & Decor',
                'sku' => 'ANZ-005',
                'price' => 1700,
                'sale_price' => 1350,
                'description' => 'A sculptural ceramic piece that doubles as tableware and wall art, adding texture to a shelf or dining setting.',
            ],
            [
                'name' => 'Kung Fu Tea Set for Home/Office/Business',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-006',
                'price' => 8900,
                'sale_price' => 7600,
                'featured' => true,
                'description' => 'A complete Kung Fu tea ceremony set with pot, cups, and tray, suited to a home tea corner or an office reception.',
            ],
            [
                'name' => 'Vintage Blue & White Ceramic Tea Storage Jar (Single)',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-007',
                'price' => 1450,
                'sale_price' => null,
                'featured' => true,
                'description' => 'A single hand-painted blue-and-white ceramic jar for keeping loose-leaf tea airtight and fresh, with a vintage porcelain finish.',
            ],
            [
                'name' => 'Purple Sand Pot Kung Fu Tea Set Household Gift Box Set',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-008',
                'price' => 11590,
                'sale_price' => null,
                'description' => 'A traditional purple clay (Yixing) teapot with a full Kung Fu tea set, boxed for gifting or everyday home brewing.',
            ],
            [
                'name' => 'Premium Round Tea Coaster Set With Holder',
                'category' => 'Tableware & Decor',
                'sku' => 'ANZ-009',
                'price' => 910,
                'sale_price' => null,
                'description' => 'A set of round ceramic tea coasters with a matching holder, protecting your table while keeping the set tidy between uses.',
            ],
            [
                'name' => 'Ruyao Tea Set Gift Box',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-010',
                'price' => 5500,
                'sale_price' => null,
                'description' => 'A Ruyao-glaze tea set with its signature crackled finish, presented in a gift box for tea lovers and collectors alike.',
            ],
            [
                'name' => 'Handcrafted Blue Tenmoku Ceramic Tea Set',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-011',
                'price' => 32500,
                'sale_price' => null,
                'description' => 'A handcrafted tea set glazed in a striking blue Tenmoku finish, each piece carrying its own natural variation.',
            ],
            [
                'name' => 'Five Blessings Kung Fu Tea Set Gift Box',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-012',
                'price' => 6200,
                'sale_price' => null,
                'description' => 'A Kung Fu tea set decorated with the Five Blessings motif, a traditional symbol of good fortune, boxed for gifting.',
            ],
            [
                'name' => 'Stunning 3D Tableware / Creative Wall Decor (Small)',
                'category' => 'Tableware & Decor',
                'sku' => 'ANZ-013',
                'price' => 1100,
                'sale_price' => null,
                'description' => 'A smaller sculptural tableware piece with a 3D relief design, equally at home on a table or mounted as wall decor.',
            ],
            [
                'name' => 'Light Luxury Electric Ceramic Stove Glass Teapot Tea Set',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-014',
                'price' => 12300,
                'sale_price' => null,
                'description' => 'A glass teapot tea set with its own electric ceramic warming stove, keeping tea at temperature through a full session.',
            ],
            [
                'name' => 'Elegant Ru Kiln Tea Collection in Premium Wooden Case',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-015',
                'price' => 6500,
                'sale_price' => null,
                'description' => 'A Ru Kiln-inspired tea collection housed in a premium wooden case, prized for its soft glaze and understated elegance.',
            ],
            [
                'name' => 'Guochao City Cultural and Creative Tea Set Gift',
                'category' => 'Tea Sets',
                'sku' => 'ANZ-016',
                'price' => 4950,
                'sale_price' => null,
                'description' => 'A tea set inspired by contemporary Guochao design, blending traditional tea culture with a modern, giftable aesthetic.',
            ],
            [
                'name' => 'Business Executive Gift Set',
                'category' => 'Gift Boxes & Sets',
                'sku' => 'ANZ-017',
                'price' => 2800,
                'sale_price' => null,
                'description' => 'A polished gift set suited to corporate and executive gifting, presented ready to hand over for any professional occasion.',
            ],
            [
                'name' => 'Luxury Style Coffee Cup & Saucer Set',
                'category' => 'Tableware & Decor',
                'sku' => 'ANZ-018',
                'price' => 4800,
                'sale_price' => null,
                'description' => 'An elegant coffee cup and saucer set finished in a refined luxury style, ideal for everyday coffee or entertaining guests.',
            ],
        ];

        foreach ($products as $data) {
            Product::updateOrCreate(
                ['slug' => Str::slug($data['name'])],
                [
                    'category_id' => $categories[$data['category']]->id,
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'price' => $data['price'],
                    'sale_price' => $data['sale_price'] ?? null,
                    'stock_quantity' => 20,
                    'sku' => $data['sku'],
                    'is_featured' => $data['featured'] ?? false,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedPages(): void
    {
        $pages = [
            [
                'slug' => 'shipping',
                'title' => 'Shipping Policy',
                'content' => "<p>We deliver across Bangladesh through trusted courier partners. Orders inside Dhaka typically arrive within 1-3 business days; orders outside Dhaka arrive within 3-5 business days.</p><p>Delivery is free inside Dhaka for orders over &#2547;2,000. Below that, a flat delivery fee applies based on your area.</p><p>You'll receive a call or SMS from our delivery partner before your order arrives. You can track any order from the Track Order page using your order ID and phone number.</p>",
            ],
            [
                'slug' => 'returns',
                'title' => 'Returns & Refunds',
                'content' => "<p>If an item arrives damaged, defective, or different from what you ordered, let us know within 7 days of delivery and we'll arrange a replacement or refund.</p><p>Refunds for prepaid orders (bKash, Nagad, Rocket) are processed back to the original payment method. Cash on Delivery refunds are issued via mobile wallet.</p><p>To start a return, message us on WhatsApp with your order ID and a photo of the item, or use the contact form.</p>",
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms & Conditions',
                'content' => "<p>By placing an order with Anaiza Nest, you agree to these terms. Product prices, descriptions, and availability are subject to change without notice.</p><p>Orders are confirmed once payment is received or, for Cash on Delivery, once the order is placed. We reserve the right to cancel any order suspected of fraud or error.</p><p>For questions about these terms, please get in touch through the Contact page.</p>",
            ],
        ];

        foreach ($pages as $data) {
            // updateOrCreate (not firstOrCreate) so the exact required
            // title/content always lands, even over stale placeholder pages
            // left by earlier scaffold seeding.
            Page::updateOrCreate(
                ['slug' => $data['slug']],
                ['title' => $data['title'], 'content' => $data['content']],
            );
        }
    }
}
