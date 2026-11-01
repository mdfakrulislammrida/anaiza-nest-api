<?php

use App\Support\Courier\PathaoStatusPreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pathao status mapping, safely.
 *
 *  - courier_status_maps.status_key: the status with its spelling folded away, so one status is one row.
 *  - courier_status_maps.armed: a row that is not armed shows its meaning and changes no order. Every row that exists today stays armed (it
 *    already acts); the ready-made Pathao rows are added NOT armed.
 *  - orders.courier_effect_status: the status whose effect on the order has been worked out while armed, so arming later is picked up once.
 *    Steadfast orders are marked as done, because their rows have always acted.
 *
 * Nothing here changes an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_status_maps', function (Blueprint $table) {
            $table->string('status_key', 120)->nullable()->index();
            $table->boolean('armed')->default(true);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_effect_status', 120)->nullable();
        });

        foreach (DB::table('courier_status_maps')->get(['id', 'courier_status']) as $row) {
            $key = strtolower(trim((string) preg_replace('/[\s_\-]+/', '_', trim((string) $row->courier_status)), '_'));
            DB::table('courier_status_maps')->where('id', $row->id)->update(['status_key' => $key]);
        }

        foreach (DB::table('orders')->where('courier', 'steadfast')->whereNotNull('courier_status')->get(['id', 'courier_status']) as $order) {
            $key = strtolower(trim((string) preg_replace('/[\s_\-]+/', '_', trim((string) $order->courier_status)), '_'));
            DB::table('orders')->where('id', $order->id)->update(['courier_effect_status' => $key]);
        }

        PathaoStatusPreset::seed();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('courier_effect_status');
        });

        Schema::table('courier_status_maps', function (Blueprint $table) {
            $table->dropIndex(['status_key']);
            $table->dropColumn(['status_key', 'armed']);
        });
    }
};
