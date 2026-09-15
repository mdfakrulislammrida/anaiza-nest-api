<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $articles = Article::query()
            ->published()
            ->orderByDesc('published_at')
            ->paginate($request->integer('per_page', 15));

        return ArticleResource::collection($articles);
    }

    public function show(string $slug)
    {
        $article = Article::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return ArticleResource::make($article);
    }
}
