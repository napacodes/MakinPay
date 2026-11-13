<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MassPayment;

use App\Http\Requests\MassPayment\StoreMassPayoutRequest as WebStoreMassPayoutRequest;
use App\Services\MassPayment\MassPaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The app builds a mass payout draft: one of the customer's wallets (by
 * uuid), one withdraw method (by id) and either a multipart `file` or a JSON
 * `rows` list, each row's account details under `account`.
 *
 * The web form's rules, with the app's two differences, as for wallet
 * batches: the source is read from the request (a file makes it a CSV draft),
 * and each row may name its `currency`, which the draft checks against the
 * wallet's. There is no rebuild here, so `replaces` is ignored.
 *
 * Amounts and account details must arrive as strings: a JSON number reaches
 * PHP as a float, and an account number held as a float has already lost
 * its leading zeros.
 */
class StoreMassPayoutRequest extends WebStoreMassPayoutRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['replaces']);

        return array_merge($rules, [
            'rows.*.currency' => ['nullable', 'string', 'max:10'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'rows.*.currency.string' => __('Each currency must be a currency code.'),
            'rows.*.currency.max'    => __('Each currency must be a currency code.'),
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source' => $this->hasFile('file') ? MassPaymentService::SOURCE_CSV : MassPaymentService::SOURCE_MANUAL,
        ]);
    }

    /**
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
