<?php

namespace App\Services\Observability\Channels;

use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;

/**
 * Slack: Block Kit, with an Acknowledge button.
 *
 * The button is why this adapter carries the group id in its action value —
 * an acknowledgement arriving back through Slack interactivity has to identify
 * the same group a click in the panel would, so both write the same row.
 */
class SlackChannelAdapter extends WebhookChannelAdapter
{
    public function key(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return __('Slack');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ErrorGroup $group, string $body, ErrorNotificationChannel $channel): array
    {
        $url = route('admin.observability.issues.show', $group);

        return [
            'text'   => $group->level->badgeLabel().' · '.$group->title,
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $body],
                ],
                [
                    'type'     => 'actions',
                    'elements' => [
                        [
                            'type'      => 'button',
                            'style'     => 'primary',
                            'text'      => ['type' => 'plain_text', 'text' => __('Acknowledge')],
                            'action_id' => 'observability_acknowledge',
                            'value'     => $group->group_id,
                        ],
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => __('Open error')],
                            'url'  => $url,
                        ],
                    ],
                ],
            ],
        ];
    }
}
