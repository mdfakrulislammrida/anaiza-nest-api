<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A privacy-friendly bot trap for the public forms: no captcha, no third party, nothing the visitor sees.
 *
 *  - hp_field is a field people never see or fill in (hidden, no tab stop). A bot that fills every field fills it.
 *  - form_rendered_at is the moment (milliseconds) the form appeared. A submission faster than a person could type is a script.
 *
 * Usage: ->middleware('form-proof:order,reject') or ('form-proof:newsletter,pretend'). "reject" answers with a calm 422;
 * "pretend" answers success without storing anything, so a bot learns nothing. A request with neither field passes, unless
 * ABUSE_REQUIRE_FORM_PROOF is on.
 */
class RejectFormBots
{
    public const HONEYPOT = 'hp_field';

    public const RENDERED_AT = 'form_rendered_at';

    public function handle(Request $request, Closure $next, string $kind = 'form', string $mode = 'reject'): Response
    {
        if (! $this->looksHuman($request, $kind)) {
            return $mode === 'pretend'
                ? response()->json(['message' => 'Thank you.'], 201)
                : response()->json(['message' => 'Something went wrong with that form. Please reload the page and try again, or message us on WhatsApp and we will help.'], 422);
        }

        return $next($request);
    }

    private function looksHuman(Request $request, string $kind): bool
    {
        if (filled($request->input(self::HONEYPOT))) {
            return false;
        }

        $renderedAt = $request->input(self::RENDERED_AT);

        if (! is_numeric($renderedAt)) {
            return ! config('abuse.require_form_proof');
        }

        $elapsedSeconds = (microtime(true) * 1000 - (float) $renderedAt) / 1000;
        $minimum = (int) config("abuse.min_seconds.{$kind}", 3);

        // Only a time between "just now" and the minimum counts as too fast. A clock that is wrong in either direction (a phone
        // set to the wrong time) is never held against the customer.
        return ! ($elapsedSeconds >= 0 && $elapsedSeconds < $minimum);
    }
}
