<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\ReviewEligibility;
use App\Support\ReviewPhotos;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductReviewController extends Controller
{
    public const RECEIVED = 'Thank you. Your review is with us and will appear once we have read it.';

    /**
     * Approved reviews only, newest first, with the summary the product page needs.
     */
    public function index(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $perPage = min(max($request->integer('per_page', 10), 1), 30);
        $page = $product->reviews()->approved()->latest('approved_at')->latest('id')->paginate($perPage);
        $summary = $product->reviews()->approved()->selectRaw('count(*) as total, avg(rating) as average')->first();
        $total = (int) ($summary?->total ?? 0);

        return response()->json([
            'data' => ReviewResource::collection($page->getCollection())->resolve($request),
            'summary' => [
                'rating_count' => $total,
                'rating_average' => $total > 0 ? round((float) $summary->average, 1) : null,
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * A new review. It comes from a signed-in customer who has ordered the piece, or from someone who gives the order number
     * and the phone number on that order. Either way it is saved pending, and the admin decides whether it is shown.
     */
    public function store(StoreProductReviewRequest $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        /** @var Customer|null $customer */
        $customer = $request->user('sanctum');
        $customer = $customer instanceof Customer ? $customer : null;

        $line = ReviewEligibility::lineFor($product, $customer, $request->input('order_number'), $request->input('phone'));

        $photos = [];
        if ($request->hasFile('photos')) {
            try {
                $photos = ReviewPhotos::store($request->file('photos'));
            } catch (\Throwable $e) {
                Log::warning('A review photo could not be processed.', ['error' => $e->getMessage()]);

                throw ValidationException::withMessages([
                    'photos' => 'We could not read one of the photos. Please use a JPG, PNG or WebP image.',
                ]);
            }
        }

        try {
            $review = ProductReview::create([
                'product_id' => $product->id,
                'customer_id' => $line->order?->customer_id,
                'order_id' => $line->order_id,
                'order_item_id' => $line->id,
                'name' => trim($request->string('name')->toString()),
                'rating' => $request->integer('rating'),
                'title' => filled($request->input('title')) ? trim($request->string('title')->toString()) : null,
                'body' => trim($request->string('body')->toString()),
                'photos' => $photos === [] ? null : $photos,
                'status' => ProductReview::PENDING,
                'verified_purchase' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two submissions for the same order line at once: the database lets one through.
            ReviewPhotos::delete($photos);

            throw ValidationException::withMessages(['rating' => 'You have already reviewed this piece from that order. Thank you.']);
        }

        return response()->json(['message' => self::RECEIVED], 201);
    }
}
