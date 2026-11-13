<?php

declare(strict_types=1);

namespace App\Http\Requests\BillPayment;

use App\Enums\BillPayment\BillerAmountType;
use App\Models\Biller;
use App\Support\AmountEntry;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * What the pay page asks while the customer fills it in: the price of this
 * bill from this wallet, before anything is submitted.
 *
 * The biller comes from the route, and only while it is payable (it, its
 * category and its provider are all switched on); anything else is a 404.
 * Nothing here is trusted beyond its shape: the wallet is looked up among the
 * customer's own and BillPaymentPricer decides every figure.
 *
 * It always answers in JSON, even to a request that did not ask for it, so a
 * refused quote never turns into a redirect the page's script cannot read.
 */
class QuoteBillRequest extends FormRequest
{
    private ?Biller $resolvedBiller = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'wallet_id'      => ['required', 'integer'],
            'biller_item_id' => ['nullable', 'integer'],
            'amount'         => ['nullable'],
        ];

        $biller = $this->billerOrNull();

        if ($biller === null) {
            return $rules;
        }

        if ($biller->amount_type === BillerAmountType::Fixed) {
            return array_merge($rules, [
                'biller_item_id' => ['required', 'integer'],
                // The package decides the price; BillPaymentPricer refuses an
                // amount that is not exactly it.
                'amount' => ['nullable', 'numeric'],
            ]);
        }

        return array_merge($rules, ['amount' => AmountEntry::rules((string) $biller->currency)]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'wallet_id.required'      => __('Choose the wallet to pay from.'),
            'wallet_id.integer'       => __('Choose one of your wallets.'),
            'biller_item_id.required' => __('Choose a package.'),
            'biller_item_id.integer'  => __('Choose one of the packages this biller offers.'),
            'amount.required'         => __('Enter the amount.'),
            'amount.numeric'          => __('Enter a valid amount.'),
            'amount.min'              => __('Enter an amount of at least :min.'),
        ];

        $biller = $this->billerOrNull();

        if ($biller === null) {
            return $messages;
        }

        $currency = strtoupper((string) $biller->currency);
        $decimals = Money::decimals($currency);

        // Worded here rather than by the validator, whose :decimal
        // placeholder reads "0-2".
        $messages['amount.decimal'] = $decimals === 0
            ? __(':currency amounts cannot have decimals.', ['currency' => $currency])
            : __(':currency amounts can have at most :decimals decimal places.', ['currency' => $currency, 'decimals' => $decimals]);

        return $messages;
    }

    /**
     * The biller named by the route, while a customer may pay it.
     *
     * @throws ModelNotFoundException
     */
    public function biller(): Biller
    {
        return $this->resolvedBiller ??= Biller::query()
            ->payable()
            ->with(['provider', 'category'])
            ->findOrFail((string) $this->route('biller'));
    }

    /**
     * The biller the rules are worked out for. Null only where a subclass
     * has no biller to check (a replayed payment).
     *
     * @throws ModelNotFoundException
     */
    protected function billerOrNull(): ?Biller
    {
        return $this->biller();
    }

    public function isFixed(): bool
    {
        return $this->biller()->amount_type === BillerAmountType::Fixed;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors()->toArray(),
        ], 422));
    }
}
