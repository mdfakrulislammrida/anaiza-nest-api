<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialAuthController extends Controller
{
    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        return $this->handleCallback('google');
    }

    public function redirectToFacebook(): RedirectResponse
    {
        return Socialite::driver('facebook')->stateless()->redirect();
    }

    public function handleFacebookCallback(): RedirectResponse
    {
        return $this->handleCallback('facebook');
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

        $token = $customer->createToken('storefront')->plainTextToken;

        return redirect()->away(
            config('app.frontend_url').'/auth/callback?token='.urlencode($token)
        );
    }
}
