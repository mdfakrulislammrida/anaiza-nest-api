<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Footer blurbs earlier migrations/defaults wrote into settings rows. A row still holding one of
     * these verbatim is cleared so it falls back to the brand kit's one-line boilerplate; text the
     * admin edited is left alone.
     */
    private const OLD_FOOTER_ABOUT = [
        "Bangladesh's #1 gift shop — handcrafted ceramic tea sets, porcelain collections, and premium gift boxes, delivered across Bangladesh with cash-on-delivery and mobile-wallet checkout.",
        'Handcrafted ceramic tea sets, porcelain collections, and premium gift boxes, delivered across Bangladesh with cash-on-delivery and mobile-wallet checkout.',
    ];

    /**
     * Stored menu labels that were still the old Title Case defaults, keyed by URL. Anything the
     * admin renamed is left alone: a label is only changed while it still equals the old default.
     */
    private const NAV_LABELS = [
        '/hot-deals' => ['Hot Deals', 'Special prices'],
        '/gift-finder' => ['Gift Finder', 'Gift finder'],
    ];

    private const FOOTER_LABELS = [
        '/track-order' => ['Track Order', 'Track order'],
        '/pages/shipping' => ['Shipping Policy', 'Shipping policy'],
        '/pages/returns' => ['Returns & Refunds', 'Returns & refunds'],
        '/contact' => ['Contact Us', 'Contact us'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            // Brand kit: the line shown under the logo, in the footer and in default OG data.
            $table->string('tagline', 120)->nullable()->default('Gifted, beautifully.');

            // Logo masters, one per colourway. The existing logo_url stays as the fallback.
            $table->string('logo_navy')->nullable();
            $table->string('logo_ivory')->nullable();
            $table->string('monogram')->nullable();

            // At or below this many in stock (and above zero), a product page says "Only a few left".
            $table->unsignedInteger('low_stock_threshold')->default(5);
        });

        Schema::table('homepage_sections', function (Blueprint $table) {
            // Editable subtitle for every built-in section (custom_title already existed).
            $table->string('custom_subtitle', 255)->nullable();
        });

        // "Hot Deals" became "Special prices", and menu labels are sentence case now.
        $this->relabel('nav_links', self::NAV_LABELS);
        $this->relabel('footer_links', self::FOOTER_LABELS);

        DB::table('site_settings')->whereIn('footer_about', self::OLD_FOOTER_ABOUT)->update(['footer_about' => null]);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $map  url => [old label, new label]
     */
    private function relabel(string $column, array $map): void
    {
        DB::table('site_settings')->whereNotNull($column)->get(['id', $column])->each(function ($row) use ($column, $map) {
            $links = json_decode($row->{$column}, true);

            if (! is_array($links)) {
                return;
            }

            $changed = false;
            foreach ($links as &$link) {
                [$old, $new] = $map[$link['url'] ?? ''] ?? [null, null];

                if ($old !== null && ($link['label'] ?? null) === $old) {
                    $link['label'] = $new;
                    $changed = true;
                }
            }
            unset($link);

            if ($changed) {
                DB::table('site_settings')->where('id', $row->id)->update([$column => json_encode($links)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'logo_navy', 'logo_ivory', 'monogram', 'low_stock_threshold']);
        });

        Schema::table('homepage_sections', function (Blueprint $table) {
            $table->dropColumn('custom_subtitle');
        });
    }
};
