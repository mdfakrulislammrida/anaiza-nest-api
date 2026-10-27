<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row for every time the shop's own server tried to report a Purchase to Meta or TikTok (or decided not to): what came
        // back, whether trying again could help, and when. No token and no customer detail is ever written here.
        Schema::create('conversion_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);
            $table->unsignedTinyInteger('attempt');
            $table->string('trigger', 10)->default('auto');
            $table->string('status', 10);
            $table->boolean('retryable')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 500)->nullable();
            // The time the sale is reported at (kept so a retry reports the same moment).
            $table->unsignedInteger('event_time')->nullable();
            // When the next automatic try is due. Null once it has been taken, or when there is none.
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_attempts');
    }
};
