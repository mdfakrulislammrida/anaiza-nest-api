<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TestimonialResource;
use App\Models\Testimonial;
use Illuminate\Http\Request;

class TestimonialController extends Controller
{
    public function index(Request $request)
    {
        $testimonials = Testimonial::query()
            ->when($request->boolean('featured'), fn ($query) => $query->where('is_featured', true))
            ->orderBy('created_at', 'desc')
            ->get();

        return TestimonialResource::collection($testimonials);
    }
}
