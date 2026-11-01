<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The addresses a category, a page or a landing page used to have, so an old link keeps working: the API answers it with the page and a hint to go to the
        // new address, and the storefront can turn the list into redirects. One row per old address; an address belongs to one page.
        Schema::create('slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->unsignedBigInteger('model_id');
            $table->string('old_slug', 255);
            $table->timestamps();

            $table->unique(['type', 'old_slug']);
            $table->index(['type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
    }
};
