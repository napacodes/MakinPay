<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\LoginMethod;
use App\Enums\LoginRisk;
use App\Models\LoginActivity;
use App\Models\User;

/**
 * Everything the detail drawer shows about one sign-in.
 *
 * Built server-side and handed over as JSON, so the drawer never has to
 * decide what a risk verdict means — the same rules that wrote the verdict
 * describe it, and the action offered at the foot follows from it.
 */
class LoginActivityDetail
{
    /** How many earlier arrivals the drawer lists under the facts. */
    private const HISTORY_ROWS = 5;

    /**
     * @return array<string, mixed>
     */
    public function payload(LoginActivity $activity): array
    {
        $user = $activity->user;
        $risk = $activity->risk ?? LoginRisk::NORMAL;

        return [
            'id'      => $activity->id,
            'account' => $this->account($user),
            'risk'    => [
                'key'    => $risk->value,
                'label'  => $risk->label(),
                'tone'   => $risk->tone(),
                'reason' => $activity->risk_reason ?: __('No risk signals were recorded for this sign-in.'),
            ],
            'facts'   => $this->facts($activity),
            'history' => $this->history($activity),
            'action'  => $this->action($activity, $risk),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function account(?User $user): array
    {
        if ($user === null) {
            return [
                'id'       => null,
                'name'     => __('Deleted account'),
                'username' => '',
                'role'     => __('Unknown'),
                'tone'     => 'neutral',
                'initials' => '?',
                'avatar'   => null,
                'url'      => null,
            ];
        }

        $name = trim($user->first_name.' '.$user->last_name) ?: $user->username;

        return [
            'id'       => $user->id,
            'name'     => $name,
            'username' => (string) $user->username,
            'role'     => $user->role?->title() ?? __('User'),
            'tone'     => match ($user->role?->value) {
                'merchant' => 'brand',
                'agent'    => 'warning',
                default    => 'neutral',
            },
            'initials' => $this->initials($name),
            'avatar'   => $user->avatar ? asset($user->avatar) : null,
            'url'      => route('admin.user.manage', $user->username),
        ];
    }

    /**
     * @return list<array{key: string, value: string, mono: bool}>
     */
    private function facts(LoginActivity $activity): array
    {
        $signedIn = $activity->login_at;

        $session = match (true) {
            $activity->session_revoked_at !== null => __('Terminated :time', ['time' => $activity->session_revoked_at->diffForHumans()]),
            $activity->risk === LoginRisk::BLOCKED => __('Terminated by system'),
            default                                => __('Active'),
        };

        $device = implode(' · ', array_filter([$activity->browser, $activity->platform, $activity->device_type]));

        return [
            /* Masked while the demo is on, like the table it opened from. */
            ['key' => __('IP address'), 'value' => (string) maskSensitive($activity->ip_address, 'ip'), 'mono' => true],
            ['key' => __('Location'), 'value' => $activity->location(), 'mono' => false],
            ['key' => __('Network'), 'value' => $activity->isp ?: __('Unknown'), 'mono' => false],
            ['key' => __('Device'), 'value' => $device ?: __('Unknown'), 'mono' => false],
            ['key' => __('Signed in'), 'value' => $signedIn
                ? __(':date (:relative)', ['date' => $signedIn->format('d M Y H:i'), 'relative' => $signedIn->diffForHumans()])
                : __('Unknown'), 'mono' => false],
            ['key' => __('Method'), 'value' => ($activity->method ?? LoginMethod::PASSWORD)->label(), 'mono' => false],
            ['key' => __('Session'), 'value' => $session, 'mono' => false],
        ];
    }

    /**
     * This account's recent arrivals and refusals, newest first.
     *
     * Refused attempts belong here even though the table above shows
     * arrivals only: "three failures then a success" is the shape an operator
     * is looking for, and it is invisible if the failures are not on the list.
     *
     * @return list<array{tone: string, text: string, when: string}>
     */
    private function history(LoginActivity $activity): array
    {
        if ($activity->user_id === null) {
            return [];
        }

        return LoginActivity::query()
            ->where('user_id', $activity->user_id)
            ->orderByDesc('login_at')
            ->limit(self::HISTORY_ROWS)
            ->get()
            ->map(function (LoginActivity $row): array {
                /*
                 * The listener writes the literal 'Unknown' when the parser
                 * cannot name a browser or platform, and rows written before
                 * this screen existed carry it twice. Printing it back gives
                 * "Unknown · Unknown · United States", which says less than
                 * the country on its own.
                 */
                $parts = array_filter(
                    [$row->browser, $row->platform, $row->city ?: $row->country],
                    static fn (?string $part): bool => $part !== null && $part !== '' && $part !== 'Unknown'
                );

                $where = implode(' · ', $parts);

                if (! $row->succeeded) {
                    return [
                        'tone' => 'danger',
                        'text' => __('Failed attempt · :where', ['where' => $where ?: __('Unknown')]),
                        'when' => $row->login_at?->diffForHumans() ?? '',
                    ];
                }

                $risk = $row->risk ?? LoginRisk::NORMAL;

                return [
                    'tone' => match ($risk) {
                        LoginRisk::BLOCKED    => 'danger',
                        LoginRisk::SUSPICIOUS => 'warning',
                        LoginRisk::NEW_DEVICE => 'brand',
                        default               => 'success',
                    },
                    'text' => $where ?: __('Unknown device'),
                    'when' => $row->login_at?->diffForHumans() ?? '',
                ];
            })
            ->all();
    }

    /**
     * The one action the drawer offers, chosen by the verdict.
     *
     * @return array<string, mixed>
     */
    private function action(LoginActivity $activity, LoginRisk $risk): array
    {
        if ($activity->user_id === null) {
            return ['key' => null, 'label' => null, 'url' => null, 'tone' => 'neutral', 'hint' => null];
        }

        return match ($risk) {
            LoginRisk::BLOCKED => [
                'key'   => 'unblock',
                'label' => __('Unblock account'),
                'url'   => route('admin.activity-log.unblock', $activity->user_id),
                'tone'  => 'brand',
                'hint'  => __('Restores sign-in for this account.'),
            ],
            LoginRisk::NORMAL => [
                'key'   => 'revoke',
                'label' => __('Sign out session'),
                'url'   => route('admin.activity-log.revoke', $activity->id),
                'tone'  => 'neutral',
                'hint'  => __('Ends this session only.'),
            ],
            default => [
                'key'   => 'force-2fa',
                'label' => __('Force sign-out + 2FA'),
                'url'   => route('admin.activity-log.force-2fa', $activity->user_id),
                'tone'  => 'warning',
                'hint'  => __('Ends every session and requires two-factor next time.'),
            ],
        };
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        $letters = array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)),
            array_slice(array_filter($parts), 0, 2)
        );

        return implode('', $letters) ?: '?';
    }
}
