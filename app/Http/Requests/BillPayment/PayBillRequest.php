<?php

declare(strict_types=1);

namespace App\Http\Requests\BillPayment;

use App\Models\Biller;
use App\Models\BillPayment;
use App\Services\BillPayment\SavedBillBook;
use App\Support\AmountEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * A customer pays one bill from the web pay page.
 *
 * The quote's fields, plus what only a payment carries: the account the
 * biller knows the customer by, the wallet PIN, the idempotency key the page
 * rendered once, and the total the customer was shown. Every field is still
 * only a claim; BillPaymentService re-reads the wallet, biller and package and
 * prices the payment itself.
 *
 * A form whose idempotency key already made one of this customer's payments
 * is a replay: its biller is not resolved and only the shape of its fields is
 * checked, so a resubmit still lands on its receipt after the biller, its
 * category or its provider was switched off. BillPaymentService returns that
 * payment, or refuses the form when its details differ.
 *
 * The messages name the account the way this biller does ("Meter number",
 * "Smartcard number"), because that is the word printed on the customer's
 * bill.
 *
 * A refused form goes back with what was typed, except the PIN: the session
 * would otherwise hold it as plain old input until the next request.
 */
class PayBillRequest extends QuoteBillRequest
{
    /** Never flashed back as old input. */
    public const NEVER_FLASHED = ['wallet_pin'];

    private ?bool $isReplay = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $biller = $this->billerOrNull();

        return array_merge(parent::rules(), [
            'customer_number' => ['required', 'string', 'max:64'],
            'wallet_pin'      => ['nullable', 'string'],
            'save'            => ['nullable', 'boolean'],
            'save_name'       => ['nullable', 'string', 'max:'.SavedBillBook::NICKNAME_MAX],
            'idempotency_key' => ['required', 'uuid'],
            'expected_total'  => $biller === null
                ? ['nullable']
                : AmountEntry::rules((string) $biller->currency, required: false),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $label = $this->customerNumberLabel();

        return array_merge(parent::messages(), [
            'customer_number.required' => __('Enter the :label.', ['label' => $label]),
            'customer_number.string'   => __('Enter a valid :label.', ['label' => $label]),
            'customer_number.max'      => __('The :label may not be longer than :max characters.', ['label' => $label]),
            'idempotency_key.required' => __('This form has expired. Reload the page and try again.'),
            'idempotency_key.uuid'     => __('This form has expired. Reload the page and try again.'),
            'expected_total.numeric'   => __('Reload the page to see the current total.'),
            'expected_total.min'       => __('Reload the page to see the current total.'),
            'expected_total.decimal'   => __('Reload the page to see the current total.'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['customer_number' => $this->customerNumberLabel()];
    }

    /**
     * The key BillPaymentService records the payment under, 'web:' and the
     * form's uuid, or null while the form carries no valid key.
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->input('idempotency_key');

        return is_string($key) && Str::isUuid($key) ? 'web:'.$key : null;
    }

    /** Whether this form's key already made one of this customer's payments. */
    public function isReplay(): bool
    {
        if ($this->isReplay !== null) {
            return $this->isReplay;
        }

        $key  = $this->idempotencyKey();
        $user = $this->user();

        return $this->isReplay = $key !== null && $user !== null && BillPayment::query()
            ->where('user_id', $user->id)
            ->where('client_idempotency_key', $key)
            ->exists();
    }

    /**
     * The biller id the submission carries: the payable biller's, or for a
     * replay the one the route names, which the service compares with the
     * payment the key made.
     */
    public function billerId(): int
    {
        return (int) ($this->billerOrNull()?->id ?? $this->route('biller'));
    }

    protected function billerOrNull(): ?Biller
    {
        return $this->isReplay() ? null : parent::billerOrNull();
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->to($this->getRedirectUrl())
                ->withInput($this->except(self::NEVER_FLASHED))
                ->withErrors($validator->errors(), $this->errorBag)
        );
    }

    private function customerNumberLabel(): string
    {
        $label = trim((string) $this->billerOrNull()?->customer_number_label);

        return $label === '' ? __('account number') : $label;
    }
}
