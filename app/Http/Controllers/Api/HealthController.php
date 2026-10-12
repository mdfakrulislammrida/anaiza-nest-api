<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * An address an outside uptime monitor can ask "is the shop answering, and can it reach its database?".
 * It says yes or no and nothing else: no versions, no paths, no counts.
 */
class HealthController extends Controller
{
    public function show()
    {
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            return response()->json(['status' => 'down'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
