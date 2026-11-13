<?php

namespace App\Http\Requests\Backend;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * The export takes the same query string the list page is showing, so the
     * file can never describe a different set of rows than the screen it was
     * downloaded from.
     *
     * The list itself is tolerant of junk in these parameters — it simply
     * ignores what it cannot use. The export is not: `daterange` reaches
     * `Carbon::parse()` inside the shared filter scope, so a malformed range
     * has to be turned away here rather than thrown from inside a response
     * whose headers have already been sent.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The Manage user page exports one account's ledger.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type'    => ['nullable', 'string', Rule::in([...array_column(TrxType::cases(), 'value'), 'all'])],
            'status'  => ['nullable', 'string', Rule::in(array_column(TrxStatus::cases(), 'value'))],
            'search'  => ['nullable', 'string', 'max:255'],
            // Written by the shared date-range picker as `Y-m-d,Y-m-d`.
            'daterange' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2},\d{4}-\d{2}-\d{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in'         => __('Selected transaction type is invalid.'),
            'status.in'       => __('Selected transaction status is invalid.'),
            'daterange.regex' => __('The date range is not in a format we recognise.'),
        ];
    }
}
