<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillerCsvImporter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * BP5: the biller file an admin uploads for review, and the provider that
 * will pay the billers in it. A provider whose billers come from the
 * catalogue sync is refused here, before the file is read.
 */
class ImportBillersRequest extends FormRequest
{
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
            'file'                     => ['required', ...BillerCsvImporter::uploadRules()],
            'bill_payment_provider_id' => ['required', 'integer', 'exists:bill_payment_providers,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required'                   => __('Choose the CSV file to import.'),
            'file.file'                       => __('Choose the CSV file to import.'),
            'file.mimes'                      => __('The file must be a CSV (.csv or .txt).'),
            'file.max'                        => __('The file is larger than allowed. Split it into smaller files.'),
            'bill_payment_provider_id.exists' => __('Choose the provider that pays these billers.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['bill_payment_provider_id' => __('provider')];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('bill_payment_provider_id')) {
                    return;
                }

                $provider = BillPaymentProvider::query()->find((int) $this->input('bill_payment_provider_id'));

                if ($provider !== null && $provider->billersComeFromSync()) {
                    $validator->errors()->add('bill_payment_provider_id', __('Billers for :provider come from the catalogue sync. Run the sync on the provider screen, then switch on the billers it imports.', [
                        'provider' => $provider->name,
                    ]));
                }
            },
        ];
    }

    public function provider(): BillPaymentProvider
    {
        return BillPaymentProvider::query()->findOrFail((int) $this->validated('bill_payment_provider_id'));
    }
}
