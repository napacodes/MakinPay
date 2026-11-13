<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\BillPayment;

use App\Enums\BillPayment\BillerAmountType;
use App\Models\Biller;
use App\Support\AmountEntry;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a bill would cost from one of the customer's wallets, asked by the app
 * before anything is submitted.
 *
 * The web pay page names the biller in its route; the app names it in
 * `biller_id`, so the rules are the web form's, worked out once the biller is
 * known. A biller that is not payable (it, its category or its provider is
 * switched off) is a 404, like the web page. The wallet travels as its uuid,
 * the id every wallet route in this API speaks, and is looked up among the
 * caller's own by the controller.
 *
 * A refusal is the API's ordinary 422 VALIDATION_FAILED envelope, with the
 * field it belongs to.
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
            'biller_id'      => ['required', 'integer', 'min:1'],
            'wallet_id'      => ['required', 'string', 'max:64'],
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
            'biller_id.required'      => __('Choose the biller to pay.'),
            'biller_id.integer'       => __('Choose one of the billers on offer.'),
            'biller_id.min'           => __('Choose one of the billers on offer.'),
            'wallet_id.required'      => __('Choose the wallet to pay from.'),
            'wallet_id.string'        => __('Choose one of your wallets.'),
            'wallet_id.max'           => __('Choose one of your wallets.'),
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
     * The biller named by `biller_id`, while a customer may pay it.
     *
     * @throws ModelNotFoundException the id names no payable biller
     */
    public function biller(): Biller
    {
        return $this->billerOrNull() ?? throw (new ModelNotFoundException)->setModel(Biller::class);
    }

    public function isFixed(): bool
    {
        return $this->biller()->amount_type === BillerAmountType::Fixed;
    }

    /**
     * Null while `biller_id` is not a positive whole number, so the rules can
     * still refuse it on its own field. A well-formed id that names no payable
     * biller is a 404.
     *
     * @throws ModelNotFoundException
     */
    protected function billerOrNull(): ?Biller
    {
        if ($this->resolvedBiller !== null) {
            return $this->resolvedBiller;
        }

        $billerId = filter_var($this->input('biller_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($billerId === false) {
            return null;
        }

        return $this->resolvedBiller = Biller::query()
            ->payable()
            ->with(['provider', 'category'])
            ->findOrFail($billerId);
    }
}
