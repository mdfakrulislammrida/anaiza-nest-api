<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Where an order came from: null for every order made on this shop, 'legacy_import' for one brought over from the old site.
            $table->string('source', 30)->nullable()->after('status')->index();
            // The old site's order number (its invoice number, the one customers were given). Unique, so importing twice never duplicates.
            $table->string('legacy_order_id', 40)->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['legacy_order_id']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'legacy_order_id']);
        });
    }
};
