<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestInstallSmtpConnectionRequest extends FormRequest
{
    /**
     * Encryption values the admin Mail Settings screen offers, so the value
     * the installer stores is one that screen can show and save again.
     */
    public const MAIL_ENCRYPTIONS = ['tls', 'ssl'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules for the SMTP fields, shared with the final installation request so
     * the test and the install agree on what a usable mail setup is. The
     * lengths match the admin Mail Settings rules in config/settings.php.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function mailRules(): array
    {
        return [
            'mail_host'          => ['required', 'string', 'max:255'],
            'mail_port'          => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_secure'        => ['required', Rule::in(self::MAIL_ENCRYPTIONS)],
            'mail_username'      => ['required', 'string', 'max:255'],
            'mail_password'      => ['required', 'string', 'max:255'],
            'email_from_name'    => ['required', 'string', 'max:50'],
            'email_from_address' => ['required', 'email:rfc', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mailMessages(): array
    {
        return [
            'mail_host.required'          => __('SMTP host is required, for example smtp.gmail.com.'),
            'mail_port.required'          => __('SMTP port is required. Usually 587 with TLS or 465 with SSL.'),
            'mail_secure.in'              => __('Choose TLS or SSL encryption.'),
            'mail_username.required'      => __('SMTP username is required. With most providers this is the full email address.'),
            'mail_password.required'      => __('SMTP password is required.'),
            'email_from_address.required' => __('Enter the address emails are sent from.'),
            'email_from_address.email'    => __('The From address must be a valid email address.'),
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...self::mailRules(),
            'mail_test_recipient' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...self::mailMessages(),
            'mail_test_recipient.required' => __('Enter an address to send the test email to.'),
            'mail_test_recipient.email'    => __('The test email recipient must be a valid email address.'),
        ];
    }
}
