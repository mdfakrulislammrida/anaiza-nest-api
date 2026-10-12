<?php

/*
|--------------------------------------------------------------------------
| Abuse protection and response headers
|--------------------------------------------------------------------------
|
| Everything here can be changed from .env without a code change.
*/

return [
    // Strict-Transport-Security, sent only over HTTPS. Leave off until the site has run on HTTPS only for a while: a browser
    // remembers it for the whole max-age. 15552000 seconds is 180 days.
    'hsts_enabled' => (bool) env('HSTS_ENABLED', false),
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 15552000),

    // Public forms carry a hidden field no person fills in (the honeypot) and the moment the form was shown (the time trap).
    // A submission that fills the field, or arrives faster than a person could type, is turned away. With this on, a
    // submission that carries neither is refused too: turn it on once the storefront that sends them is live.
    'require_form_proof' => (bool) env('ABUSE_REQUIRE_FORM_PROOF', false),

    // Fewest seconds a form can be filled in, by kind of form.
    'min_seconds' => [
        'order' => (int) env('ABUSE_MIN_SECONDS_ORDER', 4),
        'form' => (int) env('ABUSE_MIN_SECONDS_FORM', 3),
        'newsletter' => (int) env('ABUSE_MIN_SECONDS_NEWSLETTER', 1),
    ],

    // Whether limits are counted per visitor address. Turn off ONLY if Admin > Network check shows every visitor arriving as
    // one address you cannot separate (then limits fall back to phone number and email only).
    'throttle_by_ip' => (bool) env('THROTTLE_BY_IP', true),
];
