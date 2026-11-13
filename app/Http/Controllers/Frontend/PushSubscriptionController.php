<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\StorePushSubscriptionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Hand the browser the public half of the VAPID pair so it can subscribe.
     */
    public function showConfig(): JsonResponse
    {
        $publicKey = (string) config('webpush.vapid.public_key');

        return response()->json([
            'enabled'    => $publicKey !== '' && (string) config('webpush.vapid.private_key') !== '',
            'public_key' => $publicKey,
            'subscribed' => $this->currentUserHasSubscription(),
        ]);
    }

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? null,
        );

        return response()->json(['subscribed' => true], 201);
    }

    /**
     * The browser can revoke a subscription without telling us, so deleting an
     * endpoint we no longer hold is a success, not a 404.
     */
    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');

        if ($endpoint !== '') {
            $request->user()->deletePushSubscription($endpoint);
        }

        return response()->json(['subscribed' => $this->currentUserHasSubscription()]);
    }

    private function currentUserHasSubscription(): bool
    {
        return (bool) auth()->user()?->pushSubscriptions()->exists();
    }
}
