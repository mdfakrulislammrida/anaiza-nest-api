<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > Courier: "Update order status from courier automatically". On by default, which is how the shop already behaves.
 * Off, a courier's reports are still kept and shown on the order, but they change nothing on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_settings', function (Blueprint $table) {
            $table->boolean('status_sync_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('courier_settings', function (Blueprint $table) {
            $table->dropColumn('status_sync_enabled');
        });
    }
};
