<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            // Up to three footer link columns, each with a title and items (same picker as the header).
            $table->json('footer_columns')->nullable();
            // A shortcut: list every category that is in the menu as sub-items of the Shop item.
            $table->boolean('nav_auto_categories')->default(false);
        });

        // Every item typed by hand before this is a Custom URL item now. Page and Category items store the
        // record id and are resolved to the current slug when the API answers.
        DB::table('site_settings')->get()->each(function ($row): void {
            $nav = json_decode((string) $row->nav_links, true);
            $footer = json_decode((string) $row->footer_links, true);

            $custom = fn (array $item): array => [
                'type' => 'custom',
                'label' => $item['label'] ?? '',
                'url' => $item['url'] ?? '',
            ];

            DB::table('site_settings')->where('id', $row->id)->update([
                'nav_links' => is_array($nav) ? json_encode(array_map($custom, $nav)) : $row->nav_links,
                // Today's footer links become the first column. A row that never customised them stays null and
                // keeps following the built-in defaults.
                'footer_columns' => is_array($footer) && $footer !== []
                    ? json_encode([['title' => 'Customer care', 'items' => array_map($custom, $footer)]])
                    : null,
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            // What the visitor chose for marketing cookies when they ordered; null on older orders.
            $table->boolean('marketing_consent')->nullable();
        });

        Schema::create('cookie_consent_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            // notice: tracking runs and the banner informs. opt_in: analytics and marketing wait for consent.
            $table->string('mode', 20)->default('notice');
            $table->text('banner_text')->nullable();
            $table->foreignId('privacy_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('privacy_link_label', 60)->nullable();
            $table->string('accept_label', 40)->nullable();
            $table->string('reject_label', 40)->nullable();
            $table->string('customize_label', 40)->nullable();
            $table->string('save_label', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_consent_settings');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('marketing_consent');
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['footer_columns', 'nav_auto_categories']);
        });
    }
};
