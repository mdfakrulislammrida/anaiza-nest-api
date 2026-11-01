<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Page;
use App\Models\Product;
use App\Support\Slugs;

class SitemapController extends Controller
{
    /**
     * Return the slugs and last-modified timestamps of every publicly
     * visible product, page, and article, so the Next.js frontend can build
     * (and keep up to date) its own sitemap.xml without hardcoding URLs.
     */
    public function index()
    {
        // Every item has its slug, its ready path (the same one the page lists, the homepage tiles and the product feeds use) and its last change.
        $with = fn ($rows, string $type) => $rows->map(fn ($row) => ['slug' => $row->slug, 'path' => Slugs::path($type, (string) $row->slug), 'updated_at' => $row->updated_at])->values();

        return response()->json([
            'products' => $with(Product::query()->where('is_active', true)->select('slug', 'updated_at')->get(), 'product'),
            'pages' => $with(Page::query()->select('slug', 'updated_at')->get(), 'page'),
            'articles' => Article::query()->published()->select('slug', 'updated_at')->get(),
            // Campaign pages are kept out of search unless someone switches "Do not index" off.
            'landing_pages' => $with(LandingPage::query()->live()->where('noindex', false)->select('slug', 'updated_at')->get(), 'landing_page'),
            'categories' => $with(Category::query()->select('slug', 'updated_at')->get(), 'category'),
            // The addresses pages used to have and where they are now: for the storefront to turn into redirects.
            'redirects' => Slugs::redirects(),
        ]);
    }
}
