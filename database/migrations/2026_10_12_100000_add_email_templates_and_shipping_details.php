<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            // The old single body is the greeting/intro of the new, fuller template.
            $table->renameColumn('body_html', 'intro_html');
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->longText('closing_html')->nullable();
            $table->text('footer_note')->nullable();
            // "Custom full HTML": replaces the whole body between the branded header and footer.
            $table->boolean('use_custom_html')->default(false);
            $table->longText('custom_html')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_name', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            // Which status emails have been sent for this order, by status ({"shipped": "2026-10-12T..."}), so
            // changing the status back and forth never sends the same one twice.
            $table->json('emails_sent')->nullable();
        });

        // Orders that are already shipped, delivered or cancelled have long since had their moment: record them as
        // sent so that changing one of them later does not email an old customer.
        $now = now()->toIso8601String();
        foreach (['shipped', 'delivered', 'cancelled'] as $status) {
            DB::table('orders')->where('status', $status)->update(['emails_sent' => json_encode([$status => $now])]);
        }

        Schema::table('site_settings', function (Blueprint $table) {
            // One-colour (black) logo for the invoice, which carries no decoration or colour.
            $table->string('logo_mono')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('logo_mono');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_name', 'tracking_number', 'emails_sent']);
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['closing_html', 'footer_note', 'use_custom_html', 'custom_html']);
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->renameColumn('intro_html', 'body_html');
        });
    }
};
