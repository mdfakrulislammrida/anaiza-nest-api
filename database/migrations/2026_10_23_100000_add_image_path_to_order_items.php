<?php

use App\Support\EmailThumbs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // The small picture of this line as it was ordered (a path on the public disk, under email-thumbs/).
            $table->string('image_path', 500)->nullable()->after('sku');
        });

        // Existing orders get the picture their product has now. A problem here must never stop the migration:
        // `php artisan orders:backfill-images` does the same again.
        try {
            EmailThumbs::backfill();
        } catch (Throwable $e) {
            Log::warning('The order line pictures were not backfilled.', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
