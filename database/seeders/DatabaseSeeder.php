<?php

namespace Database\Seeders;

use App\Models\ShippingZone;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // A sample admin with the factory's well-known password exists only while developing. It must never be created on a
        // live server, where it would be an unrestricted account anyone could guess.
        if (app()->environment(['local', 'testing'])) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // Checkout requires at least one shipping zone to exist.
        ShippingZone::firstOrCreate(
            ['name' => 'Inside Dhaka'],
            ['delivery_fee' => 60, 'estimated_days' => '1-2 days'],
        );

        ShippingZone::firstOrCreate(
            ['name' => 'Outside Dhaka'],
            ['delivery_fee' => 120, 'estimated_days' => '3-5 days'],
        );
    }
}
