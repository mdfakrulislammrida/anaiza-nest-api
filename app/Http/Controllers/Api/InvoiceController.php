<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\InvoicePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The invoice PDF of an order, for the customer.
 *
 * Two ways in, both ending in respond():
 *  - signed(): the link that comes with the order answer (placing the order, or Track order once the phone number is proved). The signature is the proof; there is
 *    no login. A link that is wrong, changed or older than 7 days is a 403.
 *  - mine(): a signed-in customer asking for one of their own orders. Someone else's order, or one that does not exist, is the same 404.
 * Every failure is JSON (never an HTML page, never a bare 500), says what to do, and is written to the log without any personal detail.
 */
class InvoiceController extends Controller
{
    public function signed(Request $request, string $order): Response|JsonResponse
    {
        if (! $request->hasValidSignature()) {
            Log::info('Invoice link refused.', ['order_id' => (int) $order, 'path' => 'signed', 'reason' => 'bad or expired signature']);

            return response()->json(['message' => 'This invoice link is not valid any more. Open your order again (Track order) to get a new one.', 'code' => 'invoice_link_invalid'], 403);
        }

        $found = Order::query()->with(['customer', 'items'])->find((int) $order);

        return $found === null ? $this->notFound() : $this->respond($found, 'signed');
    }

    public function mine(Request $request, string $order): Response|JsonResponse
    {
        $found = Order::query()->with(['customer', 'items'])->where('customer_id', $request->user()->getKey())->find((int) $order);

        return $found === null ? $this->notFound() : $this->respond($found, 'account');
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'We could not find that order.', 'code' => 'order_not_found'], 404);
    }

    /** Makes the PDF and sends it as a download; a failure is logged (order, path, kind of error) and answered in words. */
    public function respond(Order $order, string $path): Response|JsonResponse
    {
        try {
            $pdf = InvoicePdf::make($order)->output();
        } catch (\Throwable $e) {
            Log::error('The invoice could not be made.', [
                'order_id' => $order->id, 'path' => $path, 'exception' => $e::class, 'server_problems' => InvoicePdf::problems(),
            ]);

            return response()->json([
                'message' => 'We could not make the invoice just now. Please try again in a moment, or contact us with your order number and we will send it to you.',
                'code' => 'invoice_failed',
            ], 500);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.InvoicePdf::filename($order).'"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
