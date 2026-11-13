<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Support\ApiResponse;
use App\Support\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's own notification feed.
 *
 * Every query starts at the notifications relation on the token's owner, so
 * an id belonging to somebody else finds nothing - marking a stranger's
 * notification read is a small harm, but it is a read of their feed, and the
 * id is a uuid somebody could hold.
 */
class NotificationController extends Controller
{
    private const MAX_PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            // One chip, or several joined by commas - the app's Money chip is four categories.
            'category' => ['sometimes', 'string', function (string $attribute, mixed $value, $fail): void {
                foreach (explode(',', (string) $value) as $one) {
                    if (! NotificationCategory::isValid($one)) {
                        $fail(__('Unknown category.'));
                    }
                }
            }],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $categories = isset($validated['category']) ? explode(',', $validated['category']) : [];

        $notifications = $request->user()
            ->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            // A chip filters on the server: by the category new rows carry,
            // by the template identifier older ones were written from, or
            // by the class for the notifications that have no template.
            ->when($categories !== [], fn ($query) => $query->where(function ($q) use ($categories): void {
                $q->whereIn('data->category', $categories);
                $identifiers = array_merge(...array_map(NotificationCategory::identifiersFor(...), $categories));
                $classes     = array_merge(...array_map(NotificationCategory::classesFor(...), $categories));
                if ($identifiers !== []) {
                    $q->orWhereIn('data->identifier', $identifiers);
                }
                if ($classes !== []) {
                    $q->orWhereIn('type', $classes);
                }
            }))
            ->latest()
            // Tie-broken by the uuid. created_at alone is not unique - a batch
            // of notifications written in one second all share it - and a
            // cursor over a non-unique order skips rows or repeats them.
            ->orderByDesc('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 20), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::paginated(NotificationResource::collection($notifications));
    }

    /**
     * The number the app paints on its bell.
     *
     * Its own endpoint because it is the one thing polled often, and counting
     * is far cheaper than fetching a page to count it.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::ok([
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->first();

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        // Already-read is success, not a conflict: a phone retrying after a
        // dropped response must not be told it did something wrong.
        $record->markAsRead();

        return ApiResponse::ok(new NotificationResource($record->fresh()));
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $marked = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::ok(['marked_read' => $marked]);
    }

    /**
     * Swiped away. Gone from the feed for good; deleting one that is already
     * gone is success for the same reason marking a read one read is.
     */
    public function destroy(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->first()?->delete();

        return ApiResponse::ok(['deleted' => true]);
    }
}
