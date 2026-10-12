<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Finds customers from what someone types into "Email or Phone" (login, password reset).
 *
 * An email is matched whatever its case. A phone number is matched as a number, not as text: 01711234567,
 * +8801711234567, 8801711234567 and "+880 1711-234567" are the same customer, however each was typed when the
 * account or an order was made. Phone numbers are not unique (a guest order creates a customer by phone), so more than one
 * customer can come back; a login tries each of them with the password given.
 */
final class CustomerLookup
{
    /**
     * @return Collection<int, Customer>
     */
    public static function byLogin(?string $login): Collection
    {
        $login = trim((string) $login);

        if ($login === '') {
            return collect();
        }

        $email = Contact::email($login);

        if ($email !== null) {
            return Customer::query()
                ->where(fn ($q) => $q->where('email', $email)->orWhereRaw('lower(email) = ?', [$email]))
                ->orderBy('id')
                ->get();
        }

        $phone = Contact::phone($login);

        if ($phone === null) {
            return collect();
        }

        // The usual spellings first (an exact, indexable match).
        $found = Customer::query()
            ->whereIn('phone', [$phone, '+88'.$phone, '88'.$phone, '+880'.substr($phone, 1), '880'.substr($phone, 1)])
            ->orderBy('id')
            ->get();

        if ($found->isNotEmpty()) {
            return $found;
        }

        // Anything else typed with spaces or dashes: narrowed by the last ten digits, then compared as numbers.
        return Customer::query()
            ->where('phone', 'like', '%'.substr($phone, 1, 3).'%')
            ->get()
            ->filter(fn (Customer $customer): bool => Contact::samePhone($customer->phone, $phone))
            ->sortBy('id')
            ->values();
    }
}
