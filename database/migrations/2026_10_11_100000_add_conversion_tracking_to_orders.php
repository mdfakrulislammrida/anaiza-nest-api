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
            // A wallet order is sent to Meta and TikTok only once its payment is verified, which may be hours later,
            // when there is no checkout request to read the visitor's IP address and browser from. They are kept here
            // until then (and cleared once the send decision is made).
            $table->string('client_ip', 45)->nullable();
            $table->string('client_user_agent', 500)->nullable();
            // When the sale was sent to Meta and TikTok. Set exactly once per order, so verifying twice cannot send twice.
            $table->timestamp('conversion_sent_at')->nullable();
        });

        // Every order that exists now was already counted at placement under the old rule (or was skipped for lack of
        // consent), so verifying one of them later must not send it again.
        DB::table('orders')->whereNull('conversion_sent_at')->update(['conversion_sent_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['client_ip', 'client_user_agent', 'conversion_sent_at']);
        });
    }
};
