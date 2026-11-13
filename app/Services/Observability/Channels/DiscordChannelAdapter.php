<?php

namespace App\Services\Observability\Channels;

use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;

/**
 * Discord: an embed with a level-coloured left bar.
 *
 * The colour is a convenience, not the signal — the level name is in the title
 * too, because a colour bar is invisible to anybody reading the notification
 * through a screen reader or a mobile summary.
 */
class DiscordChannelAdapter extends WebhookChannelAdapter
{
    public function key(): string
    {
        return 'discord';
    }

    public function label(): string
    {
        return __('Discord');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ErrorGroup $group, string $body, ErrorNotificationChannel $channel): array
    {
        return [
            'embeds' => [[
                'title'       => $group->level->badgeLabel().' · '.$group->title,
                'description' => $body,
                'color'       => $this->colour($group),
                'url'         => route('admin.observability.issues.show', $group),
                'fields'      => [
                    ['name' => __('Events'), 'value' => (string) $group->event_count, 'inline' => true],
                    ['name' => __('Users'), 'value' => (string) $group->affected_user_count, 'inline' => true],
                    ['name' => __('Source'), 'value' => $group->source->chipLabel(), 'inline' => true],
                ],
            ]],
        ];
    }

    /**
     * Discord takes a decimal integer rather than a hex string.
     */
    private function colour(ErrorGroup $group): int
    {
        return match ($group->level->badge()) {
            'fatal'   => 0xB91C1C,
            'error'   => 0xEF4444,
            'warning' => 0xB45309,
            default   => 0x0369A1,
        };
    }
}
