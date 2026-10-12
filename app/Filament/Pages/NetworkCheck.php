<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Shows exactly what the server receives for YOUR request, so the client-IP and proxy settings are set from evidence, not
 * guesswork. Every limit in the shop (orders, tracking, forms, login) is counted per visitor address: this page says whether
 * that address is the visitor's own or the CDN's.
 */
class NetworkCheck extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Network check';

    protected static ?string $title = 'Network check';

    protected static string $view = 'filament.pages.network-check';

    protected static ?string $slug = 'network-check';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('integration_settings.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $request = request();
        $remote = (string) $request->server->get('REMOTE_ADDR');
        $resolved = (string) $request->ip();
        $forwarded = trim((string) $request->headers->get('X-Forwarded-For'));
        $firstForwarded = trim(explode(',', $forwarded)[0] ?? '');

        $headers = [];
        foreach (['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP', 'True-Client-IP', 'Forwarded', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'Via', 'CDN-Loop'] as $name) {
            $headers[$name] = trim((string) $request->headers->get($name));
        }

        $realIp = $headers['X-Real-IP'] ?: ($headers['CF-Connecting-IP'] ?: $headers['True-Client-IP']);
        $realHeader = $headers['X-Real-IP'] !== '' ? 'X-Real-IP' : ($headers['CF-Connecting-IP'] !== '' ? 'CF-Connecting-IP' : ($headers['True-Client-IP'] !== '' ? 'True-Client-IP' : null));

        if ($forwarded === '' && $realIp === '') {
            $verdict = 'ok';
            $advice = 'The connection arrived with no forwarding headers, so the address PHP sees is the address of whoever connected. If that is your own public address (compare with any "what is my IP" site) nothing needs changing.';
        } elseif ($resolved !== $remote) {
            $verdict = 'ok';
            $advice = 'The shop is already reading the visitor\'s address from a forwarding header. If the "Address the shop uses" below is your own public address, this is correct.';
        } elseif ($firstForwarded !== '' && $firstForwarded !== $remote) {
            $verdict = 'act';
            $advice = 'The server is being called by a proxy ('.$remote.') and the visitor\'s address ('.$firstForwarded.') is in X-Forwarded-For, but the shop is not using it. Every visitor would count as one. In .env set TRUSTED_PROXIES=REMOTE_ADDR (trust whoever calls) or, safer, TRUSTED_PROXIES='.$remote.' then reload this page: "Address the shop uses" should become your own address.';
        } elseif ($realIp !== '' && $realIp !== $remote) {
            $verdict = 'act';
            $advice = 'The visitor\'s address ('.$realIp.') arrives in the '.$realHeader.' header, not X-Forwarded-For. In .env set CLIENT_IP_HEADER='.$realHeader.' and TRUSTED_PROXIES='.$remote.' then reload this page.';
        } else {
            $verdict = 'ok';
            $advice = 'No difference between the connection address and the forwarding headers.';
        }

        return [
            'remote' => $remote,
            'resolved' => $resolved,
            'headers' => $headers,
            'secure' => $request->isSecure(),
            'verdict' => $verdict,
            'advice' => $advice,
            'settings' => [
                'TRUSTED_PROXIES' => is_array(config('trustedproxy.proxies')) ? implode(', ', config('trustedproxy.proxies')) : (config('trustedproxy.proxies') ?? '(not set: forwarded headers are ignored)'),
                'CLIENT_IP_HEADER' => config('trustedproxy.client_ip_header') ?? '(not set)',
                'THROTTLE_BY_IP' => config('abuse.throttle_by_ip') ? 'on' : 'off',
                'HSTS_ENABLED' => config('abuse.hsts_enabled') ? 'on' : 'off',
                'ABUSE_REQUIRE_FORM_PROOF' => config('abuse.require_form_proof') ? 'on' : 'off',
            ],
        ];
    }
}
