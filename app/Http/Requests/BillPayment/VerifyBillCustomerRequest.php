<?php

declare(strict_types=1);

namespace App\Http\Requests\BillPayment;

use App\Models\Biller;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * What the pay page asks when the customer leaves the account number field:
 * does the biller's provider know this account, and whose is it?
 *
 * The biller comes from the route, and only while it is payable; anything
 * else is a 404. Only the shape is checked here: BillerRules decides whether
 * the number is one the biller accepts, and BillCustomerVerifier asks the
 * provider. Nothing is written and nothing is reserved.
 *
 * It always answers in JSON, so a refusal never turns into a redirect the
 * page's script cannot read.
 */
class VerifyBillCustomerRequest extends FormRequest
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
        return [
            'customer_number' => ['required', 'string', 'max:64'],
            'biller_item_id'  => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $label = $this->customerNumberLabel();

        return [
            'customer_number.required' => __('Enter the :label.', ['label' => $label]),
            'customer_number.string'   => __('Enter a valid :label.', ['label' => $label]),
            'customer_number.max'      => __('The :label may not be longer than :max characters.', ['label' => $label]),
            'biller_item_id.integer'   => __('Choose one of the packages this biller offers.'),
        ];
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

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors()->toArray(),
        ], 422));
    }

    private function customerNumberLabel(): string
    {
        $label = trim((string) $this->biller()->customer_number_label);

        return $label === '' ? __('account number') : $label;
    }
}
