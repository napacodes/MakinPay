@php
    use App\Enums\NotificationActionType;
    use Illuminate\Support\Facades\Storage;

    /*
     * The bell and its panel.
     *
     * Rendered on every admin page, and again by NotificationController@recent
     * whenever the poller sees the unread feed change — so everything the
     * panel needs, including the three routes the script calls, travels in
     * this markup rather than being bound once at page load.
     *
     * The feed holds the latest few notifications whatever their state. The
     * count on the bell is the unread ones; a read row stays in the list,
     * quieter, so "Unread" is a filter with something to filter.
     */
    $notifications = collect($notifications ?? []);

    $isUnread = static fn (object $notification): bool => ($notification->read_at ?? null) === null;

    $unreadCount  = $notifications->filter($isUnread)->count();
    $hasUnread    = $unreadCount > 0;
    $countDisplay = $unreadCount > 99 ? '99+' : (string) $unreadCount;

    /*
     * How many of these are asking for something rather than reporting that
     * something worked. Counted off the collection already loaded for the
     * list below, so the pill costs no query of its own.
     */
    $needsActionCount = $notifications->filter(function (object $notification): bool {
        $type = (string) (((array) ($notification->data ?? []))['action_type'] ?? '');

        return $type === '' || NotificationActionType::getClass($type) !== 'success';
    })->count();

    /*
     * The tile's glyph: the notification's own icon when it names a file the
     * icon set has, otherwise one that says what the tone says.
     */
    $iconExists = static fn (string $name): bool => $name !== '' && Storage::disk('svg_assets')->exists($name.'.svg');

    $toneIcon = static fn (string $tone): string => match ($tone) {
        'success' => 'check',
        'danger'  => 'error',
        'warning' => 'warning-2',
        'info'    => 'info',
        default   => 'bell',
    };

    $pill = static fn (int $count): string => $count > 99 ? '99+' : (string) $count;
@endphp
<a class="nav-link admin-notification-trigger {{ $hasUnread ? 'has-unread' : '' }}"
   id="adminNotificationTrigger"
   data-coreui-toggle="dropdown"
   data-coreui-auto-close="outside"
   href="#"
   role="button"
   aria-haspopup="true"
   aria-expanded="false"
   aria-label="{{ trans_choice('{0} No new notifications|{1} :count new notification|[2,*] :count new notifications', $unreadCount, ['count' => $unreadCount]) }}">
    <span class="admin-notification-trigger__icon">
        <x-icon name="cil-bell" class="icon icon-lg"/>
    </span>
    @if($hasUnread)
        <span class="admin-notification-counter" aria-hidden="true">{{ $countDisplay }}</span>
    @endif
    <span class="visually-hidden" data-notif-announce>{{ trans_choice('{0} No new notifications|{1} :count new notification|[2,*] :count new notifications', $unreadCount, ['count' => $unreadCount]) }}</span>
</a>

