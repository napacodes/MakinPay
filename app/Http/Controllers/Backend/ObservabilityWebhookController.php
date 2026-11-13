<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Observability\ErrorDeliveryLog;
use App\Models\Observability\ErrorGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acknowledge and resolve callbacks arriving from a notification.
 *
 * Slack interactivity, Discord interactions and Teams actions all end
 * up here, and all write exactly the same row a click inside the admin panel
 * would. That equality is the point: an incident timeline that shows only the
 * actions taken in the browser is a timeline of half the incident.
 *
 * These routes are public because the providers calling them are not logged
 * in, so they are protected by Laravel's signed URLs instead. The signature
 * covers the group id, which is why the callback payload carries an identifier
 * and nothing about the error itself.
 */
class ObservabilityWebhookController extends Controller
{
    /**
     * Stop the escalation ladder.
     *
     * Idempotent by construction: acknowledging an already-acknowledged group
     * changes nothing and still answers 200, because a provider that retries
     * its callback must not produce a second timeline entry.
     */
    public function acknowledge(Request $request, string $groupId): JsonResponse
    {
        $group = ErrorGroup::query()->where('group_id', $groupId)->first();

        if ($group === null) {
            return response()->json(['ok' => false], 404);
        }

        $alreadyAcknowledged = ErrorDeliveryLog::query()
            ->where('error_group_id', $group->id)
            ->whereNotNull('acknowledged_at')
            ->exists();

        if ($alreadyAcknowledged) {
            return response()->json(['ok' => true, 'already' => true]);
        }

        ErrorDeliveryLog::query()->create([
            'error_group_id'  => $group->id,
            'channel_key'     => $this->channelFrom($request),
            'status'          => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $this->actorFrom($request),
            'created_at'      => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Only the provider name is taken from the payload, and only from a short
     * allow-list. Everything else in a callback body is untrusted input.
     */
    private function channelFrom(Request $request): string
    {
        $channel = (string) $request->input('channel', '');

        return in_array($channel, ['slack', 'discord', 'teams'], true)
            ? $channel
            : 'unknown';
    }

    /**
     * A display name for the timeline, truncated and never trusted as an
     * identity — the signature is what authorises the action.
     */
    private function actorFrom(Request $request): string
    {
        $name = trim((string) $request->input('actor', ''));

        return $name === '' ? __('Someone via a notification') : mb_substr($name, 0, 60);
    }
}
