<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductTagResource;
use App\Models\ProductTag;

class ProductTagController extends Controller
{
    public function index()
    {
        return ProductTagResource::collection(ProductTag::orderBy('name')->get());
    }
}
