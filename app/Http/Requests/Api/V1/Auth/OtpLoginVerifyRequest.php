<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Auth\OtpLoginVerifyRequest as WebOtpLoginVerifyRequest;

/**
 * The app's half of signing in by SMS code (GF9): the web form's fields and
 * throttle keys, plus the handset the token is issued to, read the way the
 * password sign-in reads it.
 */
class OtpLoginVerifyRequest extends WebOtpLoginVerifyRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'device'             => ['sometimes', 'array'],
            'device.id'          => ['nullable', 'string', 'max:64'],
            'device.name'        => ['nullable', 'string', 'max:120'],
            'device.platform'    => ['nullable', 'string', 'max:20'],
            'device.app_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** A phone cannot produce a reCAPTCHA token; the shared lockout carries the budget. */
    protected function wantsRecaptcha(): bool
    {
        return false;
    }

    /**
     * @return array{id: ?string, name: ?string, platform: ?string, app_version: ?string}
     */
    public function device(): array
    {
        return [
            'id'          => $this->input('device.id'),
            'name'        => $this->input('device.name'),
            'platform'    => $this->input('device.platform'),
            'app_version' => $this->input('device.app_version'),
        ];
    }
}
