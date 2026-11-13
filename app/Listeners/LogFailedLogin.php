<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;
use Throwable;

/**
 * Records a rejected sign-in against the account it was aimed at.
 *
 * Without this the platform had no memory of a wrong password at all, so an
 * account could be guessed at for a week and the only trace would be the
 * rate limiter's own counter, which expires. The risk panel counts these.
 */
class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        /*
         * Customer sign-ins only. The success listener now also records an
         * administrator arriving, because the team-members screen asks when
         * someone was last here — but a refused attempt is read by the risk
         * panel, and that panel is about customer accounts. Nothing reads
         * refused administrator attempts, so nothing writes them.
         */
        if ($event->guard !== 'web') {
            return;
        }

        if ($event->user !== null && ! $event->user instanceof User) {
            return;
        }

        $request = request();
        $agent   = new Agent;
        $agent->setUserAgent($request->userAgent());

        /*
         * No IP geolocation here, unlike the success path. That lookup is an
         * outbound HTTP call, and putting one behind an unauthenticated form
         * hands anyone a way to make the platform hammer a third party by
         * typing wrong passwords. The country stays null; the risk signal
         * counts attempts, not places.
         *
         * $event->credentials carries the submitted password. Nothing from it
         * is read, and nothing from it is stored.
         */
        $attributes = [
            'user_id'    => $event->user?->getAuthIdentifier(),
            'succeeded'  => false,
            'ip_address' => $request->ip(),
            'country'    => null,
            'device'     => $agent->device() ?: 'Unknown',
            'platform'   => $agent->platform() ?: 'Unknown',
            'browser'    => $agent->browser() ?: 'Unknown',
            'user_agent' => $request->userAgent(),
            'login_at'   => now(),
        ];

        /*
         * As on the success path, the history must not be able to decide the
         * outcome of a sign-in. Here it would be the mirror image: a wrong
         * password answering with a 500 instead of being refused, which both
         * tells an attacker something and hides the refusal from the person
         * who simply mistyped.
         */
        try {
            LoginActivity::create($attributes);
        } catch (Throwable $exception) {
            Log::warning('Could not record a refused sign-in.', [
                'guard'      => $event->guard,
                'account_id' => $event->user?->getAuthIdentifier(),
                'reason'     => $exception->getMessage(),
            ]);
        }
    }
}
