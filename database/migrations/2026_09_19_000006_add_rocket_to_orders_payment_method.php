<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Converts payment_method from a DB-level enum to a plain string.
     * The reference checkout adds "Rocket" as a payment option, and
     * enum columns are painful to alter portably across SQLite/MySQL —
     * the actual constraint now lives in StoreOrderRequest's validation
     * instead, so adding a future payment method never needs a migration.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_method', ['cod', 'bkash', 'nagad', 'card'])->change();
        });
    }
};
