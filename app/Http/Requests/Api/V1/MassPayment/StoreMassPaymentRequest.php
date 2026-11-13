<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MassPayment;

use App\Http\Requests\MassPayment\StoreMassPaymentRequest as WebStoreMassPaymentRequest;
use App\Services\MassPayment\MassPaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The app builds a mass payment draft: one of the customer's wallets (by
 * uuid) and either a multipart `file` or a JSON `rows` list.
 *
 * The web form's rules, with two differences that belong to the app. There
 * is no `source` field: a request carrying an uploaded file is a CSV draft
 * and anything else is the rows list, so the app cannot send one and label
 * it the other. And each row may name its `currency`, which the draft
 * service checks against the wallet's, the way the CSV's optional column is.
 *
 * Amounts must arrive as strings. A JSON number reaches PHP as a float, and
 * a float has already lost the figure the customer typed before any rule
 * sees it.
 *
 * A refusal is the ordinary VALIDATION_FAILED envelope, never the web form's
 * redirect.
 */
class StoreMassPaymentRequest extends WebStoreMassPaymentRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
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

    /**
     * The source is read from the request itself: a file makes it a CSV
     * draft, anything else the rows list.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

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
