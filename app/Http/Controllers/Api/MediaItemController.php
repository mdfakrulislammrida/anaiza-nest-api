<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use Illuminate\Http\Request;

class MediaItemController extends Controller
{
    public function index(Request $request)
    {
        $items = MediaItem::query()
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 24));

        return MediaItemResource::collection($items);
    }
}
