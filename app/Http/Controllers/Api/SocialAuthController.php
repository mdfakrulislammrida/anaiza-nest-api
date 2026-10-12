<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Support\Email\TransactionalEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * "Continue with Google / Facebook".
 *
 * The provider flow is stateless (the API has no session), so the protection against someone else's sign-in being completed
 * in your browser (login CSRF) is built here: a random value is kept in a short-lived cookie on this browser and its signed
 * form travels as the OAuth "state"; the callback only proceeds when the two match.
 *
 * The callback never puts the sign-in token in the address. It hands the browser a one-time code that lives five minutes and
 * works once, and the storefront exchanges it for the token with a POST.
 */
class SocialAuthController extends Controller
{
    private const STATE_COOKIE = 'anaiza_oauth_state';

    private const CODE_MINUTES = 5;

    public function redirectToGoogle(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'google') ?? $this->begin('google');
    }

    public function handleGoogleCallback(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'google') ?? $this->handleCallback($request, 'google');
    }

    public function redirectToFacebook(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'facebook') ?? $this->begin('facebook');
    }

    public function handleFacebookCallback(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'facebook') ?? $this->handleCallback($request, 'facebook');
    }

    /**
     * Trades the one-time code from the callback for the sign-in token.
     */
    public function exchange(Request $request): JsonResponse
    {
        $code = (string) $request->input('code');

        $customerId = $code === '' ? null : Cache::pull(self::codeKey($code));
        $customer = $customerId ? Customer::query()->find($customerId) : null;

        if ($customer === null) {
            return response()->json(['message' => 'That sign-in link has ended. Please try again.'], 422);
        }

        return response()->json([
            'token' => $customer->createToken('storefront')->plainTextToken,
            'customer' => $customer,
        ]);
    }

    /**
     * A provider the admin has switched off (or that has no credentials) does nothing. A browser is taken back to the
     * storefront's sign-in page with a calm message; anything asking for JSON gets a plain 403. Customers who signed up
     * with it keep their accounts and can still sign in with their email and password.
     */
    private function unavailable(Request $request, string $provider): RedirectResponse|JsonResponse|null
    {
        if (IntegrationSetting::socialLoginEnabled($provider)) {
            return null;
        }

        $label = IntegrationSetting::SOCIAL_PROVIDERS[$provider]['label'];

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "Signing in with {$label} is not available just now. Please sign in with your email and password.",
            ], 403);
        }

        return redirect()->away(
            config('app.frontend_url').'/auth/callback?error=social_login_unavailable&provider='.urlencode($provider)
        );
    }

    private function begin(string $provider): RedirectResponse
    {
        $nonce = Str::random(40);

        return Socialite::driver($provider)
            ->stateless()
            ->with(['state' => self::stateFor($nonce)])
            ->redirect()
            ->withCookie(Cookie::make(self::STATE_COOKIE, $nonce, 10, '/', null, request()->isSecure(), true, false, 'lax'));
    }

    private function handleCallback(Request $request, string $provider): RedirectResponse
    {
        $nonce = (string) $request->cookie(self::STATE_COOKIE);
        $state = (string) $request->query('state');
        $forget = Cookie::forget(self::STATE_COOKIE);

        // The answer must come back to the browser that asked, carrying the state made for it.
        if ($nonce === '' || $state === '' || ! hash_equals(self::stateFor($nonce), $state)) {
            return $this->failed('social_login_failed')->withCookie($forget);
        }

        try {
            /** @var SocialiteUser $socialUser */
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            Log::error("Social login via {$provider} failed.", ['error' => $e->getMessage()]);

            return $this->failed('social_login_failed')->withCookie($forget);
        }

        if (! $socialUser->getEmail()) {
            return $this->failed('no_email_from_provider')->withCookie($forget);
        }

        $customer = Customer::firstOrCreate(
            ['email' => $socialUser->getEmail()],
            [
                'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'Customer',
                'password' => Str::random(32),
            ]
        );

        // A first sign-in creates the account, which is when the welcome goes out; coming back sends nothing.
        if ($customer->wasRecentlyCreated) {
            TransactionalEmails::welcome($customer);
        }

        $code = Str::random(48);
        Cache::put(self::codeKey($code), $customer->id, now()->addMinutes(self::CODE_MINUTES));

        return redirect()->away(config('app.frontend_url').'/auth/callback?code='.urlencode($code))->withCookie($forget);
    }

    private function failed(string $error): RedirectResponse
    {
        return redirect()->away(config('app.frontend_url').'/auth/callback?error='.$error);
    }

    private static function stateFor(string $nonce): string
    {
        return hash_hmac('sha256', 'oauth-state:'.$nonce, (string) config('app.key'));
    }

    private static function codeKey(string $code): string
    {
        return 'social-code:'.hash('sha256', $code);
    }
}
