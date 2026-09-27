<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('homepage_sections', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->integer('position')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->string('custom_title')->nullable();
            $table->longText('custom_html')->nullable();
            $table->timestamps();
        });

        // Today's actual homepage order (see HomeClient.tsx/page.tsx) as the
        // starting rows, so an existing deploy's homepage looks identical
        // the moment this ships -- nothing to configure before it works.
        $now = now();
        DB::table('homepage_sections')->insert([
            ['type' => 'hero_banner', 'position' => 0, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'hot_deals', 'position' => 1, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'bestsellers', 'position' => 2, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'new_arrivals', 'position' => 3, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'newsletter', 'position' => 4, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('homepage_sections');
    }
};
