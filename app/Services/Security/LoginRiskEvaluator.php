<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\LoginRisk;
use App\Models\LoginActivity;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Notifications\TemplateNotification;
use App\Services\UserAccountSummaryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What to make of one sign-in, decided from rows the platform already writes.
 *
 * Every rule below compares this arrival against that account's own history,
 * never against a global reputation list, because the question the Activity
 * history screen asks is "is this normal *for this person*". An account that
 * always signs in from a datacenter is not suspicious for doing it again.
 *
 * The verdicts are ordered: blocked beats suspicious beats new device. The
 * first rule that matches wins, so a session terminated for impossible travel
 * is not also relabelled "new device" for arriving on an unseen browser.
 *
 * Thresholds are admin-editable (layer C). The defaults are the handoff's.
 */
class LoginRiskEvaluator
{
    public function __construct(private readonly UserAccountSummaryService $accounts) {}

    /** Kilometres between two sign-ins that no aircraft could have covered. */
    public const DEFAULT_TRAVEL_KM = 800;

    /** ...within this many minutes. */
    public const DEFAULT_TRAVEL_MINUTES = 60;

    /** Refused attempts against the account just before it opened. */
    public const DEFAULT_FAILED_THRESHOLD = 3;

    /** ...counted over this many minutes. */
    public const DEFAULT_FAILED_WINDOW = 15;

    /** How far back "this account has signed in from here before" looks. */
    public const DEFAULT_NEW_COUNTRY_DAYS = 30;

    /** How many recent arrivals the device rule compares against. */
    public const DEVICE_SCAN_CAP = 200;

    /**
     * The template the account holder is sent when a sign-in is blocked.
     * Seeded by SecurityNotificationTemplateSeeder; an installation that has
     * not run it delivers nothing and logs, rather than throwing.
     */
    public const BLOCKED_TEMPLATE = 'security_user_notify_sign_in_blocked';

    /**
     * Networks that are anonymising or rented by the hour. Matched as a
     * substring of the ISP string the IP lookup returned, lowercased.
     *
     * @var list<string>
     */
    public const ANONYMISING_NETWORKS = [
        'tor exit', 'tor network', 'tor relay', 'proxy', 'vpn',
        'digitalocean', 'linode', 'ovh', 'hetzner', 'vultr', 'selectel',
        'amazon', 'google cloud', 'microsoft azure', 'contabo', 'choopa',
    ];

    /**
     * Decide, record and act.
     *
     * Returns the verdict it wrote. Nothing here throws: it runs behind a
     * sign-in that has already been accepted, and a risk engine that can
     * refuse a session by crashing is worse than one that stays quiet.
     */
    public function evaluate(LoginActivity $activity): LoginRisk
    {
        if (! $activity->succeeded || $activity->user_id === null) {
            return LoginRisk::NORMAL;
        }

        [$risk, $reason] = $this->assess($activity);

        if ($risk === LoginRisk::BLOCKED) {
            /*
             * The published demo logins are shared: on a demo install the
             * credentials are printed on the sign-in page, so the same
             * account arrives from every continent within the hour and the
             * travel rule fires on ordinary use. Suspending it would hand
             * any visitor a one-click way to end the demo for everybody
             * after them, so the arrival is recorded — the screen still
             * demonstrates the rule — and the account is left open.
             *
             * Nothing is given up by doing so. A visitor who is actually
             * probing is answered at their address by
             * {@see \App\Services\Security\IpAbuseGuard}, which costs the
             * other evaluators nothing.
             */
            if ($this->isSharedDemoLogin($activity)) {
                $risk   = LoginRisk::SUSPICIOUS;
                $reason = $reason.' '.__('Published demo login: recorded only, the account stays open.');
            } else {
                $reason = $reason.' '.__('Account suspended.');
            }
        }

        $activity->forceFill([
            'risk'        => $risk->value,
            'risk_reason' => $reason,
        ])->save();

        if ($risk === LoginRisk::BLOCKED) {
            $this->terminate($activity);
        }

        if ($risk === LoginRisk::NEW_DEVICE && ! $this->isSharedDemoLogin($activity)) {
            $this->tellNewDevice($activity);
        }

        return $risk;
    }

