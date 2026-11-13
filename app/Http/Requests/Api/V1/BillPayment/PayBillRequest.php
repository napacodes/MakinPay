<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\BillPayment;

use App\Models\Biller;
use App\Models\BillPayment;
use App\Services\Api\IdempotencyGuard;
use App\Services\BillPayment\SavedBillBook;
use App\Support\AmountEntry;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The app pays one bill.
 *
 * The quote's fields, plus what only a payment carries: the account the
 * biller knows the customer by, the wallet PIN, and the total the customer
 * was shown. The idempotency key is the `Idempotency-Key` header, which the
 * route's middleware requires, so it is not a field here. Every field is
 * still only a claim; BillPaymentService re-reads the wallet, biller and
 * package and prices the payment itself.
 *
 * A request whose key already made one of this customer's payments is a
 * replay: its biller is not resolved and only the shape of its fields is
 * checked, so a retry still gets its payment back after the biller, its
 * category or its provider was switched off. BillPaymentService returns that
 * payment, or answers 409 when the details differ.
 *
 * The messages name the account the way this biller does ("Meter number",
 * "Smartcard number"), because that is the word printed on the customer's
 * bill.
 */
class PayBillRequest extends QuoteBillRequest
{
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
            'expected_total.numeric'   => __('Get a new quote to see the current total.'),
            'expected_total.min'       => __('Get a new quote to see the current total.'),
            'expected_total.decimal'   => __('Get a new quote to see the current total.'),
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
     * The key BillPaymentService records the payment under, 'api:' and the
     * sha256 of the Idempotency-Key header, or null without one.
     */
    public function idempotencyKey(): ?string
    {
        $key = app(IdempotencyGuard::class)->keyFrom($this);

        return $key === '' ? null : 'api:'.hash('sha256', $key);
    }

    /** Whether this request's key already made one of this customer's payments. */
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
     * replay the `biller_id` sent, which the service compares with the
     * payment the key made.
     */
    public function billerId(): int
    {
        return (int) ($this->billerOrNull()?->id ?? $this->input('biller_id'));
    }

    protected function billerOrNull(): ?Biller
    {
        return $this->isReplay() ? null : parent::billerOrNull();
    }

    private function customerNumberLabel(): string
    {
        $label = trim((string) $this->billerOrNull()?->customer_number_label);

        return $label === '' ? __('account number') : $label;
    }
}
