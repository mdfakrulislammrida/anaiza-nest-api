<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // What the browser knows about the visitor that Meta uses to match the shop's own Purchase to the same person as the
            // browser's and the server container's. All three are sent by the storefront with the order, checked for shape, and dropped
            // when they do not fit. Imported and admin-made orders have none.
            //   fbp          the _fbp cookie (fb.1.<ms>.<random>). Erased with the visitor's IP address and browser, once the sale is sent.
            //   fbc          the click id in Meta's format (fb.1.<ms>.<fbclid>). A click id, not a person: kept, like fbclid.
            //   external_id  a SHA-256 hash (64 hex characters) of the customer id or phone, already hashed by the storefront. Erased with fbp.
            $table->string('fbp', 100)->nullable();
            $table->string('fbc', 255)->nullable();
            $table->string('external_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fbp', 'fbc', 'external_id']);
        });
    }
};
