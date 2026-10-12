<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| The shop sits behind Hostinger's CDN ("Server: hcdn"). Whether PHP sees the visitor's real address or the CDN's depends
| on how that edge hands the request over, and that cannot be read from code. So nothing is trusted by default: the address
| PHP is given (REMOTE_ADDR) is used as it is, and forwarded headers are ignored.
|
| Settle it with Admin > Network check, which shows exactly what PHP receives, then set one of these in .env:
|
|   TRUSTED_PROXIES=*                      trust X-Forwarded-For from whoever connects (only safe if the origin can be
|                                          reached through the CDN alone)
|   TRUSTED_PROXIES=203.0.113.7,10.0.0.0/8 trust it only from these addresses or ranges (REMOTE_ADDR is also accepted,
|                                          meaning "whoever is calling")
|   CLIENT_IP_HEADER=X-Real-IP             read the visitor's address from this header instead (with TRUSTED_PROXIES set to
|                                          the CDN's addresses): CF-Connecting-IP, True-Client-IP or X-Real-IP
|
| Laravel's TrustProxies middleware reads `proxies` below.
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => $proxies === '' ? null : ($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies))))),

    // An alternative to X-Forwarded-For: the one header the CDN sets to the visitor's own address.
    'client_ip_header' => ($header = trim((string) env('CLIENT_IP_HEADER', ''))) === '' ? null : $header,
];
