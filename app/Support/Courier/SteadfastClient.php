<?php

namespace App\Support\Courier;

use App\Models\CourierSetting;
use App\Support\Redact;
use App\Support\System\SystemEvents;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Steadfast (portal.packzy.com). Keys go in the Api-Key and Secret-Key headers of every call but /ping; there is no token.
 *
 * What the docs say that shapes this class:
 *  - There is NO sandbox: a booking is a real parcel. Nothing here books except createOrder(), and only the booking service
 *    calls that. The "Test connection" button uses ping() and balance() and nothing else.
 *  - Ten failed logins in five minutes lock the client out for an hour, so a 401 is never retried: it is recorded
 *    (steadfast_auth_failed_at) and nothing more is sent with those keys until they are saved again.
 *  - A 200 can hide a failure in the body, so the body is always read.
 *  - A duplicate invoice is refused, so the order number is the invoice and a repeat of a booking cannot book twice.
 *  - Status answers are cached for 60 seconds on their side: poll no faster than that.
 */
final class SteadfastClient
{
    public const BASE = 'https://portal.packzy.com/api/v1';

    public function __construct(private CourierSetting $settings) {}

    /** No keys needed: is the service up? */
    public function ping(): void
    {
        $this->call('GET', '/ping', withKeys: false);
    }

    /** Read-only: the account balance in taka. */
    public function balance(bool $force = false): float
    {
        $body = $this->call('GET', '/get_balance', force: $force);

        if (! isset($body['current_balance']) || ! is_numeric($body['current_balance'])) {
            throw new CourierException('Steadfast answered, but not with a balance.', CourierException::UNEXPECTED);
        }

        return (float) $body['current_balance'];
    }

    /**
     * Book one parcel. One retry on a lost connection is safe here: if the first request did get through, the retry is
     * refused as a duplicate invoice (CourierException::DUPLICATE) and the caller treats the order as booked.
     *
     * @param  array{invoice: string, recipient_name: string, recipient_phone: string, recipient_address: string, cod_amount: int, note?: string, item_description?: string}  $payload
     * @return array{consignment_id: string, tracking_code: ?string, status: string}
     */
    public function createOrder(array $payload): array
    {
        $body = $this->call('POST', '/create_order', $payload, retryOnNetwork: true);
        $consignment = $body['consignment'] ?? null;

        if (! is_array($consignment) || ! isset($consignment['consignment_id'])) {
            throw new CourierException($this->messageFrom($body) ?: 'Steadfast answered without a consignment, so the parcel may not have been booked. Check the Steadfast panel before trying again.', CourierException::REJECTED);
        }

        return [
            'consignment_id' => (string) $consignment['consignment_id'],
            'tracking_code' => isset($consignment['tracking_code']) ? (string) $consignment['tracking_code'] : null,
            'status' => (string) ($consignment['status'] ?? 'in_review'),
        ];
    }

    /** The delivery status of a parcel, by our invoice (the order number). Read-only. */
    public function statusByInvoice(string $invoice): string
    {
        $body = $this->call('GET', '/status_by_invoice/'.rawurlencode($invoice), retryOnNetwork: true);

        if (! isset($body['delivery_status']) || ! is_string($body['delivery_status']) || $body['delivery_status'] === '') {
            throw new CourierException('Steadfast answered, but with no delivery status.', CourierException::UNEXPECTED);
        }

        return $body['delivery_status'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $data = [], bool $withKeys = true, bool $retryOnNetwork = false, bool $force = false): array
    {
        if ($withKeys) {
            if (! $this->settings->hasSteadfastKeys()) {
                throw new CourierException('The Steadfast keys are not saved yet (Settings > Courier).', CourierException::AUTH);
            }

            // Refused before: do not knock again with the same keys. A person pressing "Test connection" may (one call).
            if ($this->settings->steadfast_auth_failed_at !== null && ! $force) {
                throw new CourierException('Steadfast refused these keys earlier, so nothing is sent until you save the keys again in Settings > Courier (ten refusals in five minutes lock the account for an hour).', CourierException::AUTH);
            }
        }

        $headers = $withKeys ? ['Api-Key' => (string) $this->settings->steadfast_api_key, 'Secret-Key' => (string) $this->settings->steadfast_secret_key] : [];
        $attempts = $retryOnNetwork ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withHeaders($headers)->acceptJson()->connectTimeout(6)->timeout(12)
                    ->send($method, self::BASE.$path, $method === 'GET' ? [] : ['json' => $data]);

                break;
            } catch (ConnectionException) {
                if ($attempt === $attempts) {
                    throw new CourierException('Could not reach Steadfast (no answer in time). Try again in a minute.', CourierException::NETWORK);
                }
                usleep(400_000);
            }
        }

        return $this->read($response, $withKeys);
    }

    /** @return array<string, mixed> */
    private function read(Response $response, bool $withKeys): array
    {
        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            if ($withKeys) {
                $this->settings->forceFill(['steadfast_auth_failed_at' => now()])->save();
                SystemEvents::record('courier_auth', 'Steadfast refused the keys (HTTP '.$status.'). Nothing more is sent until they are saved again.');
            }

            throw new CourierException('Steadfast refused the keys (HTTP '.$status.'). Fix them in Settings > Courier; nothing more is sent until you do.', CourierException::AUTH);
        }

        if ($status === 429 || $status >= 500) {
            throw new CourierException('Steadfast is busy or down just now (HTTP '.$status.'). Try again later.', CourierException::UNAVAILABLE);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        // A 200 can still be a refusal: Steadfast puts its own status in the body.
        $inner = $body['status'] ?? null;
        $failed = $status >= 400 || (is_numeric($inner) && (int) $inner >= 400);

        if ($failed) {
            $message = $this->messageFrom($body) ?: 'Steadfast refused the request (HTTP '.$status.').';
            $duplicate = isset($body['errors']['invoice']) && preg_match('/already|taken|exist|duplicate/i', json_encode($body['errors']['invoice']) ?: '');

            throw new CourierException($message, $duplicate ? CourierException::DUPLICATE : CourierException::REJECTED);
        }

        if ($withKeys) {
            $this->settings->forceFill(['steadfast_auth_failed_at' => null])->saveQuietly();
            SystemEvents::mark('courier_steadfast_ok', 'A call to Steadfast worked.');
        }

        return $body;
    }

    /** The courier's own reason, flattened and stripped of anything that looks like one of our secrets. */
    private function messageFrom(array $body): string
    {
        $parts = [];

        if (isset($body['message']) && is_string($body['message'])) {
            $parts[] = $body['message'];
        }

        if (isset($body['errors']) && is_array($body['errors'])) {
            array_walk_recursive($body['errors'], function ($line, $key) use (&$parts): void {
                if (is_string($line)) {
                    $parts[] = (is_string($key) ? $key.': ' : '').$line;
                }
            });
        }

        $text = mb_substr(implode(' ', array_unique($parts)), 0, 300);

        return Redact::secrets($text, [$this->settings->steadfast_api_key, $this->settings->steadfast_secret_key, $this->settings->steadfast_webhook_token]);
    }
}
