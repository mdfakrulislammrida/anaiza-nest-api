<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the free-text city/postal_code shipping fields with the
     * Bangladesh Division/District/Thana hierarchy the checkout now
     * collects. city/postal_code become nullable rather than dropped, so
     * existing order history stays intact.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('division')->nullable()->after('address');
            $table->string('district')->nullable()->after('division');
            $table->string('thana')->nullable()->after('district');
            $table->string('city')->nullable()->change();
            $table->string('postal_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['division', 'district', 'thana']);
            $table->string('city')->nullable(false)->change();
            $table->string('postal_code')->nullable(false)->change();
        });
    }
};
