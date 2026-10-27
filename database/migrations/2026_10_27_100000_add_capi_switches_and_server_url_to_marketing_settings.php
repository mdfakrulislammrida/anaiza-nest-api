<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Whether this server sends the Purchase to Meta / TikTok. On by default: nothing changes until someone switches one off.
            $table->boolean('meta_capi_enabled')->default(true)->after('meta_capi_access_token');
            $table->boolean('tiktok_events_enabled')->default(true)->after('tiktok_events_api_access_token');
            // The address of the server-side Google Tag Manager container (Stape). Not a secret; the browser tags read it from a GTM constant.
            $table->string('server_container_url', 255)->nullable()->after('tiktok_pixel_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn(['meta_capi_enabled', 'tiktok_events_enabled', 'server_container_url']);
        });
    }
};
