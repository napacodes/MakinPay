<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the credentials that opened a session were presented.
 *
 * Recorded rather than inferred: the activity screen shows it beside the
 * time, and "Password" against a session that actually arrived on an API key
 * is the kind of wrong answer an operator would act on.
 */
enum LoginMethod: string
{
    case PASSWORD = 'password';

    case PASSWORD_2FA = 'password_2fa';

    case BIOMETRIC = 'biometric';

    case API_KEY = 'api_key';

    case SOCIAL = 'social';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::PASSWORD     => __('Password'),
            self::PASSWORD_2FA => __('Password + 2FA'),
            self::BIOMETRIC    => __('Biometric'),
            self::API_KEY      => __('API key'),
            self::SOCIAL       => __('Social'),
        };
    }
}
