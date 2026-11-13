@php
    use App\Enums\NotificationActionType;
    use App\Support\NotificationTuneLibrary;
    use Carbon\CarbonInterface;

    $preferenceState = $preferenceState ?? auth()->user()->notificationPreferenceState();
    $tuneOptions = $tuneOptions ?? NotificationTuneLibrary::tunes();
    $noteOptions = $noteOptions ?? NotificationTuneLibrary::noteOptions();
    $defaultTune = $defaultTune ?? NotificationTuneLibrary::resolve(NotificationTuneLibrary::defaultKey());
    $selectedTuneKey = $preferenceState['tune_key'] ?? 'default';
    $customTune = $preferenceState['custom_tune'] ?? NotificationTuneLibrary::defaultCustomTune();
    $customNotes = $customTune['notes'] ?? NotificationTuneLibrary::defaultCustomTune()['notes'];

    $unreadTotal = auth()->user()->unreadNotifications()->count();
    $activeNotificationTab = $errors->any() || session('notification_preferences_active') || request('tab') === 'preferences'
        ? 'preferences'
        : 'notifications';
@endphp
@extends('frontend.user.setting.index')
@section('title', __('Notifications'))

@section('user_setting_content')
<div class="notification-settings-page">
    <nav class="notification-tabs" aria-label="{{ __('Notification sections') }}">
        <div class="nav nav-pills notification-tabs__nav" id="notification-settings-tabs" role="tablist">
            <button
                class="nav-link notification-tabs__link {{ $activeNotificationTab === 'notifications' ? 'active' : '' }}"
                id="notification-list-tab"
                data-bs-toggle="tab"
                data-bs-target="#notification-list-panel"
                type="button"
                role="tab"
                aria-controls="notification-list-panel"
                aria-selected="{{ $activeNotificationTab === 'notifications' ? 'true' : 'false' }}"
            >
                <span class="notification-tabs__icon" aria-hidden="true">
                    <i class="fa-solid fa-bell"></i>
                </span>
                <span class="notification-tabs__copy">
                    <span class="notification-tabs__label">{{ __('Notifications') }}</span>
                    <span class="notification-tabs__hint">{{ __('Inbox and account activity') }}</span>
                </span>
                @if($unreadTotal > 0)
                    <span
                        class="notification-tabs__count is-unread"
                        aria-label="{{ trans_choice('{1} :count unread notification|[2,*] :count unread notifications', $unreadTotal, ['count' => $unreadTotal]) }}"
                    >
                        {{ $unreadTotal }}
                    </span>
                @endif
            </button>

            <button
                class="nav-link notification-tabs__link {{ $activeNotificationTab === 'preferences' ? 'active' : '' }}"
                id="notification-preferences-tab"
                data-bs-toggle="tab"
                data-bs-target="#notification-preferences-panel"
                type="button"
                role="tab"
                aria-controls="notification-preferences-panel"
                aria-selected="{{ $activeNotificationTab === 'preferences' ? 'true' : 'false' }}"
            >
                <span class="notification-tabs__icon" aria-hidden="true">
                    <i class="fa-solid fa-sliders"></i>
                </span>
                <span class="notification-tabs__copy">
                    <span class="notification-tabs__label">{{ __('Preferences') }}</span>
                    <span class="notification-tabs__hint">{{ __('Alerts, sound, and tune') }}</span>
                </span>
                <span class="notification-tabs__status {{ $preferenceState['notifications_enabled'] ? 'is-active' : '' }}">
                    <i class="fa-solid fa-circle" aria-hidden="true"></i>
                    {{ $preferenceState['notifications_enabled'] ? __('On') : __('Off') }}
                </span>
            </button>
        </div>
    </nav>

    <div class="tab-content notification-tabs__content" id="notification-settings-tab-content">
        <section
            class="tab-pane fade {{ $activeNotificationTab === 'preferences' ? 'show active' : '' }}"
            id="notification-preferences-panel"
            role="tabpanel"
            aria-labelledby="notification-preferences-tab"
            tabindex="0"
        >
    <div class="card notification-card notification-preferences-card">
        <div class="card-body notification-preferences-card__body">
            <form id="settings-notification-preferences-form" action="{{ route('user.notifications.preferences.update') }}" method="POST" class="notification-preferences-form">
                @csrf
                @method('PUT')

                <div class="notification-preferences-grid">
                    <div class="notification-toggle-stack">
                        <input type="hidden" name="notifications_enabled" value="0">
                        <div class="notification-toggle-card">
                            <input
                                class="notification-toggle-card__input"
                                type="checkbox"
                                role="switch"
                                name="notifications_enabled"
                                id="notifications_enabled"
                                value="1"
                                @checked($preferenceState['notifications_enabled'])
                            >
                            <span class="notification-toggle-switch" aria-hidden="true">
                                <span></span>
                            </span>
                            <label class="notification-toggle-card__body" for="notifications_enabled">
                                <span class="notification-toggle-card__title">{{ __('Enable in-app notifications') }}</span>
                                <span class="notification-toggle-card__text">{{ __('Show deposits, rewards, and security alerts inside your account.') }}</span>
                            </label>
                        </div>

                        <input type="hidden" name="tune_enabled" value="0">
                        <div class="notification-toggle-card">
                            <input
                                class="notification-toggle-card__input"
                                type="checkbox"
                                role="switch"
                                name="tune_enabled"
                                id="tune_enabled"
                                value="1"
                                @checked($preferenceState['tune_enabled'])
                            >
                            <span class="notification-toggle-switch" aria-hidden="true">
                                <span></span>
                            </span>
                            <label class="notification-toggle-card__body" for="tune_enabled">
                                <span class="notification-toggle-card__title">{{ __('Play notification tune') }}</span>
                                <span class="notification-toggle-card__text">{{ __('Play a short sound when important activity arrives.') }}</span>
                            </label>
                        </div>

                        {{-- Web Push opt-in. Browsers only grant permission from a real
                             user gesture, so this stays a button rather than a form field. --}}
                        <div class="notification-toggle-card notification-device-card" data-dk-webpush-card hidden>
                            <span class="notification-device-card__icon" aria-hidden="true">
                                <i class="fas fa-mobile-screen-button"></i>
                            </span>
                            <div class="notification-toggle-card__body">
                                <span class="notification-toggle-card__title">{{ __('Notifications on this device') }}</span>
                                <span class="notification-toggle-card__text" data-dk-webpush-status>{{ __('Get alerts on your phone even when the app is closed.') }}</span>
                            </div>
                            <button type="button"
                                    class="btn-base btn-light-primary btn-sm notification-device-card__action"
                                    data-dk-webpush-toggle
                                    data-enable-label="{{ __('Turn on') }}"
                                    data-disable-label="{{ __('Turn off') }}"
                                    data-working-label="{{ __('Working...') }}">
                                {{ __('Turn on') }}
                            </button>
                        </div>
                    </div>

                    <div class="notification-tune-card">
                        <label for="tune_key" class="form-label">{{ __('Tune') }}</label>
                        <div class="notification-tune-card__control">
                            <select id="tune_key" name="tune_key" class="form-select" data-user-tune-select>
                                <option value="default" @selected($selectedTuneKey === 'default')>
                                    {{ __('Platform default (:name)', ['name' => __($defaultTune['label'])]) }}
                                </option>
                                @foreach($tuneOptions as $key => $tune)
                                    <option value="{{ $key }}" @selected($selectedTuneKey === $key)>
                                        {{ __($tune['label']) }}
                                    </option>
                                @endforeach
                                <option value="{{ NotificationTuneLibrary::CUSTOM_KEY }}" @selected($selectedTuneKey === NotificationTuneLibrary::CUSTOM_KEY)>
                                    {{ __('My custom tune') }}
                                </option>
                            </select>
                            <button
                                class="btn btn-outline-primary notification-preview-btn"
                                type="button"
                                data-user-tune-preview
                                aria-label="{{ __('Preview selected notification tune') }}"
                            >
                                <i class="fa-solid fa-play" aria-hidden="true"></i>
                                <span class="notification-preview-btn__label">{{ __('Preview') }}</span>
                            </button>
                        </div>
                        @error('tune_key')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="notification-custom-tune" data-user-custom-tune-panel @class(['d-none' => $selectedTuneKey !== NotificationTuneLibrary::CUSTOM_KEY])>
                    <div class="notification-custom-tune__head">
                        <div>
                            <div class="notification-custom-tune__title">{{ __('Custom Tune Builder') }}</div>
                            <div class="notification-custom-tune__text">{{ __('Pick four notes and a speed to make your own notification sound.') }}</div>
                        </div>
                        <span class="notification-custom-tune__badge">{{ __('Personal') }}</span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="custom_tune_label">{{ __('Tune Name') }}</label>
                            <input
                                type="text"
                                name="custom_tune[label]"
                                id="custom_tune_label"
                                class="form-control"
                                value="{{ old('custom_tune.label', $customTune['label'] ?? __('My Tune')) }}"
                                maxlength="40"
                                data-custom-tune-label
                            >
                            @error('custom_tune.label')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        @foreach(['note_1', 'note_2', 'note_3', 'note_4'] as $noteField)
                            <div class="col-6 col-md-2">
                                <label class="form-label" for="custom_tune_{{ $noteField }}">{{ __('Note :number', ['number' => (int) str_replace('note_', '', $noteField)]) }}</label>
                                <select
                                    name="custom_tune[{{ $noteField }}]"
                                    id="custom_tune_{{ $noteField }}"
                                    class="form-select"
                                    data-custom-tune-note
                                >
                                    @foreach($noteOptions as $noteKey => $note)
                                        <option
                                            value="{{ $noteKey }}"
                                            data-frequency="{{ $note['frequency'] }}"
                                            @selected(old("custom_tune.$noteField", $customNotes[$noteField] ?? null) === $noteKey)
                                        >
                                            {{ __($note['label']) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error("custom_tune.$noteField")
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach

                        <div class="col-md-4">
                            <label class="form-label" for="custom_tune_speed">{{ __('Note Length') }}</label>
                            <div class="input-group">
                                <input
                                    type="number"
                                    min="80"
                                    max="360"
                                    name="custom_tune[speed]"
                                    id="custom_tune_speed"
                                    class="form-control"
                                    value="{{ old('custom_tune.speed', $customNotes['speed'] ?? 150) }}"
                                    data-custom-tune-speed
                                >
                                <span class="input-group-text">{{ __('ms') }}</span>
                            </div>
                            @error('custom_tune.speed')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <footer class="notification-preferences-actions">
                    <p class="notification-preferences-actions__hint">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        {{ __('Preferences are saved to this account and can be changed anytime.') }}
                    </p>
                    <button type="submit" class="btn btn-primary notification-preferences-actions__submit">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        <span>{{ __('Save Preferences') }}</span>
                    </button>
                </footer>
            </form>
        </div>
    </div>
        </section>

        <section
            class="tab-pane fade {{ $activeNotificationTab === 'notifications' ? 'show active' : '' }}"
            id="notification-list-panel"
            role="tabpanel"
            aria-labelledby="notification-list-tab"
            tabindex="0"
        >
    <div class="card notification-card notification-feed-card">
        @if($unreadTotal > 0)
            <div class="notification-feed-toolbar">
                <a href="{{ route('user.notifications.read-all') }}" class="btn btn-sm notification-header__action">
                    <i class="fa-solid fa-check-double" aria-hidden="true"></i>
                    <span>{{ __('Mark all read') }}</span>
                </a>
            </div>
        @endif
        <div class="card-body p-0">
            @if($notifications->isNotEmpty())
                @php
                    $feedGroup = null;
                @endphp
                <ul class="notification-feed-list list-unstyled">
                    @foreach($notifications as $notification)
                        @php
                            // Defensive normalisation: legacy / framework / template-less
                            // notifications can persist a `data` payload that is empty or
                            // missing keys (e.g. a notification whose template has no push
                            // channel is stored as `data = []`). Accessing `$data['icon']`
                            // directly then throws "Undefined array key" and 500s the whole
                            // feed. Coalesce every key to a safe default so any payload
                            // shape renders cleanly.
                            $data   = is_array($notification->data) ? $notification->data : [];
                            $isRead = ! is_null($notification->read_at);

                            $notificationIcon    = $data['icon'] ?? 'bell';
                            $notificationAction  = (string) ($data['action_type'] ?? '');
                            $notificationClass   = NotificationActionType::getClass($notificationAction);
                            $notificationStatus  = NotificationActionType::tryFrom($notificationAction)?->label();
                            $notificationTitle   = $data['title'] ?? __('No message available');
                            $notificationMessage = $data['message'] ?? '';
                            $normaliseCopy = static fn (mixed $value): string => mb_strtolower(trim(strip_tags((string) $value)));
                            $showNotificationMessage = filled($notificationMessage)
                                && $normaliseCopy($notificationMessage) !== $normaliseCopy($notificationTitle);

                            $createdAt = $notification->created_at;
                            $dayGroup  = match (true) {
                                ! $createdAt => __('Earlier'),
                                $createdAt->isToday() => __('Today'),
                                $createdAt->isYesterday() => __('Yesterday'),
                                default => $createdAt->translatedFormat('d M, Y'),
                            };
                        @endphp

                        @if($dayGroup !== $feedGroup)
                            @php
                                $feedGroup = $dayGroup;
                            @endphp
                            <li class="notification-feed-divider">
                                <span class="notification-feed-divider__label">{{ $dayGroup }}</span>
                            </li>
                        @endif

                        <li class="notification-feed-item {{ $isRead ? 'is-read' : 'is-unread' }}"
                            data-tone="{{ $notificationClass }}">
                            <span class="notification-feed-item__icon" aria-hidden="true">
                                <x-icon name="{{ $notificationIcon }}" height="20" width="20"/>
                            </span>

                            <div class="notification-feed-item__body">
                                <div class="notification-feed-item__headline">
                                    @unless($isRead)
                                        <span class="notification-feed-item__dot" aria-hidden="true"></span>
                                        <span class="visually-hidden">{{ __('New') }}</span>
                                    @endunless
                                    <span class="notification-feed-item__title">{{ $notificationTitle }}</span>
                                    @if($notificationStatus)
                                        <span class="notification-feed-item__status">{{ __($notificationStatus) }}</span>
                                    @endif
                                    <time class="notification-feed-item__time"
                                          datetime="{{ $createdAt?->toIso8601String() }}"
                                          title="{{ $createdAt?->format('d M, Y h:i A') }}">
                                        {{ $createdAt?->diffForHumans(['short' => true, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]) }}
                                    </time>
                                </div>

                                @if($showNotificationMessage)
                                    <p class="notification-feed-item__message">{{ $notificationMessage }}</p>
                                @endif
                            </div>

                            @unless($isRead)
                                <a href="{{ route('user.notifications.markAsRead', $notification->id) }}"
                                   class="notification-feed-item__action"
                                   title="{{ __('Mark as read') }}"
                                   aria-label="{{ __('Mark :title as read', ['title' => $notificationTitle]) }}">
                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                    <span class="notification-feed-item__action-label">{{ __('Mark read') }}</span>
                                </a>
                            @endunless
                        </li>
                    @endforeach
                </ul>

                @if($notifications->hasPages())
                    <footer class="notification-feed-footer">
                        {{ $notifications->links() }}
                    </footer>
                @endif
            @else
                <x-user-not-found
                    :title="__('No notifications found')"
                    :message="__('Updates, alerts, and account activity will appear here when available.')"
                    icon="fa-bell"
                    class="m-3"
                />
            @endif
        </div>
    </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        (function () {
            'use strict';

            const $select = $('[data-user-tune-select]');
            const $customPanel = $('[data-user-custom-tune-panel]');
            const tabsElement = document.querySelector('.notification-tabs');

            const elementDocumentTop = function (element) {
                let top = 0;

                while (element) {
                    top += element.offsetTop;
                    element = element.offsetParent;
                }

                return top;
            };

            const notificationTabsTop = tabsElement ? elementDocumentTop(tabsElement) : 0;

            const toggleCustomPanel = function () {
                $customPanel.toggleClass('d-none', $select.val() !== '{{ NotificationTuneLibrary::CUSTOM_KEY }}');
            };

            const buildCustomTune = function () {
                const speed = Math.max(80, Math.min(360, parseInt($('[data-custom-tune-speed]').val(), 10) || 150));
                const steps = [];

                $('[data-custom-tune-note]').each(function () {
                    const frequency = Number($(this).find(':selected').data('frequency') || 0);
                    steps.push({
                        frequency: frequency,
                        duration: Number((speed / 1000).toFixed(3)),
                        type: 'sine',
                        volume: 0.16,
                        gap: 0.035
                    });
                });

                return {
                    key: '{{ NotificationTuneLibrary::CUSTOM_KEY }}',
                    label: $('[data-custom-tune-label]').val() || @json(__('My Tune')),
                    custom: true,
                    steps: steps
                };
            };

            const previewSelected = function () {
                if (!window.DigiKashNotificationTune) {
                    return;
                }

                if ($select.val() === '{{ NotificationTuneLibrary::CUSTOM_KEY }}') {
                    window.DigiKashNotificationTune.preview(buildCustomTune());

                    return;
                }

                const tune = $select.val() === 'default'
                    ? window.DigiKashNotificationTune.getDefaultTune()
                    : window.DigiKashNotificationTune.getTune($select.val());

                window.DigiKashNotificationTune.preview(tune);
            };

            $select.on('change', toggleCustomPanel);
            $(document).on('click', '[data-user-tune-preview]', previewSelected);

            if (tabsElement) {
                tabsElement.addEventListener('shown.bs.tab', function () {
                    if (window.innerWidth >= 992) {
                        return;
                    }

                    const headerHeight = parseFloat(getComputedStyle(document.body).getPropertyValue('--dk-mobile-header-height')) || 54;

                    window.scrollTo({
                        top: Math.max(0, notificationTabsTop - headerHeight),
                        behavior: 'auto'
                    });
                });
            }

            toggleCustomPanel();
        })();
    </script>
@endpush
