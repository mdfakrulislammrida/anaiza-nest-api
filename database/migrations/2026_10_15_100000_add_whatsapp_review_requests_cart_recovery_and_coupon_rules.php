<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            // The shop's WhatsApp number (international format), the first message a chat opens with, whether the
            // bubble shows, and the pages (path prefixes) where it is hidden.
            $table->string('whatsapp_number', 32)->nullable();
            $table->text('whatsapp_default_message')->nullable();
            $table->boolean('whatsapp_show_bubble')->default(true);
            $table->json('whatsapp_hidden_paths')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('coupon_code', 40)->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable()->index();
            $table->unsignedInteger('discount_amount')->default(0);

            // When the order was marked delivered: review requests count their days from here.
            $table->timestamp('delivered_at')->nullable()->index();
            $table->timestamp('review_request_sent_at')->nullable();
            $table->string('review_request_status', 24)->nullable();
            $table->timestamp('review_reminder_sent_at')->nullable();
            $table->timestamp('review_whatsapp_clicked_at')->nullable();
        });

        Schema::table('coupons', function (Blueprint $table) {
            // Exact start and end, stored in UTC and shown in Dhaka time. The old date-only expires_at stays, unused.
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('min_order')->nullable();
            $table->boolean('single_use')->default(false);
            // A coupon bound to a person can only be used by an order from that email or that phone number.
            $table->string('bound_email')->nullable()->index();
            $table->string('bound_phone', 20)->nullable()->index();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('used_order_id')->nullable();
            $table->string('source', 20)->default('manual');
            $table->unsignedBigInteger('abandoned_checkout_id')->nullable()->index();
        });

        Schema::create('abandoned_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('session_token', 64)->unique();
            $table->string('recovery_token', 48)->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 20)->nullable()->index();
            $table->string('stage', 20)->default('cart')->index();
            $table->json('items')->nullable();
            $table->unsignedInteger('cart_total')->default(0);
            $table->json('viewed_products')->nullable();
            $table->timestamp('first_activity_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->timestamp('ordered_at')->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->boolean('whatsapp_optin')->default(false);
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->json('messages')->nullable();
            $table->unsignedTinyInteger('messages_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->boolean('unsubscribed')->default(false);
            $table->timestamp('whatsapp_clicked_at')->nullable();
            $table->timestamps();
        });

        // Visitors with nothing to contact them by are never stored one by one, only counted: a day (Dhaka) and a stage.
        Schema::create('checkout_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('stage', 20);
            $table->unsignedInteger('visitors')->default(0);
            $table->unique(['day', 'stage']);
        });

        // Anyone who has asked not to get cart or review emails, kept by email and by phone.
        Schema::create('email_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable()->index();
            $table->string('phone', 20)->nullable()->index();
            $table->string('source', 30)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('review_request_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // Only orders delivered after the requests were switched on are asked, so old orders are never mailed in bulk.
            $table->timestamp('enabled_at')->nullable();
            $table->unsignedTinyInteger('days_after_delivery')->default(5);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('whatsapp_enabled')->default(true);
            $table->boolean('reminder_enabled')->default(false);
            $table->unsignedTinyInteger('reminder_days')->default(7);
            $table->text('whatsapp_message')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_recovery_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->boolean('step1_enabled')->default(true);
            $table->unsignedInteger('step1_delay_minutes')->default(60);
            $table->boolean('step2_enabled')->default(true);
            $table->unsignedInteger('step2_delay_minutes')->default(2880);
            $table->boolean('step2_coupon_enabled')->default(false);
            $table->string('coupon_type', 10)->default('percent');
            $table->unsignedInteger('coupon_amount')->default(10);
            $table->unsignedInteger('coupon_min_order')->nullable();
            $table->unsignedInteger('coupon_valid_hours')->default(48);
            $table->unsignedTinyInteger('max_messages')->default(2);
            $table->string('quiet_start', 5)->default('22:00');
            $table->string('quiet_end', 5)->default('08:00');
            $table->unsignedInteger('daily_cap')->default(100);
            $table->unsignedInteger('min_cart_value')->default(0);
            $table->unsignedTinyInteger('max_age_days')->default(7);
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->text('whatsapp_message')->nullable();
            $table->timestamps();
        });

        // Existing coupons keep working: their date becomes the end of that day in Dhaka time.
        DB::table('coupons')->whereNotNull('expires_at')->orderBy('id')->each(function ($coupon): void {
            $endOfDay = Carbon::parse($coupon->expires_at, 'Asia/Dhaka')->endOfDay()->utc();
            DB::table('coupons')->where('id', $coupon->id)->update(['valid_until' => $endOfDay]);
        });

        // Orders already delivered: the moment their email went out, else the last time they changed.
        DB::table('orders')->where('status', 'delivered')->orderBy('id')->each(function ($order): void {
            $sent = is_string($order->emails_sent) ? json_decode($order->emails_sent, true) : null;
            $at = $sent['delivered'] ?? $order->updated_at;

            DB::table('orders')->where('id', $order->id)->update(['delivered_at' => $at ? Carbon::parse($at)->utc() : null]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_recovery_settings');
        Schema::dropIfExists('review_request_settings');
        Schema::dropIfExists('email_opt_outs');
        Schema::dropIfExists('checkout_daily_counts');
        Schema::dropIfExists('abandoned_checkouts');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['valid_from', 'valid_until', 'min_order', 'single_use', 'bound_email', 'bound_phone', 'used_count', 'used_at', 'used_order_id', 'source', 'abandoned_checkout_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_id', 'discount_amount', 'delivered_at', 'review_request_sent_at', 'review_request_status', 'review_reminder_sent_at', 'review_whatsapp_clicked_at']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'whatsapp_default_message', 'whatsapp_show_bubble', 'whatsapp_hidden_paths']);
        });
    }
};
