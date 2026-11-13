<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Models\BillPayment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * An operator records that a bill was paid at the biller.
 *
 * The receipt is what the customer and support will quote back, so it is
 * required. A biller that issues a token (a prepaid-meter code, a voucher
 * PIN) needs it too, labelled the way the biller names it: the customer
 * cannot use the payment without it. The token is checked here and stored
 * only in the payment's encrypted column.
 */
class MarkBillPaymentPaidRequest extends FormRequest
{
    /** Fields a refused save never writes to the session. */
    public const NEVER_FLASHED = ['token'];

    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'receipt_reference' => ['required', 'string', 'max:128'],
            'token'             => [$this->tokenLabel() !== null ? 'required' : 'nullable', 'string', 'max:512'],
            'admin_note'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $label = $this->tokenLabel() ?? __('token');

        return [
            'receipt_reference.required' => __('Enter the receipt reference.'),
            'receipt_reference.max'      => __('The receipt reference may not be longer than :max characters.'),
            'token.required'             => __('Enter the :label.', ['label' => $label]),
            'token.max'                  => __('The :label may not be longer than :max characters.', ['label' => $label]),
            'admin_note.max'             => __('The internal note may not be longer than :max characters.'),
        ];
    }

    /**
     * Send the operator back with what they typed, except the token: the
     * session is stored unencrypted, and the token lives only in the
     * payment's encrypted column. A JSON caller gets the usual 422, which
     * flashes nothing.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(
            redirect()->to($this->getRedirectUrl())
                ->withInput($this->except(self::NEVER_FLASHED))
                ->withErrors($validator->errors(), $this->errorBag)
        );
    }

    /**
     * The name the payment's biller gives its token, or null when it issues
     * none.
     */
    private function tokenLabel(): ?string
    {
        $payment = $this->route('billPayment');

        if (! $payment instanceof BillPayment) {
            return null;
        }

        $label = trim((string) $payment->biller()->value('token_label'));

        return $label === '' ? null : $label;
    }
}
