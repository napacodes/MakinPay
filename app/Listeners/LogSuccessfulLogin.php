<?php

namespace App\Listeners;

use App\Enums\LoginMethod;
use App\Jobs\EvaluateLoginRisk;
use App\Models\Admin;
use App\Models\LoginActivity;
use App\Models\User;
use App\Services\Security\LoginRiskEvaluator;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;
use Throwable;

class LogSuccessfulLogin
{
    public function __construct()
    {
        //
    }

    public function handle(Login $event)
    {
        $isAdmin = $event->user instanceof Admin;

        if (! $isAdmin && ! ($event->user instanceof User)) {
            return;
        }

        $request = request();
        $agent   = new Agent;
        $agent->setUserAgent($request->userAgent());
        $app = $this->appDevice($request);

        $location = \IpInfo::getIpInfo($request->ip());

        [$latitude, $longitude] = $this->coordinates($location);

        /*
         * An administrator is recorded under `admin_id`. `user_id` points at
         * `users`, which an Admin has no row in, and every screen reading this
         * table by `user_id` means customers.
         */
        $attributes = [
            'user_id'    => $isAdmin ? null : $event->user->id,
            'admin_id'   => $isAdmin ? $event->user->id : null,
            'succeeded'  => true,
            'ip_address' => $request->ip(),
            'country'    => $location['country_name']
                                             ?? ($isAdmin ? null : $event->user->country)
                                             ?? 'US',
            'city'      => $location['city'] ?? null,
            'isp'       => $this->network($location),
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'device'    => $app['device'] ?? ($agent->device() ?: 'Unknown'),
            /*
             * The screen says Desktop / Mobile / Tablet, which is a different
             * question from `$agent->device()` ("iPhone", "WebKit"). Asked in
             * the parser's own order, because a tablet also answers isMobile.
             */
            'device_type' => match (true) {
                $app !== null      => 'Mobile',
                $agent->isTablet() => 'Tablet',
                $agent->isMobile() => 'Mobile',
                default            => 'Desktop',
            },
            'method'                 => $this->method($event->user, $request),
            'session_id'             => $request->hasSession() ? $request->session()->getId() : null,
            'failed_attempts_before' => $isAdmin ? 0 : $this->failedAttemptsBefore($event->user->id),
            'platform'               => $app['platform'] ?? ($agent->platform() ?: 'Unknown'),
            'browser'                => $app['browser']  ?? ($agent->browser() ?: 'Unknown'),
            'user_agent'             => $request->userAgent(),
            'login_at'               => now(),
        ];

        /*
         * The history is written after the credentials have already been
         * accepted, so it must not be able to refuse the sign-in.
         *
         * It could. `admin_id` arrived in a later migration, and an
         * installation running the code against a table without that column
         * threw a QueryException out of this listener, turning every
         * successful sign-in — administrator and customer alike — into a 500.
         * The session had already been written by then, so reloading appeared
         * to fix it, which is the worst version of the bug: it looks
         * intermittent rather than total.
         *
         * Losing a history row is the lesser harm than locking a payment
         * platform's operators out of it, but it is not nothing — the risk
         * panel counts these — so the failure is logged loudly rather than
         * swallowed. Nothing from the submitted credentials is recorded.
         */
        try {
            $activity = LoginActivity::create($attributes);

            /*
             * Judged on the queue, never in the request: the rules read this
             * account's whole history, and the person is already signed in.
             * Administrators are not judged — the screen and the rules are
             * about customer accounts.
             */
            if (! $isAdmin) {
                EvaluateLoginRisk::dispatch($activity->id);
            }
        } catch (Throwable $exception) {
            Log::warning('Could not record a successful sign-in.', [
                'guard'      => $isAdmin ? 'admin' : 'web',
                'account_id' => $event->user->getAuthIdentifier(),
                'reason'     => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The phone the app named itself as - the sign-in payload's device block
     * (name, platform) - since a Dart user agent says nothing a person would
     * recognise. Null for a browser sign-in.
     *
     * @return array{device: string, platform: string, browser: string}|null
     */
    private function appDevice($request): ?array
    {
        $device = $request->input('device');

        if (! is_array($device) || trim((string) ($device['name'] ?? '')) === '') {
            return null;
        }

        $platform = strtolower(trim((string) ($device['platform'] ?? '')));

        return [
            'device'   => mb_substr(trim((string) $device['name']), 0, 100),
            'platform' => match ($platform) {
                'ios'     => 'iOS',
                'android' => 'Android',
                ''        => 'Unknown',
                default   => ucfirst($platform),
            },
            'browser' => config('app.name').' app',
        ];
    }

    /**
     * Latitude and longitude from the lookup's "lat,lng" string.
     *
     * @param  array<string, mixed>                $location
     * @return array{0: float|null, 1: float|null}
     */
    private function coordinates(?array $location): array
    {
        $point = explode(',', (string) ($location['loc'] ?? ''));

        if (count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
            return [null, null];
        }

        return [(float) $point[0], (float) $point[1]];
    }

    /**
     * The network operator, without the AS number the lookup prefixes it with.
     *
     * @param array<string, mixed> $location
     */
    private function network(?array $location): ?string
    {
        $org = trim((string) ($location['org'] ?? $location['asn']['name'] ?? ''));

        if ($org === '') {
            return null;
        }

        return trim(preg_replace('/^AS\d+\s*/i', '', $org)) ?: $org;
    }

    /**
     * How the credentials were presented.
     *
     * Read from what the account has switched on rather than guessed from the
     * route, except for the two arrivals that have their own entry point.
     */
    private function method(mixed $account, $request): string
    {
        if ($request->routeIs('*social*') || $request->routeIs('*callback*')) {
            return LoginMethod::SOCIAL->value;
        }

        if ($request->bearerToken() !== null) {
            return LoginMethod::API_KEY->value;
        }

        if (($account->two_factor_enabled ?? false)) {
            return LoginMethod::PASSWORD_2FA->value;
        }

        return LoginMethod::PASSWORD->value;
    }

    /**
     * Refused attempts against this account in the minutes before it opened.
     *
     * Stored on the row so the risk rules and the drawer read one number
     * rather than re-counting a window that has since moved.
     */
    private function failedAttemptsBefore(int $userId): int
    {
        $window = (int) setting('login_risk_failed_window', LoginRiskEvaluator::DEFAULT_FAILED_WINDOW);

        try {
            return LoginActivity::query()
                ->failed()
                ->where('user_id', $userId)
                ->where('login_at', '>=', now()->subMinutes($window))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
