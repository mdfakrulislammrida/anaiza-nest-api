<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Courier\SteadfastWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Where couriers report back. Everything is in App\Support\Courier\SteadfastWebhook. */
class CourierWebhookController extends Controller
{
    public function steadfast(Request $request): JsonResponse
    {
        return SteadfastWebhook::handle($request);
    }
}
