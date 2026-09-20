<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductAttributeResource;
use App\Models\ProductAttribute;

class ProductAttributeController extends Controller
{
    public function index()
    {
        return ProductAttributeResource::collection(
            ProductAttribute::with('values')->orderBy('name')->get()
        );
    }
}
