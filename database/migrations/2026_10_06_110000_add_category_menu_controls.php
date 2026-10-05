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
        Schema::table('categories', function (Blueprint $table) {
            // Whether the category is listed in the storefront's Categories menu, and where.
            $table->boolean('show_in_menu')->default(true);
            $table->unsignedInteger('menu_order')->default(0);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            // Master switch for the Categories menu in the storefront header.
            $table->boolean('show_categories_menu')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['show_in_menu', 'menu_order']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('show_categories_menu');
        });
    }
};
