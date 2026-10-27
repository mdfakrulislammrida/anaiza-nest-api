<?php

namespace App\Support;

use App\Models\MarketingSetting;
use Illuminate\Support\Facades\Http;

/**
 * The "Send test event" button on Marketing Settings: one harmless event to Meta's Conversions API with the saved
 * Pixel ID and access token (and the test event code, if one is set), and Meta's own answer in plain words. The
 * token is never put in the answer.
 */
final class MetaTestEvent
{
    /** @return array{ok: bool, message: string} */
    public static function send(MarketingSetting $settings, ?string $clientIp, ?string $clientUserAgent): array
    {
        $token = (string) $settings->meta_capi_access_token;
        $pixel = trim((string) $settings->meta_pixel_id);

        if ($pixel === '' || $token === '') {
            return ['ok' => false, 'message' => 'Save the Meta Pixel ID and the Conversions API access token first, then send the test.'];
        }

        $code = trim((string) $settings->meta_test_event_code);

        $payload = [
            'data' => [[
                'event_name' => 'TestEvent',
                'event_time' => time(),
                'event_id' => 'test-'.bin2hex(random_bytes(6)),
                'action_source' => 'website',
                'event_source_url' => rtrim((string) config('app.frontend_url'), '/').'/',
                'user_data' => array_filter([
                    'client_ip_address' => $clientIp,
                    'client_user_agent' => $clientUserAgent ?: 'Anaiza Nest admin test',
                ]),
            ]],
        ];

        if ($code !== '') {
            $payload['test_event_code'] = $code;
        }

        try {
            $response = Http::asJson()->connectTimeout(5)->timeout(20)->post("https://graph.facebook.com/v18.0/{$pixel}/events", [...$payload, 'access_token' => $token]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Meta: '.Redact::secrets($e->getMessage(), [$token])];
        }

        $body = $response->json() ?? [];

        if ($response->successful() && (int) ($body['events_received'] ?? 0) >= 1) {
            $where = $code !== '' ? "It should appear under Test events for code {$code}." : 'No test event code is set, so Meta counts it as a real custom event named TestEvent.';

            return ['ok' => true, 'message' => 'Meta accepted the test event. '.self::summary($body).' '.$where];
        }

        $error = $body['error'] ?? [];
        $text = trim((string) ($error['message'] ?? ''));
        $detail = trim((string) ($error['error_user_msg'] ?? ''));

        if ($text === '') {
            $text = $response->successful() ? 'Meta replied without accepting the event.' : 'Meta replied with HTTP '.$response->status().'.';
        }

        $message = 'Meta said: '.$text.($detail !== '' ? " ({$detail})" : '').(isset($error['code']) ? ' [code '.$error['code'].']' : '').' '.self::summary($body, $response->status());

        return ['ok' => false, 'message' => Redact::secrets($message, [$token])];
    }

    /** Meta's answer in a line: events_received, fbtrace_id (what Meta support asks for) and the HTTP status. No token. */
    private static function summary(array $body, ?int $status = null): string
    {
        $trace = $body['fbtrace_id'] ?? $body['error']['fbtrace_id'] ?? null;

        return '['.implode(', ', array_filter([
            'events_received='.(int) ($body['events_received'] ?? 0),
            $trace !== null ? 'fbtrace_id='.$trace : null,
            $status !== null ? 'HTTP '.$status : null,
        ])).']';
    }
}
