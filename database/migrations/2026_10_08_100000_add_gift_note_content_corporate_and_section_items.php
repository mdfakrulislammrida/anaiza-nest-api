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
        Schema::table('orders', function (Blueprint $table) {
            // "This is a gift" at checkout. The message is optional and capped at 200 characters;
            // the older free-text gift_note column keeps holding the customer's general order notes.
            $table->boolean('is_gift')->default(false);
            $table->string('gift_message', 200)->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('box_contents')->nullable();
            $table->text('care_instructions')->nullable();
            $table->boolean('gift_box_included')->default(false);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->text('corporate_intro')->nullable();
            // Where corporate-gifting enquiries are announced. Blank means no email is sent.
            $table->string('corporate_notify_email')->nullable();
        });

        Schema::table('homepage_sections', function (Blueprint $table) {
            // The tiles of a "Shop by occasion" section, and the lines of a "Why Anaiza Nest" section.
            $table->json('tiles')->nullable();
            $table->json('reasons')->nullable();
        });

        Schema::create('corporate_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company');
            $table->string('phone', 30);
            $table->string('email');
            $table->unsignedInteger('quantity');
            $table->date('needed_by')->nullable();
            $table->text('products_of_interest')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_enquiries');

        Schema::table('homepage_sections', function (Blueprint $table) {
            $table->dropColumn(['tiles', 'reasons']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['corporate_intro', 'corporate_notify_email']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['box_contents', 'care_instructions', 'gift_box_included']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_gift', 'gift_message']);
        });
    }
};
