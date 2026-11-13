<?php

namespace App\Services\PhoneVerification\Drivers;

use App\Contracts\PhoneVerification\PhoneVerificationProviderInterface;
use App\Contracts\PhoneVerification\SendsSignInCodes;
use App\Exceptions\NotifyErrorException;
use App\Models\User;
use App\Notifications\LoginOtpCodeNotification;
use App\Notifications\PhoneVerificationCodeNotification;

class TwilioPhoneVerificationProvider implements PhoneVerificationProviderInterface, SendsSignInCodes
{
    public function send(User $user, string $code): void
    {
        $this->assertEnabled();

        $user->notify(new PhoneVerificationCodeNotification($code));
    }

    public function sendSignInCode(User $user, string $code, int $minutes): void
    {
        $this->assertEnabled();

        $user->notify(new LoginOtpCodeNotification($code, $minutes));
    }

    private function assertEnabled(): void
    {
        if (! config('twilio-notification-channel.enabled')) {
            throw new NotifyErrorException(__('SMS verification provider is disabled.'));
        }
    }
}
