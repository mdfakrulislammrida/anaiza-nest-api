<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking an order with a courier (Steadfast or Pathao) and following the parcel: the settings (every secret is stored
 * encrypted), what happened to each order (courier_events, append-only), and the table that says what each status a courier
 * reports means for us (courier_status_maps, editable in the admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_courier', 20)->nullable();
            // Off unless the owner turns it on. Never books a risky order, an unverified wallet order, or one with no phone or address.
            $table->boolean('auto_book')->default(false);
            $table->string('invoice_prefix', 20)->nullable();
            $table->decimal('default_weight', 4, 1)->default(0.5);
            $table->unsignedTinyInteger('pathao_item_type')->default(2);
            $table->unsignedSmallInteger('pathao_delivery_type')->default(48);

            $table->boolean('steadfast_enabled')->default(false);
            $table->text('steadfast_api_key')->nullable();
            $table->text('steadfast_secret_key')->nullable();
            $table->text('steadfast_webhook_token')->nullable();
            $table->string('steadfast_tracking_url', 300)->nullable();
            // Set when Steadfast answers 401: nothing more is sent with those keys (ten failures in five minutes lock the account).
            $table->timestamp('steadfast_auth_failed_at')->nullable();
            $table->timestamp('steadfast_webhook_last_at')->nullable();

            $table->boolean('pathao_enabled')->default(false);
            $table->boolean('pathao_sandbox')->default(true);
            $table->text('pathao_client_id')->nullable();
            $table->text('pathao_client_secret')->nullable();
            $table->text('pathao_username')->nullable();
            $table->text('pathao_password')->nullable();
            $table->string('pathao_store_id', 20)->nullable();
            $table->string('pathao_tracking_url', 300)->nullable();
            $table->text('pathao_access_token')->nullable();
            $table->text('pathao_refresh_token')->nullable();
            $table->timestamp('pathao_token_expires_at')->nullable();
            $table->timestamp('pathao_auth_failed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier', 20)->nullable();
            $table->string('courier_invoice', 120)->nullable();
            $table->string('courier_consignment_id', 80)->nullable()->index();
            $table->string('courier_tracking_code', 120)->nullable();
            $table->string('courier_tracking_url', 500)->nullable();
            $table->timestamp('courier_booked_at')->nullable();
            $table->unsignedBigInteger('courier_booked_by')->nullable();
            $table->timestamp('courier_booking_started_at')->nullable();
            $table->unsignedInteger('cod_amount_sent')->nullable();
            $table->string('courier_status', 120)->nullable();
            $table->string('courier_status_text', 160)->nullable();
            $table->smallInteger('courier_status_rank')->nullable();
            $table->timestamp('courier_synced_at')->nullable();
            $table->timestamp('courier_next_sync_at')->nullable();
            $table->unsignedSmallInteger('courier_sync_failures')->default(0);
            $table->timestamp('courier_final_at')->nullable();
            $table->timestamp('courier_returned_at')->nullable();
            $table->timestamp('cod_collected_at')->nullable();
            $table->unsignedInteger('cod_collected_amount')->nullable();
            $table->string('courier_last_error', 500)->nullable();
            $table->timestamp('courier_last_error_at')->nullable();

            $table->index(['courier', 'courier_final_at', 'courier_next_sync_at'], 'orders_courier_sync_index');
        });

        Schema::create('courier_events', function (Blueprint $table) {
            $table->id();
            // No foreign key: the history outlives an order that is deleted.
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('courier', 20);
            // booking | poll | webhook | admin
            $table->string('source', 20);
            $table->string('event_type', 60);
            $table->string('status', 120)->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('courier_status_maps', function (Blueprint $table) {
            $table->id();
            $table->string('courier', 20);
            $table->string('courier_status', 120);
            // What a person reads: "Picked up", "On its way", "Delivered".
            $table->string('stage', 80);
            // none | delivered | returned | partial
            $table->string('effect', 20)->default('none');
            $table->boolean('is_final')->default(false);
            $table->boolean('marks_cod_collected')->default(false);
            // Higher is later. A report with a lower rank than the one on the order is ignored (never backwards).
            $table->smallInteger('rank')->nullable();
            $table->unsignedInteger('seen_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['courier', 'courier_status']);
        });

        $now = now();
        $row = fn (string $status, string $stage, string $effect, bool $final, bool $cod, ?int $rank) => [
            'courier' => 'steadfast', 'courier_status' => $status, 'stage' => $stage, 'effect' => $effect,
            'is_final' => $final, 'marks_cod_collected' => $cod, 'rank' => $rank, 'created_at' => $now, 'updated_at' => $now,
        ];

        // Steadfast's own list. Anything that ends in _approval_pending is only the rider's word: it never delivers an order,
        // never marks cash collected. Only delivered, partial_delivered and cancelled are final. (The return statuses keep
        // Steadfast's spelling, "proccessing".) Pathao's docs list no statuses, so its rows are added as it reports them.
        DB::table('courier_status_maps')->insert([
            $row('pending', 'Booked, waiting for pickup', 'none', false, false, 10),
            $row('in_review', 'In review', 'none', false, false, 20),
            $row('hold', 'On hold', 'none', false, false, 25),
            $row('exceptional', 'Held up', 'none', false, false, 25),
            $row('delivered_approval_pending', 'Rider says delivered (not confirmed)', 'none', false, false, 50),
            $row('partial_delivered_approval_pending', 'Rider says partly delivered (not confirmed)', 'none', false, false, 50),
            $row('cancelled_approval_pending', 'Rider says cancelled (not confirmed)', 'none', false, false, 50),
            $row('unknown_approval_pending', 'Waiting for the courier to confirm', 'none', false, false, 50),
            $row('delivered', 'Delivered', 'delivered', true, true, 100),
            $row('partial_delivered', 'Partly delivered', 'partial', true, false, 100),
            $row('cancelled', 'Cancelled by the courier', 'returned', true, false, 100),
            $row('unknown', 'Unknown', 'none', false, false, null),
            $row('partial_delivered_return_proccessing', 'Return being processed', 'returned', false, false, 110),
            $row('partial_delivered_return_rider_assigned', 'Return rider assigned', 'returned', false, false, 120),
            $row('partial_delivered_return_received', 'Return received', 'returned', false, false, 130),
            $row('cancelled_return_proccessing', 'Return being processed', 'returned', false, false, 110),
            $row('cancelled_return_rider_assigned', 'Return rider assigned', 'returned', false, false, 120),
            $row('cancelled_return_received', 'Return received', 'returned', false, false, 130),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_status_maps');
        Schema::dropIfExists('courier_events');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_courier_sync_index');
            $table->dropColumn([
                'courier', 'courier_invoice', 'courier_consignment_id', 'courier_tracking_code', 'courier_tracking_url', 'courier_booked_at',
                'courier_booked_by', 'courier_booking_started_at', 'cod_amount_sent', 'courier_status', 'courier_status_text',
                'courier_status_rank', 'courier_synced_at', 'courier_next_sync_at', 'courier_sync_failures', 'courier_final_at',
                'courier_returned_at', 'cod_collected_at', 'cod_collected_amount', 'courier_last_error', 'courier_last_error_at',
            ]);
        });

        Schema::dropIfExists('courier_settings');
    }
};
