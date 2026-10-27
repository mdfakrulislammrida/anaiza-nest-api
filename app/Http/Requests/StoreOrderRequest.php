<?php

namespace App\Http\Requests;

use App\Models\BlockedContact;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\SiteSetting;
use App\Support\Contact;
use App\Support\MatchData;
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
            // Tracking data never fails an order: a value of the wrong shape is dropped here, before it is checked.
            'fbp' => MatchData::fbp($this->input('fbp')),
            'fbc' => MatchData::fbc($this->input('fbc')),
            'external_id' => MatchData::externalId($this->input('external_id')),
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

                // Only worth looking at once the fields themselves are valid.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $phone = Contact::phone((string) $this->input('customer_phone'));

                // Numbers, emails and address fragments the shop has chosen not to take online orders from. The buyer is told
                // where to go, not why.
                $block = BlockedContact::matching([
                    'phone' => $phone,
                    'email' => $this->input('customer_email'),
                    'address' => $this->input('customer_address'),
                ]);

                if ($block !== null) {
                    $validator->errors()->add('customer_phone', 'We are not able to take this order online just now. Please message us on WhatsApp and we will help you place it.');

                    return;
                }

                // One number, one day: a genuine buyer does not need more, and a prank or a loop does.
                $limit = (int) (SiteSetting::query()->value('max_orders_per_phone_per_day') ?? 5);

                if ($limit > 0 && $phone !== null) {
                    $today = Order::query()
                        ->where('ship_phone', $phone)
                        ->where('status', '!=', 'cancelled')
                        ->where('created_at', '>=', now()->subDay())
                        ->count();

                    if ($today >= $limit) {
                        $validator->errors()->add('customer_phone', 'We already have a few orders from this number today. If you would like to order more, please message us on WhatsApp and we will help.');
                    }
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
            'items.max' => 'That is a lot of pieces for one order. Please send us a corporate enquiry and we will help.',
            'items.*.quantity.max' => 'That is more of one piece than we can add to a single order. Please send us a corporate enquiry and we will help.',
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
            // A mobile number we can call: 01712345678, +8801712345678 and "01712 345678" are all fine.
            'customer_phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                if (Contact::phone((string) $value) === null) {
                    $fail('Please enter a Bangladeshi mobile number, like 01712 345678, so our courier can reach you.');
                }
            }],
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
            // The ad click ids, kept with the order and passed to the platforms' server-side events.
            'fbclid' => ['nullable', 'string', 'max:255'],
            'gclid' => ['nullable', 'string', 'max:255'],
            'ttclid' => ['nullable', 'string', 'max:255'],
            // Meta's browser cookies and a hashed external id, for matching the shop's own Purchase to the browser's (see MatchData). Shape-checked in prepareForValidation.
            'fbp' => ['nullable', 'string', 'max:100'],
            'fbc' => ['nullable', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'size:64'],
            // The landing page the order was placed from (its slug). Never fails an order: an unknown one is ignored.
            'landing_page' => ['nullable', 'string', 'max:160'],
            // A coupon code (checked again here, on the server, whatever the browser showed), the browser's checkout session,
            // and the token from a cart recovery link, which marks the order as coming from it.
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'checkout_session' => ['nullable', 'string', 'max:64'],
            'recovery_token' => ['nullable', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ];
    }
}
