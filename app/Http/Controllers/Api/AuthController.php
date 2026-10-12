<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginCustomerRequest;
use App\Http\Requests\RegisterCustomerRequest;
use App\Models\Customer;
use App\Support\CustomerLookup;
use App\Support\Email\TransactionalEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterCustomerRequest $request)
    {
        $customer = Customer::create($request->validated());

        TransactionalEmails::welcome($customer);

        $token = $customer->createToken('storefront')->plainTextToken;

        return response()->json([
            'customer' => $customer,
            'token' => $token,
        ], 201);
    }

    public function login(LoginCustomerRequest $request)
    {
        // An email address or a phone number, however it was typed. A phone number can belong to more than one customer (a
        // guest order creates one), so the password decides which: each is tried, and the first it opens is the account.
        $customer = CustomerLookup::byLogin($request->string('email')->toString())
            ->first(fn (Customer $candidate): bool => Hash::check($request->string('password'), $candidate->password));

        if ($customer === null) {
            // The same words for an unknown email, an unknown phone and a wrong password.
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $customer->createToken('storefront')->plainTextToken;

        return response()->json([
            'customer' => $customer,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
