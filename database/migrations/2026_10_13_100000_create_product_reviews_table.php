<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // The order line being reviewed. One review per line; the line going away (an order deleted) keeps the review.
            $table->foreignId('order_item_id')->nullable()->unique()->constrained('order_items')->nullOnDelete();
            $table->string('name', 80);
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body');
            // Up to three photos, each {"url": "reviews/generated/....webp", "thumb": "reviews/generated/....-400.webp"}.
            $table->json('photos')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('admin_reply')->nullable();
            $table->timestamp('admin_replied_at')->nullable();
            // Every review comes from someone who ordered the product; the flag is kept so that can be shown, and relied on.
            $table->boolean('verified_purchase')->default(true);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
