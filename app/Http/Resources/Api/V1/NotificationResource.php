<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\NotificationCategory;
use App\Support\NotificationLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One notification, flattened for the app.
 *
 * The stored payload is already display-shaped - TemplateNotification writes a
 * title, a message, an icon and an action - so the keys are lifted to the top
 * level rather than nested under `data`. A client should not have to know the
 * name of Laravel's column to read a title.
 *
 * Only the keys that payload is known to carry are lifted. A notification
 * class that writes something else into `data` contributes nothing to the
 * response rather than passing an unreviewed shape through to a phone.
 *
 * `category` files the row under an inbox chip, `action` is the in-app
 * screen a tap lands on when the link is one of ours, and `actions` are the
 * inline buttons - pay or decline a request, confirm or secure after a
 * sign-in - each naming what it acts on.
 *
 * @property-read DatabaseNotification $resource
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;
        $data         = is_array($notification->data) ? $notification->data : [];

        return [
            'id'          => $notification->id,
            'title'       => $data['title']       ?? null,
            'message'     => $data['message']     ?? null,
            'icon'        => $data['icon']        ?? null,
            'action_type' => $data['action_type'] ?? null,

            // A web route. Useful to an app that opens a webview, and ignored
            // by one that does not - it is never a credential.
            'action_link' => $data['action_link'] ?? null,

            // The app screen the row deep-links to: app_link, or an app path
            // written into action_link before the two were split.
            'action' => NotificationLink::app($data),

            'category' => NotificationCategory::forRow((string) $notification->type, $data),
            'actions'  => NotificationCategory::actionsFor((string) $notification->type, $data, (int) $notification->notifiable_id),

            'sender' => isset($data['sender']) && is_array($data['sender']) ? [
                'name'   => $data['sender']['name']   ?? null,
                'avatar' => $data['sender']['avatar'] ?? null,
            ] : null,

            'read'       => $notification->read_at !== null,
            'read_at'    => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
