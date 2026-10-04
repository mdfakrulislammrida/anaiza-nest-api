<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Label/value rows for the specifications table (max 20, enforced in the admin form).
            $table->json('specifications')->nullable()->after('short_description');
            // 8-14 digit GTIN and the manufacturer part number -- both optional.
            $table->string('gtin', 14)->nullable()->after('sku');
            $table->string('mpn', 100)->nullable()->after('gtin');
            // 50-100 word block shown directly under the H1.
            $table->string('summary', 600)->nullable()->after('short_description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['specifications', 'gtin', 'mpn', 'summary']);
        });
    }
};
