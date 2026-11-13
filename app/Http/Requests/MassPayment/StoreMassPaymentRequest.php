<?php

declare(strict_types=1);

namespace App\Http\Requests\MassPayment;

use App\Services\MassPayment\MassPaymentCsvParser;
use App\Services\MassPayment\MassPaymentService;
use App\Support\PhoneIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A customer builds a mass payment draft on the web: one of their wallets,
 * and either an uploaded CSV (`source=csv`) or the rows typed into the manual
 * grid (`source=manual`).
 *
 * Only the shape is checked here. The wallet must be one of the customer's
 * own (by uuid, scoped to them), so another customer's wallet is a field
 * error rather than a draft. Every recipient and amount is still only text:
 * MassPaymentService resolves, validates and prices each line itself and
 * reports a bad one as an invalid line of the draft, not as a form error.
 */
class StoreMassPaymentRequest extends FormRequest
{
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
            'name'     => ['nullable', 'string', 'max:100'],
            'replaces' => ['nullable', 'uuid'],
            'source'   => ['required', Rule::in([MassPaymentService::SOURCE_CSV, MassPaymentService::SOURCE_MANUAL])],
            'file'     => array_merge(
                ['exclude_unless:source,'.MassPaymentService::SOURCE_CSV, 'required'],
                MassPaymentCsvParser::uploadRules(),
            ),
            'rows'             => ['exclude_unless:source,'.MassPaymentService::SOURCE_MANUAL, 'required', 'array', 'max:'.$this->maxRows()],
            'rows.*'           => ['array'],
            'rows.*.recipient' => ['nullable', 'string', 'max:191'],
            'rows.*.amount'    => ['nullable', 'string', 'max:64'],
            'rows.*.note'      => ['nullable', 'string', 'max:255'],
            'rows.*.reference' => ['nullable', 'string', 'max:100'],

            'rows.*.recipient_country' => ['nullable', 'string', 'max:2'],
        ];
    }

    /**
     * A row may name the country its number was typed under
     * (`recipient_country`, ISO-2), as the app's phone field does. A bare
     * national number typed under a country other than home is put into that
     * country's international form here, by the rule sign-in uses
     * (PhoneIdentifier::inPickedCountry()), so the draft resolves the number
     * the customer meant. Anything else - an email, a wallet ID, a '+' number,
     * an unknown country - is left exactly as typed.
     */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $key => $row) {
            if (! is_array($row) || ! is_string($row['recipient'] ?? null) || ! is_string($row['recipient_country'] ?? null)) {
                continue;
            }

            $rows[$key]['recipient'] = PhoneIdentifier::inPickedCountry($row['recipient'], $row['recipient_country']);
        }

        $this->merge(['rows' => $rows]);
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
            'source.required'         => __('Choose how to add the payments.'),
            'source.in'               => __('Choose how to add the payments.'),
            'file.required'           => __('Choose a CSV file to upload.'),
            'file.file'               => __('Choose a CSV file to upload.'),
            'file.mimes'              => __('The file must be a CSV file.'),
            'file.max'                => __('The file may not be larger than :max KB.'),
            'rows.required'           => __('Add at least one payment.'),
            'rows.array'              => __('Add at least one payment.'),
            'rows.max'                => __('A batch may have at most :max rows.'),
            'rows.*.array'            => __('A row could not be read. Check it and try again.'),
            'rows.*.recipient.string' => __('Each recipient must be text.'),
            'rows.*.recipient.max'    => __('A recipient may be at most :max characters.'),
            'rows.*.amount.string'    => __('Each amount must be text.'),
            'rows.*.amount.max'       => __('An amount may be at most :max characters.'),
            'rows.*.note.string'      => __('Each note must be text.'),
            'rows.*.note.max'         => __('A note may be at most :max characters.'),
            'rows.*.reference.string' => __('Each reference must be text.'),
            'rows.*.reference.max'    => __('A reference may be at most :max characters.'),
        ];
    }

    /** The customer's name for the batch, or null. */
    public function batchName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? $name : null;
    }

    /** The draft source: `csv` or `manual`. */
    public function source(): string
    {
        return (string) $this->validated('source');
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