<div class="dropdown-menu dropdown-menu-end p-0 notification-dropdown notification-dropdown--pro"
     aria-labelledby="adminNotificationTrigger"
     role="menu"
     data-coreui-display="static"
     data-notif-panel
     data-notif-recent-url="{{ route('admin.notifications.recent') }}"
     data-notif-mark-all-url="{{ route('admin.notifications.markAllAsRead') }}"
     data-notif-read-url="{{ route('admin.notifications.markAsRead', ':id') }}"
     data-notif-zero-label="{{ __('No new notifications') }}">
    <div class="notification-dropdown__head">
        <span class="notification-dropdown__title">{{ __('Notifications') }}</span>
        @if($hasUnread)
            <button type="button" class="notification-dropdown__mark-all" data-notif-mark-all>{{ __('Mark all read') }}</button>
        @endif
    </div>

    @unless($notifications->isEmpty())
        {{--
            Real controls. They filter the list already on screen — no request,
            no endpoint, nothing refetched: every row the panel can show is
            already in the markup, and each carries the two facts these read.
        --}}
        @php
            /*
             * The panel opens on Unread — what an operator came to the bell
             * for is what they have not seen yet.
             *
             * It has to be decided here rather than in JavaScript: the script
             * never applies a filter on load, and the poller repaints this
             * container straight from the server, so a tab marked active in
             * markup alone would sit highlighted above an unfiltered list.
             *
             * The one exception is having nothing unread to show. "Always
             * Unread" would then greet a clear inbox with "Nothing here under
             * this filter" while ten action-needed rows sat one tab away.
             *
             * Asked of the ten rows this panel actually renders, not of
             * $hasUnread, which counts the whole feed: with the only unread
             * notification sitting at position eleven the tab would have been
             * right about the account and wrong about the panel, and opened
             * on an empty list.
             */
            $visibleNotifications = $notifications->take(10);
            $defaultFilter = $visibleNotifications->contains($isUnread) ? 'unread' : 'all';

            $matchesDefault = static fn (bool $unread, bool $needsAction): bool => match ($defaultFilter) {
                'unread' => $unread,
                'action' => $needsAction,
                default  => true,
            };
        @endphp

        <div class="notification-dropdown__filters" role="group" aria-label="{{ __('Filter notifications') }}">
            <button type="button" @class(['notification-dropdown__filter', 'is-active' => $defaultFilter === 'all'])
                    data-notif-filter="all" aria-pressed="{{ $defaultFilter === 'all' ? 'true' : 'false' }}">{{ __('All') }}</button>
            <button type="button" @class(['notification-dropdown__filter', 'is-active' => $defaultFilter === 'unread'])
                    data-notif-filter="unread" aria-pressed="{{ $defaultFilter === 'unread' ? 'true' : 'false' }}">
                {{ __('Unread') }}
                @if($hasUnread)
                    <span class="notification-dropdown__filter-count is-danger" data-notif-unread-count>{{ $pill($unreadCount) }}</span>
                @endif
            </button>
            <button type="button" class="notification-dropdown__filter"
                    data-notif-filter="action" aria-pressed="false">
                {{ __('Action needed') }}
                @if($needsActionCount > 0)
                    <span class="notification-dropdown__filter-count is-warn">{{ $pill($needsActionCount) }}</span>
                @endif
            </button>
        </div>
    @endunless

    @if($notifications->isEmpty())
        <x-admin-not-found
            class="notification-dropdown__empty"
            :title="__('All caught up!')"
            :message="__('You have no notifications right now.')"
            icon="fa-bell-slash"
        />
    @else
        <div class="notification-dropdown__list" data-scroll>
            @foreach($visibleNotifications as $notification)
                @php
                    $payload    = (array) ($notification->data ?? []);
                    $sender     = (array) ($payload['sender'] ?? []);
                    $senderName = trim((string) ($sender['name'] ?? ''));
                    $actionType = (string) ($payload['action_type'] ?? '');
                    $tone       = $actionType !== '' ? NotificationActionType::getClass($actionType) : 'primary';
                    $actionLabel = $actionType !== ''
                        ? __(ucfirst(str_replace('_', ' ', $actionType)))
                        : __('Update');
                    $unread = $isUnread($notification);

                    /*
                     * The two facts the filter row reads. Stamped here because
                     * this is where they are already known — deriving them in
                     * JavaScript would mean a second definition of "needs
                     * action", and the count on the pill comes from this one.
                     */
                    $needsAction = $tone !== 'success';

                    $icon = (string) ($payload['icon'] ?? '');
                    $iconName = $iconExists($icon) ? $icon : $toneIcon($tone);

                    /* "4 min ago · Deposits": who or what this came from. */
                    $module = $senderName !== '' && $senderName !== __('System Notification') ? $senderName : $actionLabel;
                @endphp
                <a @class([
                       'dropdown-item',
                       'notification-dropdown-item',
                       'notification-dropdown-item--pro',
                       'notification-dropdown-item--'.$tone,
                       'read-notification',
                       'is-unread' => $unread,
                   ])
                   href="{{ safeUrl($payload['action_link'] ?? null, '#') }}"
                   role="menuitem"
                   data-notif-action="{{ $needsAction ? '1' : '0' }}"
                   data-notif-unread="{{ $unread ? '1' : '0' }}"
                   @unless($matchesDefault($unread, $needsAction)) hidden @endunless
                   data-id="{{ $notification->id }}">
                    <span class="notification-dropdown-item__tile" aria-hidden="true">
                        <x-icon name="{{ $iconName }}" width="16" height="16"/>
                    </span>
                    <span class="notification-dropdown-item__main">
                        <span class="notification-dropdown-item__title">{{ $payload['title'] ?? __('Notification') }}</span>
                        <span class="notification-dropdown-item__message">{{ $payload['message'] ?? __('No additional details provided.') }}</span>
                        <span class="notification-dropdown-item__meta">
                            <span class="notification-dropdown-item__time-badge"
                                  title="{{ $notification->created_at->format('Y-m-d H:i') }}">{{ $notification->created_at->diffForHumans() }}</span>
                            <span class="notification-dropdown-item__module">{{ $module }}</span>
                        </span>
                    </span>
                    <span class="notification-dropdown-item__dot" aria-hidden="true"></span>
                </a>
            @endforeach

            {{-- Shown only when a filter leaves the list with nothing in it. --}}
            <p class="notification-dropdown__filter-empty" data-notif-none hidden>
                {{ __('Nothing here under this filter.') }}
            </p>
        </div>
    @endif

    <div class="notification-dropdown__footer">
        <a href="{{ route('admin.notifications.index') }}" class="notification-dropdown__footer-link">{{ __('View all notifications') }}</a>
    </div>
</div>
