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
        Schema::table('product_variants', function (Blueprint $table) {
            // All nullable: a variant with no overrides behaves exactly like
            // today, inheriting the parent product's price/stock (see the
            // effective_* accessors on ProductVariant).
            $table->unsignedInteger('price')->nullable()->after('value');
            $table->unsignedInteger('sale_price')->nullable()->after('price');
            $table->unsignedInteger('stock_quantity')->nullable()->after('sale_price');
            $table->string('sku')->nullable()->after('stock_quantity');
            $table->foreignId('image_id')->nullable()->after('sku')
                ->constrained('product_images')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_id');
            $table->dropColumn(['price', 'sale_price', 'stock_quantity', 'sku']);
        });
    }
};
