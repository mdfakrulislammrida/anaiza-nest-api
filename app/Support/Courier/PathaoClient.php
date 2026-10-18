<?php

namespace App\Support\Courier;

use App\Models\CourierSetting;
use App\Support\Redact;
use App\Support\System\SystemEvents;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Pathao Courier Merchant API ("Aladdin"). Sandbox courier-api-sandbox.pathao.com, live api-hermes.pathao.com.
 *
 * Login is OAuth: POST /aladdin/api/v1/issue-token with the password grant gives an access token (valid 432000 seconds, five
 * days) and a refresh token; the refresh_token grant renews it. Both are kept encrypted. A refused login is never retried
 * (it would only repeat), and it pauses Pathao until the login is saved again.
 *
 * An order is created without city, zone or area: Pathao reads them from the address. A null is never sent.
 * Pathao's docs list no webhook and no status list, so the parcel is followed by polling alone.
 */
final class PathaoClient
{
    private const API = '/aladdin/api/v1';

    public function __construct(private CourierSetting $settings) {}

    /** Token and stores: proves the login works and shows which store ids are yours. @return list<array{id: string, name: string}> */
    public function stores(): array
    {
        $body = $this->call('GET', '/stores');
        $list = $body['data']['data'] ?? $body['data'] ?? [];
        $stores = [];

        foreach (is_array($list) ? $list : [] as $store) {
            if (is_array($store) && isset($store['store_id'])) {
                $stores[] = ['id' => (string) $store['store_id'], 'name' => (string) ($store['store_name'] ?? '')];
            }
        }

        return $stores;
    }

    /**
     * Book one parcel. Not retried on a lost connection: unlike Steadfast's invoice, nothing in the docs says a repeat
     * would be refused, so a second attempt could book twice. The owner checks the Pathao panel and tries again.
     *
     * @param  array<string, mixed>  $payload
     * @return array{consignment_id: string, status: ?string}
     */
    public function createOrder(array $payload): array
    {
        // A null is never sent.
        $payload = array_filter($payload, fn ($value) => $value !== null);
        $body = $this->call('POST', '/orders', $payload);
        $data = $body['data'] ?? $body;
        $id = is_array($data) ? ($data['consignment_id'] ?? null) : null;

        if (! is_string($id) && ! is_int($id)) {
            throw new CourierException('Pathao answered without a consignment id, so the parcel may not have been booked. Check the Pathao panel before trying again.', CourierException::REJECTED);
        }

        return ['consignment_id' => (string) $id, 'status' => isset($data['order_status']) ? (string) $data['order_status'] : null];
    }

    /** @return array{slug: string, text: string, updated_at: ?string} what Pathao says about a parcel now. Read-only. */
    public function orderInfo(string $consignmentId): array
    {
        $body = $this->call('GET', '/orders/'.rawurlencode($consignmentId).'/info', retry: true);
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $slug = $data['order_status_slug'] ?? $data['order_status'] ?? null;

        if (! is_string($slug) || $slug === '') {
            throw new CourierException('Pathao answered, but with no order status.', CourierException::UNEXPECTED);
        }

        return [
            'slug' => $slug,
            'text' => is_string($data['order_status'] ?? null) ? $data['order_status'] : $slug,
            'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $data = [], bool $retry = false): array
    {
        $refreshed = false;
        $attempts = $retry ? 2 : 1;

        while (true) {
            $token = $this->token();

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $response = Http::withToken($token)->acceptJson()->connectTimeout(6)->timeout(15)
                        ->send($method, $this->settings->pathaoBaseUrl().self::API.$path, $method === 'GET' ? [] : ['json' => $data]);

                    break;
                } catch (ConnectionException) {
                    if ($attempt === $attempts) {
                        throw new CourierException('Could not reach Pathao (no answer in time). Check the Pathao panel before booking again.', CourierException::NETWORK);
                    }
                    usleep(400_000);
                }
            }

            // A token Pathao no longer accepts: renew it once and ask again. (A 401 means nothing was done, so asking again is safe.)
            if ($response->status() === 401 && ! $refreshed) {
                $refreshed = true;
                $this->settings->forceFill(['pathao_access_token' => null, 'pathao_token_expires_at' => null])->saveQuietly();

                continue;
            }

            return $this->read($response);
        }
    }

