<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\ResolveClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Which address the shop counts a visitor as. Nothing is trusted unless it is configured, so a visitor who reaches the origin
 * directly cannot pick their own address by sending a header.
 */
class ClientAddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(ResolveClientIp::class)->get('/whoami-test', fn (Request $request) => response()->json(['ip' => $request->ip()]));
    }

    private function whoami(array $server, array $headers = [])
    {
        $prefixed = [];
        foreach ($headers as $name => $value) {
            $prefixed['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('GET', '/whoami-test', [], [], [], array_merge($server, $prefixed))->json('ip');
    }

    public function test_by_default_forwarded_headers_are_ignored(): void
    {
        config(['trustedproxy.proxies' => null, 'trustedproxy.client_ip_header' => null]);

        $ip = $this->whoami(['REMOTE_ADDR' => '203.0.113.9'], ['X-Forwarded-For' => '198.51.100.1', 'X-Real-IP' => '198.51.100.2']);

        $this->assertSame('203.0.113.9', $ip, 'a visitor cannot choose their own address');
    }

    public function test_a_named_header_is_used_only_when_the_caller_is_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => ['203.0.113.0/24'], 'trustedproxy.client_ip_header' => 'X-Real-IP']);

        $this->assertSame('198.51.100.7', $this->whoami(['REMOTE_ADDR' => '203.0.113.9'], ['X-Real-IP' => '198.51.100.7']));

        // The same header from an address that is not the proxy is ignored.
        $this->assertSame('192.0.2.50', $this->whoami(['REMOTE_ADDR' => '192.0.2.50'], ['X-Real-IP' => '198.51.100.7']));
    }

    public function test_the_header_must_hold_a_real_address_and_the_first_entry_of_a_list_wins(): void
    {
        config(['trustedproxy.proxies' => '*', 'trustedproxy.client_ip_header' => 'CF-Connecting-IP']);

        $this->assertSame('203.0.113.9', $this->whoami(['REMOTE_ADDR' => '203.0.113.9'], ['CF-Connecting-IP' => 'not-an-address']));
        $this->assertSame('198.51.100.7', $this->whoami(['REMOTE_ADDR' => '203.0.113.9'], ['CF-Connecting-IP' => '198.51.100.7, 10.0.0.1']));
    }

    public function test_remote_addr_in_the_list_means_trust_whoever_is_calling(): void
    {
        config(['trustedproxy.proxies' => ['REMOTE_ADDR'], 'trustedproxy.client_ip_header' => 'True-Client-IP']);

        $this->assertSame('198.51.100.7', $this->whoami(['REMOTE_ADDR' => '203.0.113.9'], ['True-Client-IP' => '198.51.100.7']));
    }

    public function test_the_proxy_list_is_read_from_the_environment_format(): void
    {
        $config = function (string $proxies, string $header) {
            putenv("TRUSTED_PROXIES={$proxies}");
            putenv("CLIENT_IP_HEADER={$header}");
            try {
                return require base_path('config/trustedproxy.php');
            } finally {
                putenv('TRUSTED_PROXIES');
                putenv('CLIENT_IP_HEADER');
            }
        };

        $this->assertSame(['proxies' => null, 'client_ip_header' => null], $config('', ''));
        $this->assertSame('*', $config('*', '')['proxies']);
        $this->assertSame(['10.0.0.1', '203.0.113.0/24'], $config(' 10.0.0.1 , 203.0.113.0/24 ', 'X-Real-IP')['proxies']);
        $this->assertSame('X-Real-IP', $config('10.0.0.1', ' X-Real-IP ')['client_ip_header']);
    }

    public function test_laravels_own_proxy_handling_reads_x_forwarded_for_from_a_trusted_caller(): void
    {
        // Straight through the framework's TrustProxies, with no help from ResolveClientIp.
        Route::get('/whoami-plain', fn (Request $request) => response()->json(['ip' => $request->ip()]));
        config(['trustedproxy.proxies' => ['203.0.113.9']]);

        $trusted = $this->call('GET', '/whoami-plain', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
        $this->assertSame('198.51.100.1', $trusted->json('ip'));

        $untrusted = $this->call('GET', '/whoami-plain', [], [], [], ['REMOTE_ADDR' => '192.0.2.50', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
        $this->assertSame('192.0.2.50', $untrusted->json('ip'));
    }
}
