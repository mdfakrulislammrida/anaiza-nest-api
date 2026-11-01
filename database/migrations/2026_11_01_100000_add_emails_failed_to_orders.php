<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // An order email that could not be sent (an SMTP timeout, say) and is waiting for its next try, per kind of email:
            //   { "confirmation": { "tries": 1, "at": "<time of the last try>", "first_at": "<time of the first>" }, "shipped": { ... } }
            // The scheduled task emails:retry sends them again (3 more tries, within 24 hours); no error text or address is kept here.
            $table->json('emails_failed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('emails_failed');
        });
    }
};
