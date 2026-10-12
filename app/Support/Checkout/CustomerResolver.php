<?php

namespace App\Support\Checkout;

use App\Models\Customer;
use App\Support\Contact;
use App\Support\CustomerLookup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Which customer record an order belongs to.
 *
 * A guest checkout is typed by someone nobody has verified, so it never rewrites an existing customer: not their name,
 * their email (which is what a password reset trusts), their phone or their address. What the buyer typed is kept on the
 * order itself. The order is attached to the customer who already has that phone number, or a new customer is made.
 */
final class CustomerResolver
{
    /**
     * @param  array{name: string, phone: string, address: string, division: string, district: string, thana: string, email: ?string}  $details
     */
    public static function forOrder(array $details): Customer
    {
        $phone = Contact::phone($details['phone']) ?? $details['phone'];
        $email = Contact::email($details['email'] ?? null);

        $existing = CustomerLookup::byLogin($phone);

        if ($existing->isNotEmpty()) {
            // Two customers can share a number (guest orders); the one with the same email is the better match.
            return $existing->first(fn (Customer $customer): bool => $email !== null && $customer->email === $email) ?? $existing->first();
        }

        // An email that already belongs to another customer is not given to a second one: it stays on the order only.
        $storedEmail = $email !== null && ! Customer::query()->where('email', $email)->exists() ? $email : null;

        $attributes = [
            'name' => $details['name'],
            'phone' => $phone,
            'address' => $details['address'],
            'division' => $details['division'],
            'district' => $details['district'],
            'thana' => $details['thana'],
            'password' => Str::random(32),
        ];

        try {
            return Customer::query()->create($attributes + ['email' => $storedEmail]);
        } catch (UniqueConstraintViolationException) {
            // Someone took that email a moment ago.
            return Customer::query()->create($attributes + ['email' => null]);
        }
    }
}
