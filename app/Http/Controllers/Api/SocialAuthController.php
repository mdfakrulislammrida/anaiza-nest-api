<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Support\Email\TransactionalEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialAuthController extends Controller
{
    public function redirectToGoogle(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'google') ?? Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'google') ?? $this->handleCallback('google');
    }

    public function redirectToFacebook(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'facebook') ?? Socialite::driver('facebook')->stateless()->redirect();
    }

    public function handleFacebookCallback(Request $request): RedirectResponse|JsonResponse
    {
        return $this->unavailable($request, 'facebook') ?? $this->handleCallback('facebook');
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

    private function handleCallback(string $provider): RedirectResponse
    {
        try {
            /** @var SocialiteUser $socialUser */
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            Log::error("Social login via {$provider} failed.", ['error' => $e->getMessage()]);

            return redirect()->away(
                config('app.frontend_url').'/auth/callback?error=social_login_failed'
            );
        }

        if (! $socialUser->getEmail()) {
            return redirect()->away(
                config('app.frontend_url').'/auth/callback?error=no_email_from_provider'
            );
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

        $token = $customer->createToken('storefront')->plainTextToken;

        return redirect()->away(
            config('app.frontend_url').'/auth/callback?token='.urlencode($token)
        );
    }
}
