<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TransactionalMail;
use App\Models\Customer;
use App\Models\SiteSetting;
use App\Support\CustomerLookup;
use App\Support\Email\TemplateDefinitions;
use App\Support\Email\TransactionalEmails;
use App\Support\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /** The same words whether or not there is an account, so this cannot be used to find out who has one. */
    public const SENT = 'If there is an account with those details, we have emailed a link to choose a new password. It works for one hour.';

    /** Emails to one address in an hour; any more are quietly not sent (the answer is the same). */
    private const PER_ADDRESS_PER_HOUR = 3;

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            // What the login form calls "Email or Phone".
            'email' => ['required', 'string', 'max:255'],
        ]);

        $customer = $this->find($data['email']);

        if ($customer !== null && filled($customer->email) && RateLimiter::attempt(
            'password-reset-to:'.sha1(mb_strtolower($customer->email)),
            self::PER_ADDRESS_PER_HOUR,
            fn () => true,
            3600,
        )) {
            $link = PasswordReset::url($customer);
            // The saved row, or null (the mail then uses its defaults): an unsaved model must not ride inside the closure.
            $site = SiteSetting::query()->first();

            // Sent after the response (there is no queue worker), and a mail problem is logged, never shown.
            TransactionalEmails::afterResponse(
                fn () => Mail::to($customer->email)->send(new TransactionalMail(
                    TemplateDefinitions::PASSWORD_RESET,
                    null,
                    $customer,
                    $site,
                    null,
                    [
                        'plain' => [
                            '{{customer_name}}' => filled($customer->name) ? trim($customer->name) : 'there',
                            '{{reset_link}}' => $link,
                            '{{reset_expires_in}}' => PasswordReset::expiresInWords(),
                        ],
                        'cta_url' => $link,
                    ],
                )),
                'Password reset email',
                ['customer_id' => $customer->id],
            );
        }

        return response()->json(['message' => self::SENT]);
    }

    /**
     * Whether a link is still good, so the page can show the form or a calm "this link has ended".
     */
    public function check(Request $request): JsonResponse
    {
        return response()->json(['valid' => PasswordReset::resolve((string) $request->query('token')) !== null]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:300'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed'],
        ], [
            'password.min' => 'Please choose a password of at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $customer = PasswordReset::resolve($data['token']);

        if ($customer === null) {
            throw ValidationException::withMessages([
                'token' => ['This link has ended or has already been used. Please ask for a new one.'],
            ]);
        }

        // Saving changes the password, which is also what makes this link (and any other) stop working.
        $customer->password = $data['password'];
        $customer->save();

        // Anyone signed in with the old password, on any device, is signed out.
        $customer->tokens()->delete();

        return response()->json(['message' => 'Your password is changed. You can log in with it now.']);
    }

    /**
     * The customer for an email address or a phone number. A phone number belongs to the account's own email, never to the
     * address typed.
     */
    private function find(string $login): ?Customer
    {
        return CustomerLookup::byLogin($login)->first(fn (Customer $customer): bool => filled($customer->email));
    }
}
