<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product can be listed in more than one category. products.category_id stays what it always was, the PRIMARY category (breadcrumb, feeds, schema, the
 * category shown on a card); this table lists every category the product is shown in, the primary included. Nothing is removed or changed: each existing
 * product gets one row, for the category it already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            // Gone with the product. A category is NOT cascade-deleted from a product: products.category_id still restricts deleting a category that is the primary
            // of a product; the rows for a category that is only an extra are removed with the category (the product stays).
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'category_id']);
            $table->index('category_id');
        });

        // Backfill, never lose anything: one row per existing product, for its current (primary) category.
        $now = now();

        DB::table('products')->orderBy('id')->select(['id', 'category_id'])->chunkById(500, function ($products) use ($now): void {
            DB::table('category_product')->insert($products->map(fn ($product): array => [
                'product_id' => $product->id,
                'category_id' => $product->category_id,
                'sort_order' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