    /**
     * A sign-in from a device this account has not used: the owner hears
     * of it at once, with "that was me" and "secure account" to hand.
     */
    private function tellNewDevice(LoginActivity $activity): void
    {
        try {
            $activity->user?->notify(new NewDeviceLoginNotification($activity));
        } catch (\Throwable $exception) {
            Log::warning('Could not tell a user about a new-device sign-in.', [
                'login_activity_id' => $activity->id,
                'reason'            => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Is the account behind this arrival one of the published demo logins?
     */
    private function isSharedDemoLogin(LoginActivity $activity): bool
    {
        $user = $activity->user_id === null ? null : User::find($activity->user_id);

        return $user !== null && $user->isDemoProtected();
    }

    /**
     * @return array{0: LoginRisk, 1: string}
     */
    public function assess(LoginActivity $activity): array
    {
        if ($travel = $this->impossibleTravel($activity)) {
            return [LoginRisk::BLOCKED, $travel];
        }

        if ($anonymising = $this->anonymisingNetwork($activity)) {
            return [LoginRisk::SUSPICIOUS, $anonymising];
        }

        if ($guessing = $this->recentlyGuessed($activity)) {
            return [LoginRisk::SUSPICIOUS, $guessing];
        }

        if ($country = $this->newCountry($activity)) {
            return [LoginRisk::NEW_DEVICE, $country];
        }

        if ($device = $this->newDevice($activity)) {
            return [LoginRisk::NEW_DEVICE, $device];
        }

        return [LoginRisk::NORMAL, __('Known device and network.')];
    }

    /**
     * Two sign-ins too far apart to be the same person travelling.
     */
    private function impossibleTravel(LoginActivity $activity): ?string
    {
        if ($activity->latitude === null || $activity->longitude === null) {
            return null;
        }

        $minutes = (int) setting('login_risk_travel_minutes', self::DEFAULT_TRAVEL_MINUTES);
        $limit   = (float) setting('login_risk_travel_km', self::DEFAULT_TRAVEL_KM);

        $previous = $this->history($activity)
            ->where('login_at', '>=', $activity->login_at->copy()->subMinutes($minutes))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderByDesc('login_at')
            ->first();

        if ($previous === null) {
            return null;
        }

        $km = $this->distance(
            (float) $previous->latitude,
            (float) $previous->longitude,
            (float) $activity->latitude,
            (float) $activity->longitude,
        );

        if ($km < $limit) {
            return null;
        }

        $apart = max(1, (int) $previous->login_at->diffInMinutes($activity->login_at, true));

        /*
         * The rule states the fact; what follows from it is decided in
         * evaluate(), because a published demo login is recorded and left
         * open where any other account is suspended.
         */
        return __('Impossible travel: :city :minutes min earlier, :km km away.', [
            'city'    => $previous->city ?: ($previous->country ?: __('Unknown')),
            'minutes' => $apart,
            'km'      => (int) round($km),
        ]);
    }

    /**
     * A network that hides where the person actually is.
     */
    private function anonymisingNetwork(LoginActivity $activity): ?string
    {
        $isp = mb_strtolower((string) $activity->isp);

        if ($isp === '') {
            return null;
        }

        foreach (self::ANONYMISING_NETWORKS as $needle) {
            if (str_contains($isp, $needle)) {
                return __('Signed in over :network, which hides the real location.', ['network' => $activity->isp]);
            }
        }

        return null;
    }

    /**
     * Refused attempts against this account immediately before it opened.
     */
    private function recentlyGuessed(LoginActivity $activity): ?string
    {
        $threshold = (int) setting('login_risk_failed_threshold', self::DEFAULT_FAILED_THRESHOLD);

        if ($threshold < 1) {
            return null;
        }

        $window = (int) setting('login_risk_failed_window', self::DEFAULT_FAILED_WINDOW);

        /*
         * `??`, not `?:`. The column is an int and zero is a real answer —
         * "nobody guessed at this account" — so a truthiness test would throw
         * the stored value away and re-run the count on every ordinary
         * sign-in, which is the majority of them.
         */
        $attempts = $activity->failed_attempts_before ?? LoginActivity::query()
            ->failed()
            ->where('user_id', $activity->user_id)
            ->whereBetween('login_at', [$activity->login_at->copy()->subMinutes($window), $activity->login_at])
            ->count();

        if ($attempts < $threshold) {
            return null;
        }

        return __(':count failed attempts in the :minutes minutes before this sign-in.', [
            'count'   => $attempts,
            'minutes' => $window,
        ]);
    }

    /**
     * A country this account has not used in the recent past.
     */
    private function newCountry(LoginActivity $activity): ?string
    {
        $country = (string) $activity->country;

        if ($country === '') {
            return null;
        }

        $days = (int) setting('login_risk_new_country_days', self::DEFAULT_NEW_COUNTRY_DAYS);

        $seen = $this->history($activity)
            ->where('login_at', '>=', $activity->login_at->copy()->subDays($days))
            ->where('country', $country)
            ->exists();

        if ($seen) {
            return null;
        }

        /*
         * An account with no history at all is not signing in from a new
         * country — it is signing in for the first time, and calling that
         * suspicious would flag every registration.
         */
        /*
         * The account's usual country is read from its whole history, not
         * from the lookback window. Scoped to the window, an account dormant
         * for longer than it has no "usual" at all and is scored normal —
         * which is exactly the reactivated-account arrival this rule exists
         * to catch.
         */
        $usual = $this->history($activity)
            ->whereNotNull('country')
            ->orderByDesc('login_at')
            ->value('country');

        if ($usual === null) {
            return null;
        }

        return __('New country for this account (usually :country).', ['country' => $usual]);
    }

    /**
     * A browser / platform / device combination this account has not used.
     */
    private function newDevice(LoginActivity $activity): ?string
    {
        $fingerprint = $this->fingerprint($activity);

        if ($fingerprint === '') {
            return null;
        }

        /*
         * Bounded on purpose. This runs once per successful sign-in, and an
         * account that signs in daily across the retention window would
         * otherwise pull its whole history into memory every time. The most
         * recent arrivals are where a known device will be found.
         */
        $seen = $this->history($activity)
            ->orderByDesc('login_at')
            ->limit(self::DEVICE_SCAN_CAP)
            ->get(['browser', 'platform', 'device_type']);

        /*
         * An account with no history at all is not arriving on a new device —
         * it is arriving for the first time. Without this the rule flags
         * every registration, and a screen where every new customer is a
         * warning is a screen nobody reads.
         */
        if ($seen->isEmpty()) {
            return null;
        }

        if ($seen->contains(fn (LoginActivity $row): bool => $this->fingerprint($row) === $fingerprint)) {
            return null;
        }

        return __('First sign-in from this device and browser.');
    }

    /**
     * Everything this account did before the row being judged.
     *
     * Ordered by id as well as time so two sign-ins recorded in the same
     * second cannot include the row in its own history.
     */
    private function history(LoginActivity $activity): Builder
    {
        return LoginActivity::query()
            ->succeeded()
            ->where('user_id', $activity->user_id)
            ->where('id', '!=', $activity->id)
            ->where('login_at', '<=', $activity->login_at);
    }

    private function fingerprint(LoginActivity $activity): string
    {
        $parts = array_filter([$activity->browser, $activity->platform, $activity->device_type]);

        return mb_strtolower(implode('|', $parts));
    }

    /**
     * Great-circle distance in kilometres.
     */
    public function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2)                                              ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Shut the account, not just the row.
     *
     * A verdict of "blocked" has to mean the next arrival is refused, or the
     * word is a label on a screen while the credentials keep working. Three
     * things therefore happen together:
     *
     * 1. The account is suspended until an administrator lifts it. That is
     *    the gate `CheckUserStatus` and `LoginRequest` already enforce, so
     *    reusing it is what makes the block real on every entry point rather
     *    than only the one this screen knows about.
     * 2. Every session and token is revoked through the same service the
     *    Manage user screen uses.
     * 3. The row is stamped as revoked, which is what the drawer reads.
     *
     * Deleting the one session row is not enough on its own: this platform
     * ships with `SESSION_DRIVER=file` as often as not, where there are no
     * rows to delete and the intruder's cookie would otherwise stay valid.
     */
    private function terminate(LoginActivity $activity): void
    {
        /*
         * evaluate() never reaches here for a published demo login, and this
         * says so a second time: the shared sandbox account is never
         * suspended and its sessions — other evaluators, mid-walkthrough —
         * are never revoked.
         */
        if ($this->isSharedDemoLogin($activity)) {
            return;
        }

        if ($activity->session_id !== null && config('session.driver') === 'database') {
            try {
                DB::table(config('session.table', 'sessions'))
                    ->where('id', $activity->session_id)
                    ->delete();
            } catch (\Throwable) {
                /* A session store that cannot be reached must not undo the verdict. */
            }
        }

        $activity->forceFill(['session_revoked_at' => Carbon::now()])->save();

        $user = $activity->user_id === null ? null : User::find($activity->user_id);

        if ($user === null) {
            return;
        }

        /*
         * Indefinite, never timed. The platform cannot tell on its own that
         * the person got their account back, so the block waits for someone
         * to decide — the drawer's "Unblock account" is that decision.
         */
        if (! $user->isSuspended()) {
            $user->forceFill([
                'suspended_at'      => Carbon::now(),
                'suspended_until'   => null,
                'suspension_reason' => 'suspicious_activity',
                'suspension_note'   => $activity->risk_reason,
            ])->save();
        }

        try {
            $this->accounts->revokeSessions($user);
        } catch (\Throwable) {
            /* The suspension is the block; the revoke is how fast it bites. */
        }

        $this->tell($user, $activity);
    }

    /**
     * Tell the account holder their account was locked, and why.
     *
     * They are the only person who can say "that was me, I was travelling"
     * or "that was not me", and an account locked without a word is
     * indistinguishable from an outage. Best-effort: a mail server that is
     * down must not leave the account unlocked, so a failure here is logged
     * and the block stands.
     */
    private function tell(User $user, LoginActivity $activity): void
    {
        try {
            $user->notify(new TemplateNotification(
                identifier: self::BLOCKED_TEMPLATE,
                data: [
                    'location'     => $activity->location(),
                    'ip_address'   => (string) $activity->ip_address,
                    'device'       => implode(' · ', array_filter([$activity->browser, $activity->platform])) ?: __('an unrecognised device'),
                    'signed_in_at' => $activity->login_at?->format('d M Y H:i') ?? '',
                    'reason'       => (string) $activity->risk_reason,
                ],
            ));
        } catch (\Throwable $exception) {
            Log::warning('Could not tell a user their sign-in was blocked.', [
                'user_id'           => $user->id,
                'login_activity_id' => $activity->id,
                'reason'            => $exception->getMessage(),
            ]);
        }
    }
}
