<?php

namespace App\Http\Requests\Backend\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuspendUserRequest extends FormRequest
{
    /** @var list<string> */
    public const REASONS = ['suspicious_activity', 'chargeback_dispute', 'kyc_mismatch', 'policy_violation', 'user_request'];

    /** @var array<string, int|null> duration key → hours, null = until lifted */
    public const DURATIONS = ['24h' => 24, '7d' => 168, '30d' => 720, 'indefinite' => null];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'   => ['required', 'string', Rule::in(self::REASONS)],
            'duration' => ['required', 'string', Rule::in(array_keys(self::DURATIONS))],
            'note'     => ['nullable', 'string', 'max:1000'],
            'notify'   => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => __('Pick a reason for the suspension.'),
            'reason.in'       => __('Pick one of the listed reasons.'),
            'duration.in'     => __('Pick one of the listed durations.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function reasonLabels(): array
    {
        return [
            'suspicious_activity' => __('Suspicious activity'),
            'chargeback_dispute'  => __('Chargeback / dispute'),
            'kyc_mismatch'        => __('KYC mismatch'),
            'policy_violation'    => __('Policy violation'),
            'user_request'        => __('User request'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function durationLabels(): array
    {
        return [
            '24h'        => __('24 hours'),
            '7d'         => __('7 days'),
            '30d'        => __('30 days'),
            'indefinite' => __('Until lifted'),
        ];
    }
}
