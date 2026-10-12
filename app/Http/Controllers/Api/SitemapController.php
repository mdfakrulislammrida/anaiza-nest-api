<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LandingPage;
use App\Models\Page;
use App\Models\Product;

class SitemapController extends Controller
{
    /**
     * Return the slugs and last-modified timestamps of every publicly
     * visible product, page, and article, so the Next.js frontend can build
     * (and keep up to date) its own sitemap.xml without hardcoding URLs.
     */
    public function index()
    {
        return response()->json([
            'products' => Product::query()
                ->where('is_active', true)
                ->select('slug', 'updated_at')
                ->get(),
            'pages' => Page::query()
                ->select('slug', 'updated_at')
                ->get(),
            'articles' => Article::query()
                ->published()
                ->select('slug', 'updated_at')
                ->get(),
            // Campaign pages are kept out of search unless someone switches "Do not index" off.
            'landing_pages' => LandingPage::query()
                ->live()
                ->where('noindex', false)
                ->select('slug', 'updated_at')
                ->get(),
        ]);
    }
}
