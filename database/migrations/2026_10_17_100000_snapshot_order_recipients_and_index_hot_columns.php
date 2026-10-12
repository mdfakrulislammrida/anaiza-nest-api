<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An order now carries its own copy of who it is for and where it goes. Until now those details lived only on the
     * customer record, and the next order from the same phone number rewrote them, so an earlier order would show a later
     * order's address (and a checkout could change someone's account email). Existing orders are filled from their
     * customer, which is the best record there is of them.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ship_name')->nullable();
            $table->string('ship_email')->nullable();
            $table->string('ship_phone', 30)->nullable();
            $table->string('ship_address', 500)->nullable();
            $table->string('ship_division', 60)->nullable();
            $table->string('ship_district', 60)->nullable();
            $table->string('ship_thana', 60)->nullable();
        });

        foreach ([
            'ship_name' => 'name',
            'ship_email' => 'email',
            'ship_phone' => 'phone',
            'ship_address' => 'address',
            'ship_division' => 'division',
            'ship_district' => 'district',
            'ship_thana' => 'thana',
        ] as $order => $customer) {
            DB::statement("UPDATE orders SET {$order} = (SELECT customers.{$customer} FROM customers WHERE customers.id = orders.customer_id) WHERE {$order} IS NULL");
        }

        // Lookups the checkout, the tracking page, the login and the admin lists do on every request. SQLite does not index
        // a foreign key by itself.
        Schema::table('customers', function (Blueprint $table) {
            $table->index('phone');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('customer_id');
            $table->index('status');
            $table->index('created_at');
            $table->index('ship_phone');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['is_active', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'category_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['ship_phone']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['phone']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ship_name', 'ship_email', 'ship_phone', 'ship_address', 'ship_division', 'ship_district', 'ship_thana']);
        });
    }
};
