<?php

namespace App\Http\Requests;

use App\Models\PaymentSetting;
use App\Support\WalletPayments;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The number and transaction ID are tidied before they are checked: spaces, dashes and +88 are
     * dropped from the number, the ID is upper-cased (so one ID is one ID, however it was typed), and
     * a cash on delivery order carries neither.
     */
    protected function prepareForValidation(): void
    {
        $wallet = WalletPayments::isWallet($this->input('payment_method'));

        $this->merge([
            'payment_sender_number' => $wallet ? WalletPayments::normalizeNumber($this->input('payment_sender_number')) : null,
            'payment_trx_id' => $wallet ? WalletPayments::normalizeTrxId($this->input('payment_trx_id')) : null,
        ]);
    }

    /**
     * A wallet can only be used when the shop has published a number to pay to.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $method = $this->input('payment_method');

                if (WalletPayments::isWallet($method) && PaymentSetting::query()->first()?->numberFor($method) === null) {
                    $validator->errors()->add('payment_method', WalletPayments::LABELS[$method].' is not available just now. Please choose another way to pay.');
                }
            },
        ];
    }

    /**
     * Error wording, in the shop's voice.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_sender_number.required_if' => 'Please enter the number you paid from.',
            'payment_sender_number.regex' => 'That does not look like a Bangladeshi mobile number. It has 11 digits and starts with 01.',
            'payment_trx_id.required_if' => 'Please enter the transaction ID from your payment message.',
            'payment_trx_id.regex' => 'A transaction ID is 6 to 20 letters and digits, with no spaces or symbols.',
            'payment_trx_id.unique' => 'This transaction ID has already been used on another order. Please check it, or contact us.',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_address' => ['required', 'string', 'max:500'],
            'division' => ['required', 'string', 'max:60'],
            'district' => ['required', 'string', 'max:60'],
            'thana' => ['required', 'string', 'max:60'],
            'payment_method' => ['required', 'in:cod,bkash,nagad,rocket'],
            'payment_sender_number' => ['nullable', 'required_if:payment_method,bkash,nagad,rocket', 'regex:/^01[3-9]\d{8}$/'],
            'payment_trx_id' => ['nullable', 'required_if:payment_method,bkash,nagad,rocket', 'regex:/^[A-Za-z0-9]{6,20}$/', Rule::unique('orders', 'payment_trx_id')],
            'gift_note' => ['nullable', 'string', 'max:1000'],
            'is_gift' => ['nullable', 'boolean'],
            // Whether the visitor allowed marketing cookies when they ordered (see Cookie consent).
            'marketing_consent' => ['nullable', 'boolean'],
            // Counted in characters, so Bangla text gets the same 200 as English.
            'gift_message' => ['nullable', 'string', 'max:200'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ];
    }
}
