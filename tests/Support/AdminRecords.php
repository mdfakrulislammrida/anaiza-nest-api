<?php

namespace Tests\Support;

use App\Models\Article;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\CorporateEnquiry;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Faq;
use App\Models\HomepageSection;
use App\Models\MediaItem;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductLabel;
use App\Models\ProductReview;
use App\Models\ProductTag;
use App\Models\Role;
use App\Models\ShippingZone;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal valid records for the admin tests, one builder per Filament resource model.
 */
class AdminRecords
{
    public static function category(array $overrides = []): Category
    {
        return Category::create(array_merge(['name' => 'Tea Sets', 'slug' => 'tea-sets'], $overrides));
    }

    public static function product(array $overrides = []): Product
    {
        $categoryId = $overrides['category_id'] ?? self::category(['slug' => 'cat-'.uniqid()])->id;

        return Product::create(array_merge([
            'category_id' => $categoryId,
            'name' => 'Burgundy Tea Set',
            'slug' => 'burgundy-tea-set-'.uniqid(),
            'price' => 1500,
            'stock_quantity' => 5,
            'sku' => 'SKU-'.uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    private static function emailTemplate(): EmailTemplate
    {
        EmailTemplate::ensureAll();

        return EmailTemplate::query()->where('key', 'order_shipped')->firstOrFail();
    }

    private static function productReview(): ProductReview
    {
        $product = self::for(Product::class);

        return ProductReview::create(['product_id' => $product->id, 'name' => 'Rafi', 'rating' => 5, 'body' => 'Lovely set, packed beautifully.']);
    }

    public static function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Fakrul Islam',
            'email' => 'customer-'.uniqid().'@example.com',
            'password' => 'secret-password',
            'phone' => '01710000000',
            'address' => 'House 12, Road 5',
            'city' => 'Dhaka',
            'postal_code' => '1212',
        ], $overrides));
    }

    public static function shippingZone(array $overrides = []): ShippingZone
    {
        return ShippingZone::create(array_merge(['name' => 'Inside Dhaka', 'delivery_fee' => 80, 'estimated_days' => '1-3 days'], $overrides));
    }

    public static function order(array $overrides = []): Order
    {
        $customerId = $overrides['customer_id'] ?? self::customer()->id;

        $order = Order::create(array_merge([
            'customer_id' => $customerId,
            'status' => 'pending',
            'subtotal' => 1500,
            'delivery_fee' => 80,
            'total' => 1580,
            'payment_method' => 'cod',
        ], $overrides));

        $order->items()->create([
            'product_name' => 'Burgundy Tea Set',
            'quantity' => 1,
            'price' => 1500,
        ]);

        return $order;
    }

    public static function role(array $overrides = []): Role
    {
        return Role::create(array_merge([
            'name' => 'Editor',
            'slug' => 'editor-'.uniqid(),
            'permissions' => ['products.manage', 'orders.manage'],
        ], $overrides));
    }

    /**
     * One record for the given resource model, with the relations the admin forms read.
     */
    public static function for(string $model): Model
    {
        return match ($model) {
            Article::class => Article::create(['title' => 'Care guide', 'slug' => 'care-guide', 'content' => '<h2>Care</h2><p>Hand wash.</p>', 'author_name' => 'A. Writer', 'published_at' => now()->subDay()]),
            Banner::class => Banner::create(['image_url' => 'banners/sale.jpg', 'title' => 'Sale']),
            Brand::class => Brand::create(['name' => 'Anaiza', 'slug' => 'anaiza']),
            Category::class => self::category(),
            ContactSubmission::class => ContactSubmission::create(['name' => 'Fakrul', 'phone' => '01710000000', 'message' => 'Hello']),
            CorporateEnquiry::class => CorporateEnquiry::create(['name' => 'Rafi', 'company' => 'Northwind Ltd', 'phone' => '01710000001', 'email' => 'rafi@example.com', 'quantity' => 40]),
            Coupon::class => Coupon::create(['code' => 'WELCOME10', 'discount_type' => 'percent', 'amount' => 10]),
            Customer::class => self::customer(),
            EmailTemplate::class => self::emailTemplate(),
            ProductReview::class => self::productReview(),
            Faq::class => Faq::create(['question' => 'How long is delivery?', 'answer' => '1-3 days.']),
            HomepageSection::class => HomepageSection::create(['type' => array_key_first(HomepageSection::TYPES), 'position' => 1, 'is_enabled' => true]),
            MediaItem::class => MediaItem::create(['disk' => 'public', 'path' => 'media/example.jpg', 'original_name' => 'example.jpg', 'mime_type' => 'image/jpeg', 'size' => 1024]),
            NewsletterSubscriber::class => NewsletterSubscriber::create(['email' => 'subscriber@example.com']),
            Order::class => self::order(),
            Page::class => Page::create(['title' => 'Shipping', 'slug' => 'shipping', 'content' => '<p>Text</p>']),
            Product::class => self::product(),
            ProductAttribute::class => ProductAttribute::create(['name' => 'Size', 'slug' => 'size']),
            ProductLabel::class => ProductLabel::create(['name' => 'Hot', 'badge_color' => '#661E29']),
            ProductTag::class => ProductTag::create(['name' => 'Gift', 'slug' => 'gift']),
            Role::class => self::role(),
            ShippingZone::class => self::shippingZone(),
            Testimonial::class => Testimonial::create(['customer_name' => 'Nusrat', 'quote' => 'Lovely set.']),
            User::class => User::factory()->create(['role_id' => self::role()->id]),
        };
    }
}
