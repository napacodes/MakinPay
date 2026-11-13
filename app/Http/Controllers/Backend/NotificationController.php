<?php

namespace App\Http\Controllers\Backend;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\NotifyUsers;
use App\Mail\GenericNotification;
use App\Models\User;
use App\Services\BackgroundHealthService;
use App\Support\RealtimeConnection;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class NotificationController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index|recent|signature'       => 'notification-list',
            'notifyUsers|sendNotification' => 'custom-notify-users',
        ];
    }

    /**
     * Display paginated notifications.
     */
    public function index(Request $request): View
    {
        $filter = in_array($request->query('filter'), ['unread', 'read'], true)
            ? $request->query('filter')
            : 'all';

        $query = auth()->user()->notifications()->latest();

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->paginate(15)->withQueryString();

        return view('backend.notifications.index', compact('notifications', 'filter'));
    }

    /**
     * Return recent notifications for the dropdown.
     */
    public function recent(): string
    {
        $notifications = auth()->user()->recentNotificationFeed();

        return view('backend.layouts.partials._notifications', compact('notifications'))->render();
    }

    /**
     * Return the unread feed fingerprint so the poller can skip untouched feeds.
     */
    public function signature(): JsonResponse
    {
        return response()->json(auth()->user()->notificationSignature());
    }

    /**
     * The composer: audience, channel, message, delivery.
     *
     * The job only ever reaches `status = active` accounts, so the audience
     * cards say how many each choice really is rather than letting the admin
     * guess. Realtime is a fact of this installation (the Pusher plugin), the
     * timezone is the one the schedule is read in, and the earliest allowed
     * time is "now" in that zone — the same rule `sendNotification` enforces.
     */
    public function notifyUsers(): View
    {
        $active = User::query()
            ->where('status', UserStatus::ACTIVE)
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $audiences = collect([
            'all'                     => [__('All accounts'), __('accounts')],
            UserRole::USER->value     => [__('Users'), __('users')],
            UserRole::MERCHANT->value => [__('Merchants'), __('merchants')],
            UserRole::AGENT->value    => [__('Agents'), __('agents')],
        ])->map(static fn (array $words, string $value): array => [
            'value' => $value,
            'label' => $words[0],
            'noun'  => $words[1],
            'count' => $value === 'all' ? (int) $active->sum() : (int) ($active[$value] ?? 0),
        ])->values();

        $timezone = setting('site_timezone', config('app.timezone', 'UTC'));

        return view('backend.notifications.notify_users', [
            'audiences' => $audiences,
            // Named for the selector it feeds; true on Reverb as well.
            'pusherEnabled' => RealtimeConnection::canBroadcast(),
            'timezone'      => $timezone,
            'siteTitle'     => setting('site_title', config('app.name')),
            'minSchedule'   => now($timezone)->format('Y-m-d\TH:i'),
        ]);
    }

    public function sendNotification(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id'     => ['nullable'],
            'user_type'   => $request->filled('user_id') ? ['nullable'] : ['required'],
            'notify_type' => 'required|in:email,push',
            'title'       => $request->input('notify_type') === 'push'
                ? 'nullable|string|max:255'
                : 'required|string|max:255',
            'message'     => 'required|string',
            'schedule_at' => 'nullable|date|after_or_equal:now',
        ]);

        // Handle 'all' or convert string to array only for bulk user
        $userTypes = [];
        if (! $request->filled('user_id')) {
            $userTypes = $validated['user_type'] === 'all'
                ? UserRole::all()
                : (array) $validated['user_type'];

            $userTypes = collect($userTypes)
                ->map(fn ($value) => UserRole::tryFrom($value))
                ->filter()
                ->pluck('value')
                ->all();
        }

        // Apply timezone config and convert schedule time
        $timezone = setting('site_timezone', config('app.timezone', 'UTC'));

        $scheduleAt = isset($validated['schedule_at'])
            ? Carbon::parse($validated['schedule_at'], $timezone)->timezone($timezone)
            : now($timezone);

        $data = [
            'user_id'     => $validated['user_id'] ?? null,
            'user_types'  => $userTypes,
            'notify_type' => $validated['notify_type'],
            'title'       => $validated['title'] ?? null,
            'message'     => $validated['message'],
            'schedule_at' => $scheduleAt,
        ];

        $isScheduled = isset($validated['schedule_at']);

        if ($data['notify_type'] === 'email' && $data['user_id'] !== null && ! $isScheduled) {
            $this->emailOneUserNow((int) $data['user_id'], (string) $data['title'], $data['message']);

            return back();
        }

        dispatch(new NotifyUsers($data))->delay($scheduleAt);

        $this->flashQueuedOutcome($isScheduled ? $scheduleAt : null);

        return back();
    }

    /**
     * Send the email from the Notify User modal inside this request.
     *
     * This used to be queued like the bulk send, and the screen said
     * "Notification sent successfully" the moment the job row was written.
     * On an installation with no queue worker, or one whose worker still
     * held the SMTP settings from before the administrator corrected them,
     * that message was the only thing that ever happened. One message to one
     * address is cheap enough to send here, and the administrator then learns
     * what the mail server actually said.
     */
    private function emailOneUserNow(int $userId, string $title, string $message): void
    {
        $user = User::query()
            ->whereKey($userId)
            ->where('status', UserStatus::ACTIVE)
            ->first();

        if ($user === null) {
            notifyEvs('error', __('This user is inactive or no longer exists, so no email was sent.'));

            return;
        }

        try {
            Mail::to($user->email)->send(new GenericNotification($title, $message));
        } catch (Throwable $e) {
            Log::error('Admin email to a user could not be sent', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            notifyEvs('error', __('The mail server refused the message, so no email was sent. :reason Check Mail Settings and run the SMTP test.', [
                'reason' => Str::limit(trim($e->getMessage()), 160),
            ]));

            return;
        }

        notifyEvs('success', __('Email sent to :email.', ['email' => $user->email]));
    }

    /**
     * Say what actually happened to a queued send: it is waiting for the
     * worker, and if the worker is demonstrably not running, say that too
     * rather than let "sent" stand for a job nothing will ever pick up.
     */
    private function flashQueuedOutcome(?Carbon $scheduledFor): void
    {
        $queue = app(BackgroundHealthService::class)->queue();

        if ($queue['status'] === BackgroundHealthService::STATUS_STALLED) {
            notifyEvs('warning', __('Queued, but the queue worker has not run for :minutes minutes, so nothing will go out until it does. Check Background Tasks.', [
                'minutes' => $queue['oldest_minutes'],
            ]));

            return;
        }

        notifyEvs('success', $scheduledFor !== null
            ? __('Notification scheduled for :time.', ['time' => $scheduledFor->format('Y-m-d H:i')])
            : __('Notification queued and will be delivered by the queue worker.'));
    }

    /**
     * Mark all unread notifications as read.
     *
     * The header panel calls this over XHR and repaints itself from
     * `recent()`, so it is answered with JSON there; a plain request from the
     * notifications page still goes back where it came from.
     */
    public function markAllAsRead(Request $request): JsonResponse|RedirectResponse
    {
        // Use each() to iterate over the collection and mark each as read.
        auth()->user()->unreadNotifications->each->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back();
    }

    /**
     * Mark a single notification as read.
     *
     * @param string $id
     */
    public function markAsRead(Request $request, $id): JsonResponse|RedirectResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back();
    }
}
