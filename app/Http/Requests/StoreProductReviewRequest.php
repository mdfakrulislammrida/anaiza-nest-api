<?php

namespace App\Http\Requests;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'name' => ['required', 'string', 'max:80'],
            // How a visitor who is not signed in proves they ordered it.
            'order_number' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photos' => ['nullable', 'array', 'max:'.ProductReview::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ];
    }

    /**
     * Error wording in the shop's voice.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Please choose a rating from one to five stars.',
            'rating.between' => 'Please choose a rating from one to five stars.',
            'body.required' => 'Please tell us a little about how it was.',
            'body.min' => 'Please tell us a little more, at least ten characters.',
            'body.max' => 'That is a little long. Please keep it under 2,000 characters.',
            'name.required' => 'Please tell us the name to show with your review.',
            'photos.max' => 'You can add up to three photos.',
            'photos.*.image' => 'We could not read one of the photos. Please use a JPG, PNG or WebP image.',
            'photos.*.mimes' => 'We could not read one of the photos. Please use a JPG, PNG or WebP image.',
            'photos.*.max' => 'One of the photos is larger than 3 MB. Please choose a smaller one.',
        ];
    }
}
