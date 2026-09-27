<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->text('meta_capi_access_token')->nullable()->after('meta_pixel_id');
            $table->text('tiktok_events_api_access_token')->nullable()->after('tiktok_pixel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn(['meta_capi_access_token', 'tiktok_events_api_access_token']);
        });
    }
};
