<?php

namespace App\Services\Observability\Channels;

use App\Models\Observability\ErrorGroup;
use App\Models\Observability\ErrorNotificationChannel;

/**
 * Microsoft Teams: an Adaptive Card inside the message wrapper Teams expects.
 *
 * Teams is the slowest of the three and the most likely to rate
 * limit, which is the reason fan-out gives every channel its own job: a Teams
 * webhook queueing behind itself must not delay the message to Slack.
 */
class TeamsChannelAdapter extends WebhookChannelAdapter
{
    public function key(): string
    {
        return 'teams';
    }

    public function label(): string
    {
        return __('Microsoft Teams');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ErrorGroup $group, string $body, ErrorNotificationChannel $channel): array
    {
        return [
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => [
                    'type'    => 'AdaptiveCard',
                    'version' => '1.4',
                    'body'    => [
                        [
                            'type'   => 'TextBlock',
                            'text'   => $group->level->badgeLabel().' · '.$group->title,
                            'weight' => 'Bolder',
                            'size'   => 'Medium',
                            'wrap'   => true,
                        ],
                        [
                            'type' => 'TextBlock',
                            'text' => $body,
                            'wrap' => true,
                        ],
                    ],
                    'actions' => [[
                        'type'  => 'Action.OpenUrl',
                        'title' => __('Open error'),
                        'url'   => route('admin.observability.issues.show', $group),
                    ]],
                ],
            ]],
        ];
    }
}
