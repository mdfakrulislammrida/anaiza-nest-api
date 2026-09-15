<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    /**
     * Every permission key a staff role can be granted, keyed by the
     * Filament resource/page it unlocks.
     */
    public const PERMISSIONS = [
        'products.manage' => 'Manage Products',
        'categories.manage' => 'Manage Categories',
        'orders.manage' => 'Manage Orders',
        'customers.manage' => 'Manage Customers',
        'coupons.manage' => 'Manage Coupons',
        'shipping_zones.manage' => 'Manage Shipping Zones',
        'pages.manage' => 'Manage Pages',
        'banners.manage' => 'Manage Banners',
        'faqs.manage' => 'Manage FAQs',
        'articles.manage' => 'Manage Blog Articles',
        'newsletter.view' => 'View Newsletter Subscribers',
        'site_settings.manage' => 'Manage Site Settings',
        'payment_settings.manage' => 'Manage Payment Settings',
        'marketing_settings.manage' => 'Manage Marketing Settings',
        'staff.manage' => 'Manage Staff & Roles',
    ];

    protected $fillable = [
        'name',
        'slug',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissions ?? [], true);
    }
}
