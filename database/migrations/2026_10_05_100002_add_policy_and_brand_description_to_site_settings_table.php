<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            // Column defaults are exactly the rules that were hardcoded before
            // (free over 2,000, 80 inside Dhaka, 130 outside, 1-3 / 3-5 days,
            // 7-day returns), so nothing changes until an admin edits them.
            $table->unsignedInteger('free_delivery_threshold')->default(2000);
            $table->unsignedInteger('delivery_fee_dhaka')->default(80);
            $table->unsignedInteger('delivery_fee_outside_dhaka')->default(130);
            $table->unsignedSmallInteger('delivery_days_dhaka_min')->default(1);
            $table->unsignedSmallInteger('delivery_days_dhaka_max')->default(3);
            $table->unsignedSmallInteger('delivery_days_outside_min')->default(3);
            $table->unsignedSmallInteger('delivery_days_outside_max')->default(5);
            $table->unsignedSmallInteger('return_window_days')->default(7);

            // One master paragraph about the brand, reused across schema, llms.txt and meta.
            $table->text('brand_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn([
                'free_delivery_threshold', 'delivery_fee_dhaka', 'delivery_fee_outside_dhaka',
                'delivery_days_dhaka_min', 'delivery_days_dhaka_max',
                'delivery_days_outside_min', 'delivery_days_outside_max',
                'return_window_days', 'brand_description',
            ]);
        });
    }
};