    /** A valid access token, from the saved one, a refresh, or a fresh login. */
    private function token(): string
    {
        if (! $this->settings->hasPathaoLogin()) {
            throw new CourierException('The Pathao login is not saved yet (Settings > Courier).', CourierException::AUTH);
        }

        $expires = $this->settings->pathao_token_expires_at;

        if (filled($this->settings->pathao_access_token) && $expires !== null && $expires->gt(now()->addHour())) {
            return (string) $this->settings->pathao_access_token;
        }

        if ($this->settings->pathao_auth_failed_at !== null) {
            throw new CourierException('Pathao refused this login earlier, so nothing is sent until you save the login again in Settings > Courier.', CourierException::AUTH);
        }

        $refresh = filled($this->settings->pathao_refresh_token);
        $credentials = ['client_id' => (string) $this->settings->pathao_client_id, 'client_secret' => (string) $this->settings->pathao_client_secret];
        $form = $refresh
            ? $credentials + ['grant_type' => 'refresh_token', 'refresh_token' => (string) $this->settings->pathao_refresh_token]
            : $credentials + ['grant_type' => 'password', 'username' => (string) $this->settings->pathao_username, 'password' => (string) $this->settings->pathao_password];

        try {
            $response = $this->issue($form);

            // A refresh token that no longer works: log in again with the password instead, once.
            if ($refresh && $response->failed() && $response->status() < 500) {
                $response = $this->issue($credentials + ['grant_type' => 'password', 'username' => (string) $this->settings->pathao_username, 'password' => (string) $this->settings->pathao_password]);
            }
        } catch (ConnectionException) {
            throw new CourierException('Could not reach Pathao to log in (no answer in time). Try again in a minute.', CourierException::NETWORK);
        }

        if ($response->status() >= 500) {
            throw new CourierException('Pathao is busy or down just now (HTTP '.$response->status().'). Try again later.', CourierException::UNAVAILABLE);
        }

        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            $this->settings->forceFill(['pathao_auth_failed_at' => now(), 'pathao_access_token' => null, 'pathao_refresh_token' => null, 'pathao_token_expires_at' => null])->save();
            SystemEvents::record('courier_auth', 'Pathao refused the login (HTTP '.$response->status().'). Nothing more is sent until it is saved again.');

            throw new CourierException('Pathao refused the login (HTTP '.$response->status().'). Check the client id, client secret, username and password (and that sandbox or live matches them) in Settings > Courier.', CourierException::AUTH);
        }

        $this->settings->forceFill([
            'pathao_access_token' => $body['access_token'],
            'pathao_refresh_token' => is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : $this->settings->pathao_refresh_token,
            'pathao_token_expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 432000)),
            'pathao_auth_failed_at' => null,
        ])->save();

        return $body['access_token'];
    }

    /** @param  array<string, string>  $form */
    private function issue(array $form): Response
    {
        return Http::acceptJson()->connectTimeout(6)->timeout(15)->post($this->settings->pathaoBaseUrl().self::API.'/issue-token', $form);
    }

    /** @return array<string, mixed> */
    private function read(Response $response): array
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new CourierException('Pathao refused the request (HTTP '.$status.'). Check the login in Settings > Courier.', CourierException::AUTH);
        }

        if ($status === 429 || $status >= 500) {
            throw new CourierException('Pathao is busy or down just now (HTTP '.$status.'). Try again later.', CourierException::UNAVAILABLE);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($status >= 400 || (isset($body['type']) && $body['type'] === 'error')) {
            throw new CourierException($this->messageFrom($body) ?: 'Pathao refused the request (HTTP '.$status.').', CourierException::REJECTED);
        }

        SystemEvents::mark('courier_pathao_ok', 'A call to Pathao worked.');

        return $body;
    }

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

        return Redact::secrets(mb_substr(implode(' ', array_unique($parts)), 0, 300), [
            $this->settings->pathao_client_secret, $this->settings->pathao_password, $this->settings->pathao_access_token, $this->settings->pathao_refresh_token,
        ]);
    }
}
