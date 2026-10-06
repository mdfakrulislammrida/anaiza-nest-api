<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // cod, awaiting_verification, verified, failed. Only the admin actions move a wallet payment
            // out of awaiting_verification.
            $table->string('payment_status', 30)->default('cod')->index();
            // Stored upper-case and unique, so one transaction ID can only ever back one order, whatever
            // case it was typed in (MySQL and SQLite compare text differently, so the case is fixed on the way in).
            $table->string('payment_trx_id', 20)->nullable()->unique();
            $table->string('payment_sender_number', 20)->nullable();
            $table->timestamp('payment_verified_at')->nullable();
            $table->foreignId('payment_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('payment_note')->nullable();
        });

        // Existing orders: cash on delivery stays cash on delivery; any other method was a wallet (or card)
        // order, so its payment has not been checked by anyone yet.
        DB::table('orders')->where('payment_method', 'cod')->update(['payment_status' => 'cod']);
        DB::table('orders')->where('payment_method', '!=', 'cod')->update(['payment_status' => 'awaiting_verification']);

        Schema::table('payment_settings', function (Blueprint $table) {
            // Rich-text steps per method (blank = the built-in wording) and an optional uploaded logo.
            foreach (['bkash', 'nagad', 'rocket'] as $method) {
                $table->text("{$method}_instructions")->nullable();
                $table->string("{$method}_logo")->nullable();
            }
            // Where a new wallet order is announced; blank falls back to the contact email in Site Settings.
            $table->string('payment_notify_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn([
                'bkash_instructions', 'bkash_logo', 'nagad_instructions', 'nagad_logo',
                'rocket_instructions', 'rocket_logo', 'payment_notify_email',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_verified_by');
            $table->dropColumn(['payment_status', 'payment_trx_id', 'payment_sender_number', 'payment_verified_at', 'payment_note']);
        });
    }
};
