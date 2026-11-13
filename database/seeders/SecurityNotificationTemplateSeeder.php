<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationActionType;
use App\Enums\UserType;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Templates for the sign-in risk engine.
 *
 * The account holder is told when the platform refuses a sign-in on their
 * behalf. They are the only person who can say "that was me, I was
 * travelling" or "that was not me", and an account locked without a word is
 * indistinguishable from an outage.
 *
 * Replayed on every update through `installer.reconcile_seeders`, so the
 * feature works on an installation that upgrades into it rather than only on
 * one built fresh. It is additive for the same reason its core sibling is:
 * administrators edit this copy from the admin panel, and a replay must not
 * discard their wording. A missing template is created, and a template that
 * exists but lost its channels is repaired.
 */
class SecurityNotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'identifier'  => 'security_user_notify_sign_in_blocked',
                'name'        => 'Sign-in Blocked',
                'icon'        => 'shield-ban',
                'action_type' => NotificationActionType::FAILED,
                'info'        => 'The account holder is told when the risk rules block a sign-in and suspend the account.',
                'user_type'   => UserType::USER,
                'variables'   => ['location', 'ip_address', 'device', 'signed_in_at', 'reason'],
                'channels'    => [
                    [
                        'channel'   => 'email',
                        'title'     => 'We blocked a sign-in to your account',
                        'message'   => 'A sign-in to your account from {location} ({ip_address}) on {device} at {signed_in_at} did not look like you, so we blocked it and locked the account. Reason: {reason}. If this was you, contact support to unlock it. If it was not, change your password as soon as you regain access.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'push',
                        'title'     => 'Sign-in blocked',
                        'message'   => 'We blocked a sign-in from {location} and locked your account. Contact support if this was you.',
                        'is_active' => true,
                    ],
                    [
                        'channel'   => 'sms',
                        'title'     => null,
                        'message'   => 'Sign-in from {location} blocked and your account locked. Contact support if this was you.',
                        'is_active' => false,
                    ],
                ],
            ],
        ];

        foreach ($templates as $data) {
            $template = NotificationTemplate::query()
                ->where('identifier', $data['identifier'])
                ->first();

            if (! $template) {
                $template = NotificationTemplate::query()->create(
                    collect($data)->except('channels')->toArray()
                );
            }

            if ($template->channels()->exists()) {
                continue;
            }

            $template->channels()->createMany($data['channels']);
        }
    }
}
