<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which field a customer sign-in form opens on.
 *
 *   Email  the email-or-username field, as every form did before this
 *          setting existed.
 *   Phone  the phone field, with the home country already picked, for
 *          markets where people know their number and not their email.
 *
 * A layer-C admin choice (`default_sign_in_field`). It decides only what the
 * form shows first: both fields stay one tap away, and the server accepts an
 * email, a username or a phone number on either, exactly as before.
 */
enum SignInField: string
{
    case Email = 'email';
    case Phone = 'phone';

    public const SETTING = 'default_sign_in_field';

    /**
     * A missing or unknown value is Email, today's behaviour, so an upgraded
     * installation changes nothing until an admin opts in.
     */
    public static function fromSetting(): self
    {
        return self::tryFrom((string) setting(self::SETTING, self::Email->value)) ?? self::Email;
    }

    /**
     * Label => value, the shape the settings select renders from.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'Email or username' => self::Email->value,
            'Phone number'      => self::Phone->value,
        ];
    }
}
