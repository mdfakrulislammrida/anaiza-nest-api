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
        Schema::create('popup_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('newsletter_enabled')->default(false);
            $table->string('newsletter_trigger')->default('delay'); // delay | scroll | exit_intent
            $table->unsignedInteger('newsletter_delay_seconds')->default(5);
            $table->json('newsletter_pages')->nullable(); // ['home','shop','product','category'] or ['all']
            $table->string('newsletter_image')->nullable();

            $table->boolean('giftfinder_enabled')->default(false);
            $table->string('giftfinder_trigger')->default('delay');
            $table->unsignedInteger('giftfinder_delay_seconds')->default(8);
            $table->json('giftfinder_pages')->nullable();
            $table->string('giftfinder_image')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('popup_settings');
    }
};
