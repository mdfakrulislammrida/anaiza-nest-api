<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CorporateEnquiryMail;
use App\Models\CorporateEnquiry;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CorporateEnquiryController extends Controller
{
    public const RECEIVED = 'Thank you. We have your enquiry and will be in touch.';

    public function store(Request $request): JsonResponse
    {
        // Bot trap: a field real visitors never see. A filled one gets the same friendly answer
        // and nothing else happens, so a bot learns nothing. (The route is also rate limited.)
        if (filled($request->input('website'))) {
            return response()->json(['message' => self::RECEIVED], 201);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'products_of_interest' => ['nullable', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'needed_by.after_or_equal' => 'Please choose today or a later date.',
        ]);

        $enquiry = CorporateEnquiry::create($data);

        $this->notify($enquiry);

        return response()->json(['message' => self::RECEIVED], 201);
    }

    /**
     * Announces the enquiry by email without making the visitor wait and without a queue worker: it
     * runs after the response has been sent. A failure is logged and goes no further, since the
     * enquiry is already saved and visible in the admin.
     */
    private function notify(CorporateEnquiry $enquiry): void
    {
        $to = SiteSetting::query()->value('corporate_notify_email');

        if (blank($to)) {
            return;
        }

        dispatch(function () use ($enquiry, $to): void {
            try {
                Mail::to($to)->send(new CorporateEnquiryMail($enquiry));
            } catch (\Throwable $e) {
                Log::warning('Corporate enquiry notification email failed.', [
                    'enquiry_id' => $enquiry->id,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }
}
