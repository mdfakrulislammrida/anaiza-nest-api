<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the server itself has noticed: that the scheduler ran, that a backup finished or failed, that an email could
        // not be sent. Read by Settings > System health.
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('level', 10)->default('info');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            // Who the shop's own alerts go to (a new order, stock running low, a failed backup). Blank falls back to the
            // payment notification address and then the contact email.
            $table->string('alert_email')->nullable();
            $table->boolean('alert_new_orders')->default(true);
            $table->boolean('alert_low_stock')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['alert_email', 'alert_new_orders', 'alert_low_stock']);
        });

        Schema::dropIfExists('system_events');
    }
};
