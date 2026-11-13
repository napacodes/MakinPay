<?php

declare(strict_types=1);

namespace App\Http\Requests\MassPayment;

use App\Services\MassPayment\MassPaymentCsvParser;
use App\Services\MassPayment\MassPaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A customer builds a mass payout draft on the web: one of their wallets,
 * one withdraw method, and either an uploaded CSV (`source=csv`) or the rows
 * typed into the manual grid (`source=manual`), each with the method's
 * account fields under `account`.
 *
 * Only the shape is checked here. The wallet must be the customer's own; the
 * method only has to be a number, because MassPayoutService answers every
 * method it will not offer with the same refusal, so an id never reveals
 * whether a method exists. Every amount and account detail is still only
 * text: the service checks and prices each line itself and reports a bad one
 * as an invalid line of the draft, not as a form error.
 */
class StoreMassPayoutRequest extends FormRequest
{
    public const ACCOUNT_VALUE_MAX_LENGTH = 2000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => [
                'required',
                'string',
                Rule::exists('wallets', 'uuid')->where(fn ($query) => $query->where('user_id', $this->user()?->id)),
            ],
            'method_id' => ['required', 'integer', 'min:1'],
            'name'      => ['nullable', 'string', 'max:100'],
            'replaces'  => ['nullable', 'uuid'],
            'source'    => ['required', Rule::in([MassPaymentService::SOURCE_CSV, MassPaymentService::SOURCE_MANUAL])],
            'file'      => array_merge(
                ['exclude_unless:source,'.MassPaymentService::SOURCE_CSV, 'required'],
                MassPaymentCsvParser::uploadRules(),
            ),
            'rows'             => ['exclude_unless:source,'.MassPaymentService::SOURCE_MANUAL, 'required', 'array', 'max:'.$this->maxRows()],
            'rows.*'           => ['array'],
            'rows.*.amount'    => ['nullable', 'string', 'max:64'],
            'rows.*.note'      => ['nullable', 'string', 'max:255'],
            'rows.*.reference' => ['nullable', 'string', 'max:100'],
            'rows.*.account'   => ['nullable', 'array'],
            'rows.*.account.*' => ['nullable', 'string', 'max:'.self::ACCOUNT_VALUE_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string'             => __('The batch name must be text.'),
            'name.max'                => __('The batch name may be at most :max characters.'),
            'wallet_id.required'      => __('Choose the wallet to pay from.'),
            'wallet_id.exists'        => __('The selected wallet is invalid.'),
            'method_id.required'      => __('Choose how the payouts are sent.'),
            'method_id.integer'       => __('Choose how the payouts are sent.'),
            'method_id.min'           => __('Choose how the payouts are sent.'),
            'source.required'         => __('Choose how to add the payouts.'),
            'source.in'               => __('Choose how to add the payouts.'),
            'file.required'           => __('Choose a CSV file to upload.'),
            'file.file'               => __('Choose a CSV file to upload.'),
            'file.mimes'              => __('The file must be a CSV file.'),
            'file.max'                => __('The file may not be larger than :max KB.'),
            'rows.required'           => __('Add at least one payout.'),
            'rows.array'              => __('Add at least one payout.'),
            'rows.max'                => __('A batch may have at most :max rows.'),
            'rows.*.array'            => __('A row could not be read. Check it and try again.'),
            'rows.*.amount.string'    => __('Each amount must be text.'),
            'rows.*.amount.max'       => __('An amount may be at most :max characters.'),
            'rows.*.note.string'      => __('Each note must be text.'),
            'rows.*.note.max'         => __('A note may be at most :max characters.'),
            'rows.*.reference.string' => __('Each reference must be text.'),
            'rows.*.reference.max'    => __('A reference may be at most :max characters.'),
            'rows.*.account.array'    => __('A row could not be read. Check it and try again.'),
            'rows.*.account.*.string' => __('Each account detail must be text.'),
            'rows.*.account.*.max'    => __('An account detail may be at most :max characters.'),
        ];
    }

    /** The draft source: `csv` or `manual`. */
    public function source(): string
    {
        return (string) $this->validated('source');
    }

    /** The customer's name for the batch, or null. */
    public function batchName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? $name : null;
    }

    public function methodId(): int
    {
        return (int) $this->validated('method_id');
    }

    /**
     * What the draft is built from: the uploaded file, or the grid rows.
     *
     * @return array<int|string, mixed>|UploadedFile
     */
    public function draftInput(): array|UploadedFile
    {
        if ($this->source() === MassPaymentService::SOURCE_CSV) {
            return $this->file('file');
        }

        return (array) $this->validated('rows', []);
    }

    private function maxRows(): int
    {
        return app(MassPaymentService::class)->maxRows();
    }
}
