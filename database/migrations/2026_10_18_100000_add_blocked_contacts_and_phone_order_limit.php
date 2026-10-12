<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phone numbers, emails and address fragments the shop will not take online orders from (fake and repeat COD orders).
        Schema::create('blocked_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('value');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'value']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            // Most orders one phone number may place in 24 hours; 0 means no limit.
            $table->unsignedSmallInteger('max_orders_per_phone_per_day')->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('max_orders_per_phone_per_day');
        });

        Schema::dropIfExists('blocked_contacts');
    }
};
