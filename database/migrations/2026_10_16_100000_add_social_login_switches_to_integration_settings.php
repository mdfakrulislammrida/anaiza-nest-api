<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            // Off until the admin switches them on, and only effective while the provider's credentials are filled in.
            $table->boolean('google_login_enabled')->default(false);
            $table->boolean('facebook_login_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            $table->dropColumn(['google_login_enabled', 'facebook_login_enabled']);
        });
    }
};
