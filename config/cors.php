<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Which websites' scripts may read this API from a browser. Only the shop's own storefront needs to. The storefront is a
| static site on another domain, so without this its calls would be refused; with the framework default (every origin)
| any website could call the API from a visitor's browser.
|
| CORS_ALLOWED_ORIGINS in .env replaces the list (comma separated, full origins like https://anaizanest.com).
*/

$configured = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))));

$frontend = rtrim((string) env('FRONTEND_URL', ''), '/');

$origins = $configured !== [] ? $configured : array_values(array_unique(array_filter([
    $frontend,
    'https://anaizanest.com',
    'https://www.anaizanest.com',
    'https://new.anaizanest.com',
    // Local development only.
    env('APP_ENV') === 'local' ? 'http://localhost:3000' : null,
    env('APP_ENV') === 'local' ? 'http://127.0.0.1:3000' : null,
])));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
